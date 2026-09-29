<?php

namespace Tests\Feature\ExchangeRates;

use App\Enums\RateSource;
use App\Exceptions\ExchangeRateFetchFailed;
use App\Services\ExchangeRates\HttpExchangeRateProvider;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T11 with faked HTTP only: success, schema errors, timeouts, 5xx, 429 and
 * the bounded retry count. The provider's JSON shape follows its open
 * endpoint documentation (docs/SOURCES.md); the values are synthetic.
 */
class HttpExchangeRateProviderTest extends TestCase
{
    private const NOW = '2026-09-28T09:00:00Z';

    private const OBSERVED = '2026-09-28T00:02:31Z';

    private const NEXT_UPDATE = '2026-09-29T00:31:01Z';

    /** @var list<array{url: string, connect_timeout: mixed, timeout: mixed, allow_redirects: mixed}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));
        Sleep::fake();
    }

    public function test_a_valid_response_becomes_an_observation_at_the_provider_time(): void
    {
        $this->fakeProvider([Http::response(self::body())]);

        $observation = $this->provider()->latest();

        $this->assertSame(RateSource::Provider, $observation->source);
        $this->assertSame('89500.00000000', $observation->rate);
        // The provider's observation instant, not the fetch time.
        $this->assertSame(self::OBSERVED, $observation->effectiveAt->toIso8601ZuluString());
        $this->assertSame(self::NOW, $observation->fetchedAt?->toIso8601ZuluString());
        $this->assertSame(self::NEXT_UPDATE, $observation->nextUpdateAt?->toIso8601ZuluString());

        $this->assertCount(1, $this->sent);
        $this->assertSame('https://open.er-api.com/v6/latest/USD', $this->sent[0]['url']);
        // Short timeouts, and no redirect away from the fixed URL.
        $this->assertSame(3, $this->sent[0]['connect_timeout']);
        $this->assertSame(10, $this->sent[0]['timeout']);
        $this->assertFalse($this->sent[0]['allow_redirects']);
        Sleep::assertNeverSlept();
    }

    public function test_provider_numbers_are_normalized_to_eight_decimals_without_float_arithmetic(): void
    {
        $this->fakeProvider([
            Http::response(self::body(['rates' => ['LBP' => 89500.123456789]])),
            Http::response(self::body(['rates' => ['LBP' => 89500.123456784]])),
            Http::response(self::body(['rates' => ['LBP' => 89500]])),
        ]);

        $this->assertSame('89500.12345679', $this->provider()->latest()->rate);
        $this->assertSame('89500.12345678', $this->provider()->latest()->rate);
        $this->assertSame('89500.00000000', $this->provider()->latest()->rate);
    }

    public function test_transient_failures_are_retried_with_bounded_pauses(): void
    {
        $this->fakeProvider([
            Http::response('upstream error', 502),
            Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds'),
            Http::response(self::body()),
        ]);

        $this->assertSame('89500.00000000', $this->provider()->latest()->rate);

        $this->assertCount(3, $this->sent);
        Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds()]);
    }

    public function test_repeated_server_errors_stop_after_three_attempts(): void
    {
        $this->fakeProvider([
            Http::response('', 500),
            Http::response('', 503),
            Http::response('', 500),
        ]);

        $failure = $this->expectFailure();

        $this->assertSame('server_error', $failure->reason);
        $this->assertSame(3, $failure->attempts);
        $this->assertCount(3, $this->sent);
        Sleep::assertSleptTimes(2);
    }

    public function test_repeated_timeouts_stop_after_three_attempts(): void
    {
        $timeout = Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds');
        $this->fakeProvider([$timeout, $timeout, $timeout]);

        $failure = $this->expectFailure();

        $this->assertSame('connection_failed', $failure->reason);
        $this->assertSame(3, $failure->attempts);
        $this->assertCount(3, $this->sent);
    }

    public function test_a_429_ends_the_run_with_the_documented_20_minute_wait(): void
    {
        $this->fakeProvider([Http::response('', 429)]);

        $failure = $this->expectFailure();

        $this->assertSame('rate_limited', $failure->reason);
        $this->assertSame('2026-09-28T09:20:00Z', $failure->retryAt?->toIso8601ZuluString());
        $this->assertCount(1, $this->sent);
        Sleep::assertNeverSlept();
    }

    public function test_a_429_retry_after_header_is_respected_within_one_day(): void
    {
        $this->fakeProvider([
            Http::response('', 429, ['Retry-After' => '120']),
            Http::response('', 429, ['Retry-After' => CarbonImmutable::parse('2026-09-28T11:00:00Z')->toRfc7231String()]),
            Http::response('', 429, ['Retry-After' => '999999']),
        ]);

        foreach (['2026-09-28T09:02:00Z', '2026-09-28T11:00:00Z', '2026-09-29T09:00:00Z'] as $expected) {
            $failure = $this->expectFailure();
            $this->assertSame($expected, $failure->retryAt?->toIso8601ZuluString());
        }
    }

    public function test_other_client_errors_are_not_retried(): void
    {
        $this->fakeProvider([Http::response('', 404)]);

        $failure = $this->expectFailure();

        $this->assertSame('http_error', $failure->reason);
        $this->assertCount(1, $this->sent);
    }

    public function test_a_body_that_is_not_json_is_rejected_without_retry(): void
    {
        $this->fakeProvider([Http::response('<html>maintenance</html>', 200)]);

        $this->assertSame('invalid_response', $this->expectFailure()->reason);
        $this->assertCount(1, $this->sent);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_response_is_rejected_without_retry(array $overrides, string $reason): void
    {
        $this->fakeProvider([Http::response(self::body($overrides))]);

        $this->assertSame($reason, $this->expectFailure()->reason);
        $this->assertCount(1, $this->sent);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'result error' => [['result' => 'error', 'error-type' => 'unsupported-code'], 'provider_error'],
            'base is not USD' => [['base_code' => 'EUR'], 'unexpected_base'],
            'LBP missing' => [['rates' => ['LBP' => null]], 'invalid_rate'],
            'LBP as a string' => [['rates' => ['LBP' => '89500']], 'invalid_rate'],
            'LBP zero' => [['rates' => ['LBP' => 0]], 'invalid_rate'],
            'LBP negative' => [['rates' => ['LBP' => -89500]], 'invalid_rate'],
            'LBP rounds to zero' => [['rates' => ['LBP' => 0.000000001]], 'invalid_rate'],
            'LBP beyond DECIMAL(20,8)' => [['rates' => ['LBP' => 1.0E13]], 'invalid_rate'],
            'observation time missing' => [['time_last_update_unix' => null], 'invalid_timestamp'],
            'observation time as a string' => [['time_last_update_unix' => '1790553751'], 'invalid_timestamp'],
            'observation time in the future' => [['time_last_update_unix' => CarbonImmutable::parse('2026-09-28T09:10:00Z')->getTimestamp()], 'invalid_timestamp'],
            'observation older than 72 hours' => [['time_last_update_unix' => CarbonImmutable::parse('2026-09-25T09:00:00Z')->getTimestamp()], 'stale_observation'],
        ];
    }

    public function test_a_small_clock_skew_and_a_missing_next_update_are_tolerated(): void
    {
        $this->fakeProvider([Http::response(self::body([
            'time_last_update_unix' => CarbonImmutable::parse('2026-09-28T09:04:00Z')->getTimestamp(),
            'time_next_update_unix' => 0,
        ]))]);

        $observation = $this->provider()->latest();

        $this->assertSame('2026-09-28T09:04:00Z', $observation->effectiveAt->toIso8601ZuluString());
        $this->assertNull($observation->nextUpdateAt);
    }

    private function provider(): HttpExchangeRateProvider
    {
        return app(HttpExchangeRateProvider::class);
    }

    private function expectFailure(): ExchangeRateFetchFailed
    {
        try {
            $this->provider()->latest();
        } catch (ExchangeRateFetchFailed $e) {
            return $e;
        }

        $this->fail('The provider returned an observation instead of failing.');
    }

    /**
     * Answer successive requests from the list and record the options each
     * request was sent with. An unexpected extra request fails the test.
     *
     * @param  list<PromiseInterface|Closure>  $responses
     */
    private function fakeProvider(array $responses): void
    {
        Http::fake(function (Request $request, array $options) use (&$responses) {
            $this->sent[] = [
                'url' => $request->url(),
                'connect_timeout' => $options['connect_timeout'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'allow_redirects' => $options['allow_redirects'] ?? null,
            ];

            $next = array_shift($responses) ?? throw new LogicException('Unexpected extra provider request.');

            return $next instanceof Closure ? $next($request) : $next;
        });
    }

    /**
     * A successful response in the provider's documented shape.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function body(array $overrides = []): array
    {
        return array_replace_recursive([
            'result' => 'success',
            'provider' => 'https://www.exchangerate-api.com',
            'time_last_update_unix' => CarbonImmutable::parse(self::OBSERVED)->getTimestamp(),
            'time_next_update_unix' => CarbonImmutable::parse(self::NEXT_UPDATE)->getTimestamp(),
            'time_eol_unix' => 0,
            'base_code' => 'USD',
            'rates' => ['USD' => 1, 'EUR' => 0.8712, 'LBP' => 89500],
        ], $overrides);
    }
}
