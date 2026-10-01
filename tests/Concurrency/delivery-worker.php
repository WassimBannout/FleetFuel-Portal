<?php

/*
 * One delivery status change for DeliveryConcurrencyTest, run in its own PHP
 * process with its own MySQL connection, so requests genuinely overlap in
 * the database. It goes through the real HTTP kernel: token auth, tenant
 * binding, validation, controller and DeliveryOrderService.
 *
 *     php tests/Concurrency/delivery-worker.php <job.json>
 *
 * It prints one line "RESULT:{json}" with the HTTP status and body.
 */

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$database = (string) getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! str_starts_with($database, 'fleetfuel_test')) {
    fwrite(STDERR, "Refusing to run outside the isolated test database (got \"{$database}\").\n");
    exit(2);
}

/** @var array{token: string, order_id: int, body: array<string, mixed>} $job */
$job = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(HttpKernel::class);
$request = Request::create("/api/v1/delivery-orders/{$job['order_id']}/status", 'PATCH', server: [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'],
], content: json_encode($job['body'], JSON_THROW_ON_ERROR));

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo 'RESULT:'.json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
], JSON_THROW_ON_ERROR).PHP_EOL;
