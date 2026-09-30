<?php

namespace Tests\Feature\Console;

use App\Enums\CardStatus;
use App\Enums\ProductCode;
use App\Models\AuditLog;
use App\Models\FuelCard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * `php artisan demo:simulator-cards`: fresh dedicated cards for simulator
 * reruns, added to the demo without touching anything else.
 */
class SimulatorCardsCommandTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_it_adds_three_audited_diesel_cards_to_the_demo_company_and_nothing_else(): void
    {
        $cardsBefore = FuelCard::query()->count();

        $this->artisan('demo:simulator-cards', ['--tag' => 'run2'])
            ->expectsOutputToContain('export POS_CARD=FF-SIM-RUN2-MAIN POS_BLOCKED_CARD=FF-SIM-RUN2-BLOCKED POS_TINY_CARD=FF-SIM-RUN2-TINY')
            ->expectsOutputToContain('Nothing else was changed.')
            ->assertSuccessful();

        $this->assertSame($cardsBefore + 3, FuelCard::query()->count());
        $cards = FuelCard::query()->where('card_no', 'like', 'FF-SIM-RUN2-%')->orderBy('id')->get();
        $this->assertSame(
            [
                ['FF-SIM-RUN2-MAIN', CardStatus::Active, '100.00', '100.00'],
                ['FF-SIM-RUN2-BLOCKED', CardStatus::Blocked, '100.00', '100.00'],
                ['FF-SIM-RUN2-TINY', CardStatus::Active, '5.00', '100.00'],
            ],
            $cards->map(fn (FuelCard $card): array => [$card->card_no, $card->status, $card->monthly_limit_l, $card->monthly_limit_usd])->all(),
        );
        foreach ($cards as $card) {
            $this->assertSame([$this->atlas()->id, null, null], [$card->company_id, $card->vehicle_id, $card->driver_id]);
            $this->assertSame($this->product(ProductCode::Diesel)->id, $card->allowed_product_id);
        }

        $audits = AuditLog::query()->where('action', 'card.created')->whereIn('auditable_id', $cards->pluck('id'))->get();
        $this->assertCount(3, $audits);
        $this->assertNull($audits->first()?->user_id);
    }

    public function test_a_used_tag_or_a_bad_tag_changes_nothing(): void
    {
        $this->artisan('demo:simulator-cards', ['--tag' => 'ONCE'])->assertSuccessful();
        $count = FuelCard::query()->count();

        $this->artisan('demo:simulator-cards', ['--tag' => 'once'])
            ->expectsOutputToContain('Cards with the tag ONCE already exist')
            ->assertFailed();
        $this->artisan('demo:simulator-cards', ['--tag' => 'TOO-LONG-TAG'])->assertFailed();

        $this->assertSame($count, FuelCard::query()->count());
    }

    public function test_it_runs_only_for_the_local_demo(): void
    {
        config(['fleetfuel.demo.enabled' => false]);

        $this->artisan('demo:simulator-cards')->expectsOutputToContain('DEMO_MODE')->assertFailed();

        config(['fleetfuel.demo.enabled' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->artisan('demo:simulator-cards')->assertFailed();

        $this->assertSame(0, FuelCard::query()->where('card_no', 'like', 'FF-SIM-%')->count());
    }

    public function test_a_random_tag_is_used_when_none_is_given(): void
    {
        $this->artisan('demo:simulator-cards')->assertSuccessful();

        $numbers = FuelCard::query()->where('card_no', 'like', 'FF-SIM-%')->orderBy('id')->pluck('card_no')->all();
        $this->assertCount(3, $numbers);
        $this->assertMatchesRegularExpression('/^FF-SIM-[A-HJ-NP-Z2-9]{4}-MAIN$/', $numbers[0]);
    }
}
