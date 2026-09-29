<?php

namespace Tests\Feature\ExchangeRates;

use App\Enums\RateSource;
use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\IntegrationSyncState;
use App\Services\PriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The admin integration-status page and manual overrides (T12, D10):
 * admin only, overrides start now or later, last at most 72 hours, are
 * audited and take precedence while valid. The page never calls the
 * provider.
 */
class IntegrationStatusScreenTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        config(['fleetfuel.exchange_rates.mode' => 'fixture']);
        $this->seedDemo();
    }

    public function test_an_admin_sees_the_rate_in_effect_and_the_sync_state(): void
    {
        $this->actingAs($this->admin())
            ->get(route('integrations.exchange-rates'))
            ->assertOk()
            ->assertSee('Fixture mode')
            ->assertSeeInOrder(['Rate in effect now', '89,500.00000000', 'Fixture (synthetic)'])
            ->assertSee('No sync has run in this mode yet.')
            // The seeded override has already expired.
            ->assertSeeInOrder(['Recent overrides', '89,700.00000000', 'Expired', 'Demo scenario: temporary bank-rate adjustment'])
            ->assertDontSee('Rates By Exchange Rate API');

        $this->get(route('dashboard'))->assertSee('Exchange rates');
    }

    public function test_other_roles_cannot_open_the_page_or_enter_an_override(): void
    {
        foreach ([$this->atlasManager(), $this->operator()] as $user) {
            $this->startNewRequestCycle();
            $this->actingAs($user);

            $this->get(route('integrations.exchange-rates'))->assertForbidden();
            $this->post(route('integrations.exchange-rates.overrides.store'), [
                'rate' => '1', 'reason' => 'Not allowed', 'valid_for_hours' => '1',
            ])->assertForbidden();
        }

        $this->get(route('products.index'))->assertOk()->assertDontSee('Exchange rates');
        $this->assertSame(1, ExchangeRate::query()->where('source', RateSource::Manual)->count());
    }

    public function test_an_override_starting_now_is_audited_and_takes_precedence(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->post(route('integrations.exchange-rates.overrides.store'), [
            'rate' => '90250.5',
            'reason' => 'Bank holiday adjustment',
            'valid_for_hours' => '12',
            'starts_at' => '',
        ])->assertRedirect(route('integrations.exchange-rates'))->assertSessionHasNoErrors();

        $override = ExchangeRate::query()->where('source', RateSource::Manual)->latest('id')->firstOrFail();
        $this->assertSame('90250.50000000', $override->rate);
        $this->assertSame(self::FIXTURE_NOW, $override->effective_at->toIso8601ZuluString());
        $this->assertSame('2026-09-28T21:00:00Z', $override->expires_at->toIso8601ZuluString());
        $this->assertSame($admin->id, $override->created_by);
        $this->assertSame('Bank holiday adjustment', $override->reason);

        $audit = AuditLog::query()->where('action', 'exchange_rate.override_created')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($override->id, $audit->auditable_id);
        $this->assertEquals([
            'rate' => '90250.50000000',
            'effective_at' => self::FIXTURE_NOW,
            'expires_at' => '2026-09-28T21:00:00Z',
            'reason' => 'Bank holiday adjustment',
        ], $audit->new_values);

        $this->assertTrue($override->is(app(PriceResolver::class)->rateAt(CarbonImmutable::now())));
        $this->get(route('integrations.exchange-rates'))->assertOk()
            ->assertSeeInOrder(['Rate in effect now', '90,250.50000000', 'Manual override', 'Bank holiday adjustment'])
            ->assertSee('In effect');
    }

    public function test_a_future_override_applies_only_from_its_start(): void
    {
        $this->actingAs($this->admin());

        // 15:00 in Beirut is 12:00 UTC.
        $this->post(route('integrations.exchange-rates.overrides.store'), [
            'rate' => '91000',
            'reason' => 'Announced change',
            'valid_for_hours' => '6',
            'starts_at' => '2026-09-28T15:00',
        ])->assertSessionHasNoErrors();

        $resolver = app(PriceResolver::class);
        $this->assertSame(RateSource::Fixture, $resolver->rateAt(CarbonImmutable::now())->source);
        $this->assertSame('91000.00000000', $resolver->rateAt(CarbonImmutable::parse('2026-09-28T12:00:00Z'))->rate);
        $this->assertSame(RateSource::Fixture, $resolver->rateAt(CarbonImmutable::parse('2026-09-28T18:00:00Z'))->source);

        $this->get(route('integrations.exchange-rates'))->assertOk()->assertSee('Scheduled');
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidOverrides')]
    public function test_invalid_overrides_are_refused_and_nothing_is_written(array $overrides, string $field): void
    {
        $count = ExchangeRate::query()->count();
        $input = array_merge(['rate' => '90000', 'reason' => 'Valid reason', 'valid_for_hours' => '24', 'starts_at' => ''], $overrides);

        $this->actingAs($this->admin())
            ->from(route('integrations.exchange-rates'))
            ->post(route('integrations.exchange-rates.overrides.store'), $input)
            ->assertRedirect(route('integrations.exchange-rates'))
            ->assertSessionHasErrors($field);

        $this->assertSame($count, ExchangeRate::query()->count());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidOverrides(): array
    {
        return [
            'zero rate' => [['rate' => '0'], 'rate'],
            'negative rate' => [['rate' => '-89500'], 'rate'],
            'nine decimals' => [['rate' => '89500.123456789'], 'rate'],
            'exponent' => [['rate' => '8.95e4'], 'rate'],
            'missing reason' => [['reason' => ''], 'reason'],
            'reason too long' => [['reason' => str_repeat('x', 256)], 'reason'],
            'longer than 72 hours' => [['valid_for_hours' => '73'], 'valid_for_hours'],
            'zero hours' => [['valid_for_hours' => '0'], 'valid_for_hours'],
            'fractional hours' => [['valid_for_hours' => '1.5'], 'valid_for_hours'],
            // Retroactive: 11:00 in Beirut is 08:00 UTC, before the frozen clock.
            'start in the past' => [['starts_at' => '2026-09-28T11:00'], 'starts_at'],
        ];
    }

    public function test_a_failed_sync_is_shown_as_degraded(): void
    {
        IntegrationSyncState::query()->create([
            'name' => 'exchange_rates.fixture',
            'last_attempt_at' => CarbonImmutable::parse('2026-09-28T01:00:00Z'),
            'last_success_at' => CarbonImmutable::parse('2026-09-27T01:00:00Z'),
            'last_error_code' => 'connection_failed',
        ]);

        $this->actingAs($this->admin())->get(route('integrations.exchange-rates'))->assertOk()
            ->assertSee('Sync degraded.')
            ->assertSee('could not be reached')
            ->assertSee('Stored rates are unchanged');
    }

    public function test_the_page_warns_when_no_rate_is_valid(): void
    {
        // Every seeded fixture has expired four days later.
        $this->travelTo(CarbonImmutable::parse('2026-10-02T09:00:00Z'));

        $this->actingAs($this->admin())->get(route('integrations.exchange-rates'))->assertOk()
            ->assertSee('No valid rate.')
            ->assertSee('rate_unavailable');
    }

    public function test_live_mode_shows_the_provider_attribution_and_never_calls_it(): void
    {
        Http::fake();
        config(['fleetfuel.exchange_rates.mode' => 'live']);
        ExchangeRate::factory()->provider()->create([
            'rate' => '89480.00000000',
            'effective_at' => CarbonImmutable::parse('2026-09-28T00:02:31Z'),
        ]);

        $this->actingAs($this->admin())->get(route('integrations.exchange-rates'))->assertOk()
            ->assertSee('Live mode')
            ->assertSeeInOrder(['Rate in effect now', '89,480.00000000', 'Provider'])
            ->assertSee('Rates By Exchange Rate API');

        Http::assertNothingSent();
    }
}
