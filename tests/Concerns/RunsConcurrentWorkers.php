<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Worker processes for concurrency tests (docs/07-TEST-PLAN.md,
 * "Concurrency test design"). Each worker is a separate PHP process with its
 * own MySQL connection, given a JSON job file; it prints one line
 * "RESULT:{json}". The test holds the contested lock, starts the workers,
 * waits until MySQL shows them blocked on it, then releases it so they
 * compete at once. Every wait is bounded, so a stuck worker fails the test
 * instead of hanging CI.
 */
trait RunsConcurrentWorkers
{
    /** Seconds to wait for workers to line up before failing. */
    private const WAIT_SECONDS = 20;

    /** @var list<Process> */
    private array $workers = [];

    private string $jobDirectory;

    protected function prepareWorkers(): void
    {
        $this->jobDirectory = storage_path('framework/testing/workers-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->jobDirectory);
    }

    /** Stop leftover workers, end any transaction the test left open, remove job files. */
    protected function stopWorkers(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        File::deleteDirectory($this->jobDirectory);
    }

    /**
     * @param  string  $script  Path relative to the project root.
     * @param  array<string, mixed>  $job
     * @param  array<string, string>  $environment
     */
    protected function startWorkerProcess(string $script, array $job, array $environment): Process
    {
        $file = $this->jobDirectory.'/job-'.count($this->workers).'.json';
        file_put_contents($file, json_encode($job, JSON_THROW_ON_ERROR));

        $worker = new Process([PHP_BINARY, base_path($script), $file], base_path(), $environment, null, 60);
        $worker->start();

        return $this->workers[] = $worker;
    }

    /**
     * Wait until MySQL shows $count other connections running a statement
     * that contains both fragments, i.e. blocked behind a lock we hold.
     */
    protected function waitUntilWaiting(string $fragment, string $alsoContaining, int $count): void
    {
        $ownConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $deadline = microtime(true) + self::WAIT_SECONDS;
        $waiting = 0;

        while (microtime(true) < $deadline) {
            $waiting = collect(DB::select('SHOW FULL PROCESSLIST'))
                ->filter(fn (object $row): bool => (int) $row->Id !== $ownConnection
                    // Laravel uses server-side prepared statements, listed as "Execute".
                    && in_array($row->Command, ['Query', 'Execute'], true)
                    && is_string($row->Info)
                    && str_contains(strtolower($row->Info), strtolower($fragment))
                    && str_contains(strtolower($row->Info), strtolower($alsoContaining)))
                ->count();

            if ($waiting >= $count) {
                return;
            }

            foreach ($this->workers as $worker) {
                if (! $worker->isRunning()) {
                    $this->fail("A worker finished before it reached the lock:\n".$worker->getOutput().$worker->getErrorOutput());
                }
            }

            usleep(20_000);
        }

        $this->fail("Expected {$count} worker(s) waiting on \"{$fragment}\"; saw {$waiting} after ".self::WAIT_SECONDS.' s.');
    }

    /**
     * Wait until $count other connections have been running one statement
     * for at least a second: while this test holds its locks, they are
     * blocked behind them.
     */
    protected function waitUntilBlockedFor(int $count): void
    {
        $ownConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (microtime(true) < $deadline) {
            $blocked = collect(DB::select('SHOW FULL PROCESSLIST'))
                ->filter(fn (object $row): bool => (int) $row->Id !== $ownConnection
                    && in_array($row->Command, ['Query', 'Execute'], true)
                    && is_string($row->Info)
                    && (int) $row->Time >= 1)
                ->count();

            if ($blocked >= $count) {
                return;
            }

            usleep(100_000);
        }

        $this->fail("Expected {$count} blocked worker statement(s) within ".self::WAIT_SECONDS.' s.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function workerResult(Process $worker): array
    {
        $worker->wait();
        $line = collect(explode("\n", $worker->getOutput()))->first(fn (string $line): bool => str_starts_with($line, 'RESULT:'));

        if (! $worker->isSuccessful() || ! is_string($line)) {
            $this->fail("Worker failed (exit {$worker->getExitCode()}):\n".$worker->getOutput().$worker->getErrorOutput());
        }

        return json_decode(substr($line, 7), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function workerResults(): array
    {
        return array_map(fn (Process $worker): array => $this->workerResult($worker), $this->workers);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    protected function statuses(array $results): array
    {
        $statuses = array_map(fn (array $result): int => (int) $result['status'], $results);
        sort($statuses);

        return $statuses;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    protected function withStatus(array $results, int $status): array
    {
        return collect($results)->firstOrFail(fn (array $result): bool => $result['status'] === $status);
    }
}
