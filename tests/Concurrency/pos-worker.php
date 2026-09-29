<?php

/*
 * One step of a PosConcurrencyTest scenario, run in its own PHP process with
 * its own MySQL connection, so requests genuinely overlap in the database.
 *
 *     php tests/Concurrency/pos-worker.php <job.json>
 *
 * A "purchase" goes through the real HTTP kernel (token auth, validation,
 * controller, service); "block" and "set_limits" call FuelCardService as a
 * manager's screen would. It prints one line "RESULT:{json}".
 */

use App\Enums\CardStatus;
use App\Models\FuelCard;
use App\Models\User;
use App\Services\FuelCardService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$database = (string) getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! str_starts_with($database, 'fleetfuel_test')) {
    fwrite(STDERR, "Refusing to run outside the isolated test database (got \"{$database}\").\n");
    exit(2);
}

/** @var array{action: string, token?: string, payload?: array<string, mixed>, card_id?: int, actor_id?: int, limit_l?: ?string, limit_usd?: ?string} $job */
$job = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

$result = $job['action'] === 'purchase' ? purchase($app, $job) : editCard($app, $job);

echo 'RESULT:'.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;

/**
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function purchase(Application $app, array $job): array
{
    $kernel = $app->make(HttpKernel::class);
    $request = Request::create('/api/v1/transactions', 'POST', server: [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'],
    ], content: json_encode($job['payload'], JSON_THROW_ON_ERROR));

    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getContent(), true),
        'replayed' => $response->headers->get('Idempotency-Replayed'),
    ];
}

/**
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function editCard(Application $app, array $job): array
{
    $app->make(ConsoleKernel::class)->bootstrap();

    $cards = $app->make(FuelCardService::class);
    $card = FuelCard::query()->findOrFail($job['card_id']);
    $actor = User::query()->findOrFail($job['actor_id']);

    $card = $job['action'] === 'block'
        ? $cards->changeStatus($card, CardStatus::Blocked, $actor)
        : $cards->updateLimits($card, $job['limit_l'] ?? null, $job['limit_usd'] ?? null, $actor);

    return ['status' => 'done', 'card_status' => $card->status->value, 'limit_l' => $card->monthly_limit_l];
}
