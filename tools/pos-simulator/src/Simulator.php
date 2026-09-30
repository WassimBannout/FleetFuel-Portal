<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

/**
 * Command-line entry point: pick the scenarios, authenticate, run them and
 * turn the outcome into an exit code (0 all passed, 1 unexpected outcome,
 * 2 usage or configuration error).
 */
final class Simulator
{
    public const EXIT_OK = 0;

    public const EXIT_FAILED = 1;

    public const EXIT_USAGE = 2;

    /**
     * @param  resource  $out
     * @param  resource  $err
     */
    public function __construct(private $out, private $err) {}

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     */
    public function main(array $argv, array $env): int
    {
        $name = $argv[1] ?? 'all';

        if (in_array($name, ['-h', '--help', 'help'], true)) {
            fwrite($this->out, self::usage());

            return self::EXIT_OK;
        }

        if (count($argv) > 2 || ! in_array($name, [...Scenarios::NAMES, 'all'], true)) {
            fwrite($this->err, "Unknown scenario \"{$name}\".\n\n".self::usage());

            return self::EXIT_USAGE;
        }

        try {
            $config = Config::fromEnvironment($env);
        } catch (ConfigurationError $e) {
            fwrite($this->err, $e->getMessage()."\n\n".self::usage());

            return self::EXIT_USAGE;
        }

        $report = new Reporter($this->out);
        $api = ApiClient::for($config);
        $issuedToken = false;
        $status = self::EXIT_OK;

        $report->line('FleetFuel POS simulator (fictional demo data)');
        $report->line("API: {$config->baseUrl}");

        try {
            if ($config->token !== null) {
                $api->useToken($config->token);
                $report->line('Token: from POS_TOKEN');
            } else {
                $api->useToken($this->issueToken($api, $config));
                $issuedToken = true;
                $report->line("Token: issued for {$config->email}; it is revoked at the end");
            }
            $report->line();

            $scenarios = new Scenarios($api, $config, $report);
            $names = $name === 'all' ? Scenarios::NAMES : [$name];
            foreach ($names as $scenario) {
                $scenarios->run($scenario);
            }

            $report->line();
            $report->line(sprintf('Result: %d scenario%s passed (%d checks).', count($names), count($names) === 1 ? '' : 's', $report->checks()));
        } catch (UnexpectedOutcome $e) {
            $report->fail($e->getMessage());
            $report->line();
            $report->line('Result: FAILED. Stopped at the first unexpected outcome.');
            $status = self::EXIT_FAILED;
        } finally {
            if ($issuedToken && ! $this->revokeToken($api, $report)) {
                $status = self::EXIT_FAILED;
            }
        }

        return $status;
    }

    private function issueToken(ApiClient $api, Config $config): string
    {
        $response = $api->post('/auth/token', [
            'email' => $config->email,
            'password' => $config->password,
            'device_name' => 'pos-simulator',
        ]);

        $token = $response->status === 201 ? ($response->data()['token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new UnexpectedOutcome("The token request was refused: {$response->describe()}.");
        }

        return $token;
    }

    private function revokeToken(ApiClient $api, Reporter $report): bool
    {
        try {
            $response = $api->delete('/auth/token');
        } catch (UnexpectedOutcome $e) {
            $report->fail('Could not revoke the simulator token: '.$e->getMessage());

            return false;
        }

        if ($response->status !== 204) {
            $report->fail("Could not revoke the simulator token: {$response->describe()}.");

            return false;
        }

        $report->line('Token revoked.');

        return true;
    }

    public static function usage(): string
    {
        return <<<'TXT'
            Usage: pos-simulator [success|replay|conflict|blocked|quota|all]   (default: all)

            Environment (never read from a file):
              POS_BASE_URL      API base URL (default http://localhost:8080/api/v1)
              POS_TOKEN         A station operator's API token, or instead:
              POS_EMAIL         a station operator's email and
              POS_PASSWORD      password; the simulator then issues a token and revokes it at the end
              POS_CARD          active card for success/replay/conflict (default FF-ATLAS-001)
              POS_BLOCKED_CARD  blocked card (default FF-ATLAS-BLOCKED)
              POS_TINY_CARD     active card with a small liter limit (default FF-ATLAS-TINY)
              POS_PRODUCT       product code (default DIESEL)
              POS_LITERS        liters for new purchases (default 20.00)
              POS_TIMEOUT       HTTP timeout in seconds (default 10)

            Exit codes: 0 every check passed, 1 an unexpected outcome, 2 usage or configuration error.

            TXT;
    }
}
