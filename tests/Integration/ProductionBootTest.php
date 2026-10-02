<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

/**
 * T35, the local part: the application boots the way a production release
 * runs it. APP_ENV=production and APP_DEBUG=false, every framework cache
 * built by `php artisan optimize` (config, events, routes, views), the
 * compiled assets from `make build`, and real HTTP served from those caches
 * by PHP's built-in web server. Errors show plain pages; the details go to
 * the log only. The hosted deployment itself is M11.
 *
 * The caches go to a temporary directory (Laravel's APP_*_CACHE and
 * VIEW_COMPILED_PATH variables), so the development caches are untouched,
 * and every database setting points at a dedicated test database.
 */
class ProductionBootTest extends TestCase
{
    use UsesCommittedDatabase;

    private const DATABASE = 'fleetfuel_test_production';

    private string $cacheDirectory;

    /** @var array<string, string> */
    private array $environment = [];

    private ?Process $server = null;

    private string $baseUrl = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileExists(public_path('build/manifest.json'), 'Build the production assets first: make build (make setup does this).');
        $this->assertFileDoesNotExist(public_path('hot'), 'A Vite dev server is running (public/hot); stop it to test the built assets.');

        $this->useCommittedDatabase(self::DATABASE);
        $this->cacheDirectory = storage_path('framework/testing/production-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->cacheDirectory.'/views');
        $this->environment = $this->productionEnvironment();
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        File::deleteDirectory($this->cacheDirectory);

        parent::tearDown();
    }

    public function test_the_app_serves_from_production_caches_with_debug_off(): void
    {
        $optimize = $this->artisanProcess(['optimize']);
        $this->assertSame(0, $optimize->getExitCode(), $optimize->getOutput().$optimize->getErrorOutput());

        // The cached configuration is the production one, on the test database.
        $config = require $this->cacheDirectory.'/config.php';
        $this->assertSame('production', $config['app']['env']);
        $this->assertFalse($config['app']['debug']);
        $this->assertSame(self::DATABASE, $config['database']['connections']['mysql']['database']);
        $this->assertFileExists($this->cacheDirectory.'/routes.php');
        $this->assertFileExists($this->cacheDirectory.'/events.php');
        $this->assertNotEmpty(glob($this->cacheDirectory.'/views/*.php'));

        $about = json_decode($this->artisanProcess(['about', '--json'])->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('production', $about['environment']['environment']);
        $this->assertFalse($about['environment']['debug_mode']);
        $this->assertSame(['config' => true, 'events' => true, 'routes' => true, 'views' => true], $about['cache']);

        $this->startServer();

        $this->assertSame(200, $this->fetch('/up')[0]);
        [$status, $health] = $this->fetch('/health');
        $this->assertSame(200, $status, $health);

        // The sign-in page uses the compiled, hashed assets from the manifest.
        [$status, $login] = $this->fetch('/login');
        $this->assertSame(200, $status);
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
            $file = $manifest[$entry]['file'];
            $this->assertStringContainsString('/build/'.$file, $login);
            $this->assertFileExists(public_path('build/'.$file));
        }
        $this->assertStringNotContainsString('@vite/client', $login);

        // Behind the trusted HTTPS proxy the app builds https URLs, and the
        // session cookie is only ever sent over HTTPS.
        [$status, $proxied, $headers] = $this->fetch('/login', ['X-Forwarded-Proto: https', 'X-Forwarded-Host: fleetfuel.example.com', 'X-Forwarded-Port: 443']);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('action="https://fleetfuel.example.com/login"', $proxied);
        $sessionCookie = array_values(array_filter($headers, fn (string $header): bool => stripos($header, 'Set-Cookie: fleetfuel-portal-session=') === 0));
        $this->assertCount(1, $sessionCookie, implode("\n", $headers));
        $this->assertMatchesRegularExpression('/;\s*secure/i', $sessionCookie[0]);
        $this->assertMatchesRegularExpression('/;\s*httponly/i', $sessionCookie[0]);

        [$status, $missing] = $this->fetch('/no-such-page');
        $this->assertSame(404, $status);
        $this->assertStringContainsString('Page not found', $missing);

        [$status, $api] = $this->fetch('/api/v1/stations');
        $this->assertSame(401, $status);
        $this->assertSame('unauthenticated', json_decode($api, true, 512, JSON_THROW_ON_ERROR)['error']['code']);

        // A real failure: every web request starts a database session, and
        // the sessions table is gone. The visitor gets the plain page; the
        // SQL error goes to the log only.
        DB::statement('RENAME TABLE sessions TO sessions_hidden');
        try {
            [$status, $error] = $this->fetch('/login');
        } finally {
            DB::statement('RENAME TABLE sessions_hidden TO sessions');
        }

        $this->assertSame(500, $status);
        $this->assertStringContainsString('Something went wrong', $error);
        foreach (['SQLSTATE', 'sessions', 'Stack trace', 'vendor/laravel', 'Whoops'] as $detail) {
            $this->assertStringNotContainsString($detail, $error);
        }
        $this->assertLogged('SQLSTATE[42S02]');
        // The logged SQL keeps its placeholder, not the visitor's session ID.
        $this->assertStringContainsString('where `id` = ?', $this->server?->getErrorOutput() ?? '');
    }

    /**
     * Production settings for every child process. Environment variables win
     * over .env, so nothing here can reach the development database.
     *
     * @return array<string, string>
     */
    private function productionEnvironment(): array
    {
        $mysql = config('database.connections.mysql');

        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_CONFIG_CACHE' => $this->cacheDirectory.'/config.php',
            'APP_ROUTES_CACHE' => $this->cacheDirectory.'/routes.php',
            'APP_EVENTS_CACHE' => $this->cacheDirectory.'/events.php',
            'VIEW_COMPILED_PATH' => $this->cacheDirectory.'/views',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => (string) $mysql['host'],
            'DB_PORT' => (string) $mysql['port'],
            'DB_DATABASE' => self::DATABASE,
            'DB_USERNAME' => (string) $mysql['username'],
            'DB_PASSWORD' => (string) $mysql['password'],
            'SESSION_DRIVER' => 'database',
            'SESSION_SECURE_COOKIE' => 'true',
            // PHP's built-in server stands in for the HTTPS proxy in front of the app.
            'TRUSTED_PROXIES' => '127.0.0.1',
            'CACHE_STORE' => 'database',
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'error',
            'DEMO_MODE' => 'false',
            'DEMO_PASSWORD' => '',
            'EXCHANGE_RATE_MODE' => 'fixture',
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function artisanProcess(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments, '--no-interaction'], base_path(), $this->environment, null, 60);
        $process->run();

        return $process;
    }

    /** `php -S` with Laravel's router script, on a free local port. */
    private function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->server = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            public_path(),
            $this->environment,
        );
        $this->server->start();
        $this->baseUrl = "http://127.0.0.1:{$port}";

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if (@file_get_contents($this->baseUrl.'/up', false, stream_context_create(['http' => ['timeout' => 1]])) !== false) {
                return;
            }

            if (! $this->server->isRunning()) {
                break;
            }

            usleep(100_000);
        }

        $this->fail('The PHP test server did not start: '.$this->server->getErrorOutput().$this->server->getOutput());
    }

    /**
     * @param  list<string>  $headers  extra request headers, such as "X-Forwarded-Proto: https"
     * @return array{int, string, list<string>} status code, body and response headers
     */
    private function fetch(string $path, array $headers = []): array
    {
        $accept = str_starts_with($path, '/api/') ? 'application/json' : 'text/html';
        $request = implode("\r\n", ["Accept: {$accept}", ...$headers])."\r\n";
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => $request]]);

        $body = file_get_contents($this->baseUrl.$path, false, $context);
        $this->assertIsString($body, "No answer from {$path}.");
        preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $status);

        return [(int) ($status[1] ?? 0), $body, $http_response_header];
    }

    private function assertLogged(string $text): void
    {
        $this->assertNotNull($this->server);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && ! str_contains($this->server->getErrorOutput(), $text)) {
            usleep(100_000);
        }

        $this->assertStringContainsString($text, $this->server->getErrorOutput(), 'The error was not written to the log.');
    }
}
