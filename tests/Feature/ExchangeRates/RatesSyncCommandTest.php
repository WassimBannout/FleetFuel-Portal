<?php

namespace Tests\Feature\ExchangeRates;

use App\Enums\RateSource;
use App\Exceptions\RateUnavailable;
use App\Models\ExchangeRate;
use App\Models\IntegrationSyncState;
use App\Services\PriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * T11/T12 through `rates:sync` on MySQL, with faked HTTP only: one stored
 * row per observation, the next-update hint, outages that leave stored
 * rates untouched, fixture mode without HTTP, and the daily schedule.
 */
class RatesSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-28T09:00:00Z';

    private const OBSERVED = '2026-09-28T00:02:31Z';

    private const NEXT_UPDATE = '2026-09-29T00:31:01Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));
        Sleep::fake();
    }

    public function test_a_live_sync_stores_the_provider_observation_once(): void
    {
        $this->liveMode();
        Http::fake(['open.er-api.com/*' => Http::response($this->body())]);

        $this->artisan('rates:sync')
            ->expectsOutputToContain('Stored the Provider observation of 89500.00000000 LBP per USD at '.self::OBSERVED)
            ->assertSuccessful();

        $rate = ExchangeRate::query()->sole();
        $this->assertSame(RateSource::Provider, $rate->source);
        $this->assertSame('89500.00000000', $rate->rate);
        $this->assertSame(self::OBSERVED, $rate->effective_at->toIso8601ZuluString());
        $this->assertSame(self::NOW, $rate->fetched_at?->toIso8601ZuluString());
        $this->assertSame('2026-10-01T00:02:31Z', $rate->expires_at->toIso8601ZuluString());
        $this->assertNull($rate->created_by);

        $state = $this->state('live');
        $this->assertSame(self::NOW, $state->last_attempt_at?->toIso8601ZuluString());
        $this->assertSame(self::NOW, $state->last_success_at?->toIso8601ZuluString());
        $this->assertNull($state->last_error_code);
        $this->assertSame(self::NEXT_UPDATE, $state->next_attempt_at?->toIso8601ZuluString());
    }

    /** The provider's next-update hint prevents repeated same-day downloads. */
    public function test_runs_before_the_next_update_do_not_download_again(): void
    {
        $this->liveMode();
        Http::fake(['open.er-api.com/*' => Http::response($this->body())]);
        $this->artisan('rates:sync')->assertSuccessful();

        $this->travelTo(CarbonImmutable::parse('2026-09-28T15:00:00Z'));
        $this->artisan('rates:sync')
            ->expectsOutputToContain('Nothing fetched')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, ExchangeRate::query()->count());
    }

    /** T11: fetching the same observation again is a no-op. */
    public function test_a_forced_repeat_of_the_same_observation_changes_nothing(): void
    {
        $this->liveMode();
        Http::fake(['open.er-api.com/*' => Http::response($this->body())]);
        $this->artisan('rates:sync')->assertSuccessful();
        $stored = ExchangeRate::query()->sole();

        $this->travelTo(CarbonImmutable::parse('2026-09-28T09:30:00Z'));
        $this->artisan('rates:sync', ['--force' => true])
            ->expectsOutputToContain('was already stored; nothing changed')
            ->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(1, ExchangeRate::query()->count());
        // Still the first fetch time: the row was not touched.
        $this->assertSame(self::NOW, ExchangeRate::query()->sole()->fetched_at?->toIso8601ZuluString());
        $this->assertTrue($stored->is(ExchangeRate::query()->sole()));
    }

    /** D10: a conflicting value for a stored instant is logged; the stored value is kept. */
    public function test_a_conflicting_value_for_a_stored_observation_is_logged_and_ignored(): void
    {
        $this->liveMode();
        Http::fake(['open.er-api.com/*' => Http::sequence()
            ->push($this->body())
            ->push($this->body(['rates' => ['LBP' => 91000]])),
        ]);
        $this->artisan('rates:sync')->assertSuccessful();

        $log = Log::spy();
        $this->artisan('rates:sync', ['--force' => true])
            ->expectsOutputToContain('Kept the stored value')
            ->assertSuccessful();

        $this->assertSame('89500.00000000', ExchangeRate::query()->sole()->rate);
        $this->assertSame('conflicting_observation', $this->state('live')->last_error_code);
        $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'different value')
            && $context['stored_rate'] === '89500.00000000'
            && $context['received_rate'] === '91000.00000000')->once();
    }

    /** A new day's observation after the hint has passed is fetched and stored. */
    public function test_the_next_observation_is_fetched_once_the_hint_expires(): void
    {
        $this->liveMode();
        Http::fake(['open.er-api.com/*' => Http::sequence()
            ->push($this->body())
            ->push($this->body([
                'time_last_update_unix' => CarbonImmutable::parse('2026-09-29T00:02:40Z')->getTimestamp(),
                'time_next_update_unix' => CarbonImmutable::parse('2026-09-30T00:31:10Z')->getTimestamp(),
                'rates' => ['LBP' => 89510.5],
            ])),
        ]);
        $this->artisan('rates:sync')->assertSuccessful();

        $this->travelTo(CarbonImmutable::parse('2026-09-29T01:00:00Z'));
        $this->artisan('rates:sync')->assertSuccessful();

        $this->assertSame(['89500.00000000', '89510.50000000'], ExchangeRate::query()->orderBy('effective_at')->pluck('rate')->all());
        $this->assertSame('2026-09-30T00:31:10Z', $this->state('live')->next_attempt_at?->toIso8601ZuluString());
    }

    /**
     * T12: an outage leaves stored rates untouched and keeps using a valid
     * one; once it expires, conversion fails with rate_unavailable. Live
     * mode never falls back to fixtures.
     */
    public function test_an_outage_keeps_the_last_valid_rate_until_it_expires(): void
    {
        $this->liveMode();
        $provider = ExchangeRate::factory()->provider()->create([
            'rate' => '89400.00000000',
            'effective_at' => CarbonImmutable::parse('2026-09-27T00:02:00Z'),
        ]);
        IntegrationSyncState::query()->create(['name' => 'exchange_rates.live', 'last_success_at' => CarbonImmutable::parse('2026-09-27T01:00:00Z')]);
        Http::fake(['open.er-api.com/*' => Http::response('', 503)]);

        $this->artisan('rates:sync')
            ->expectsOutputToContain('Stored rates are unchanged')
            ->assertFailed();

        Http::assertSentCount(3);
        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertFalse(ExchangeRate::query()->where('source', RateSource::Fixture)->exists());
        $state = $this->state('live');
        $this->assertSame('server_error', $state->last_error_code);
        $this->assertSame('2026-09-27T01:00:00Z', $state->last_success_at?->toIso8601ZuluString());
        $this->assertNull($state->next_attempt_at);

        $resolver = app(PriceResolver::class);
        $this->assertTrue($provider->is($resolver->rateAt(CarbonImmutable::now())));

        $this->travelTo(CarbonImmutable::parse('2026-09-30T00:02:00Z'));
        $this->expectException(RateUnavailable::class);
        $resolver->rateAt(CarbonImmutable::now());
    }

    /** T11: a 429 records the retry advice; runs before it make no request. */
    public function test_a_429_postpones_the_next_attempt(): void
    {
        $this->liveMode();
        Http::fake(['open.er-api.com/*' => Http::response('', 429)]);

        $this->artisan('rates:sync')->assertFailed();
        $this->assertSame('2026-09-28T09:20:00Z', $this->state('live')->next_attempt_at?->toIso8601ZuluString());
        $this->assertSame('rate_limited', $this->state('live')->last_error_code);

        $this->travelTo(CarbonImmutable::parse('2026-09-28T09:10:00Z'));
        $this->artisan('rates:sync')->expectsOutputToContain('Nothing fetched')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(0, ExchangeRate::query()->count());
    }

    /** Fixture mode makes no HTTP request and appends one labeled row per UTC day. */
    public function test_fixture_mode_appends_one_synthetic_observation_per_utc_day_without_http(): void
    {
        config(['fleetfuel.exchange_rates.mode' => 'fixture']);
        Http::fake();
        $earlier = ExchangeRate::factory()->create(['effective_at' => CarbonImmutable::parse('2026-09-27T00:00:00Z')]);

        $this->artisan('rates:sync')
            ->expectsOutputToContain('Stored the Fixture (synthetic) observation of 89500.00000000 LBP per USD at 2026-09-28T00:00:00Z')
            ->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-09-28T23:59:00Z'));
        $this->artisan('rates:sync')->expectsOutputToContain('Nothing fetched')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-09-29T01:00:00Z'));
        $this->artisan('rates:sync')->assertSuccessful();

        Http::assertNothingSent();
        $fixtures = ExchangeRate::query()->where('source', RateSource::Fixture)->orderBy('effective_at')->get();
        $this->assertSame(
            ['2026-09-27T00:00:00Z', '2026-09-28T00:00:00Z', '2026-09-29T00:00:00Z'],
            $fixtures->map(fn (ExchangeRate $rate) => $rate->effective_at->toIso8601ZuluString())->all(),
        );
        $this->assertSame('2026-10-01T00:00:00Z', $fixtures[1]->expires_at->toIso8601ZuluString());
        $this->assertNull($fixtures[1]->fetched_at);
        // The earlier fixture row is unchanged.
        $this->assertSame($earlier->created_at?->toIso8601ZuluString(), $fixtures[0]->created_at?->toIso8601ZuluString());
        $this->assertNull(IntegrationSyncState::query()->where('name', 'exchange_rates.live')->first());
    }

    public function test_a_run_while_another_holds_the_lock_does_nothing(): void
    {
        $this->liveMode();
        Http::fake();
        $lock = Cache::lock('rates-sync', 60);
        $lock->get();

        $this->artisan('rates:sync')
            ->expectsOutputToContain('Another rates:sync run is in progress')
            ->assertSuccessful();

        Http::assertNothingSent();
        $lock->release();
    }

    public function test_the_sync_is_scheduled_once_a_day_in_utc_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'rates:sync'));

        $this->assertCount(1, $events);
        $event = $events->sole();
        $this->assertSame('0 1 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_request_goes_only_to_the_fixed_endpoint(): void
    {
        $this->liveMode();
        Http::fake(['open.er-api.com/*' => Http::response($this->body())]);

        $this->artisan('rates:sync')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://open.er-api.com/v6/latest/USD'
            && $request->method() === 'GET');
    }

    private function liveMode(): void
    {
        config(['fleetfuel.exchange_rates.mode' => 'live']);
    }

    private function state(string $mode): IntegrationSyncState
    {
        return IntegrationSyncState::query()->where('name', "exchange_rates.{$mode}")->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_replace_recursive([
            'result' => 'success',
            'time_last_update_unix' => CarbonImmutable::parse(self::OBSERVED)->getTimestamp(),
            'time_next_update_unix' => CarbonImmutable::parse(self::NEXT_UPDATE)->getTimestamp(),
            'base_code' => 'USD',
            'rates' => ['USD' => 1, 'LBP' => 89500],
        ], $overrides);
    }
}
