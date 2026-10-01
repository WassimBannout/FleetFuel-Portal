<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DeliveryStatus;
use App\Models\DeliveryOrder;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\LedgerFixtureBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CountsQueries;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The role-scoped dashboard (docs/06-UI-SPEC.md) on the demo seed, with the
 * clock at 2026-09-28: September is the current Beirut month, August the
 * previous one.
 */
class DashboardTest extends TestCase
{
    use BuildsLedgerFixtures;
    use CountsQueries;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_a_manager_sees_their_company_this_month_and_last_month(): void
    {
        $atlas = $this->atlas();
        $page = $this->actingAs($this->atlasManager())->get('/dashboard')->assertOk();

        $this->assertSame($this->monthTotals($atlas->id, '2026-09-01'), $page->viewData('current'));
        $this->assertSame($this->monthTotals($atlas->id, '2026-08-01'), $page->viewData('previous'));
        $this->assertSame(9, $page->viewData('current')['purchases']);
        $page->assertSeeInOrder(['Liters this month', '515.00', 'August:'])->assertSee('Atlas Logistics');

        // Quota warnings: Atlas cards only, with the reason; the masked number links to the card.
        $warnings = $page->viewData('quotaWarnings');
        $this->assertNotEmpty($warnings);
        foreach ($warnings as $warning) {
            $this->assertSame($atlas->id, $warning['company_id']);
        }
        $blocked = $this->card('FF-ATLAS-BLOCKED');
        $page->assertSee('href="'.route('cards.show', $blocked->id).'"', false)
            ->assertSee('••••CKED')->assertSee('Blocked: every purchase is declined.')
            ->assertSee('••••-H02')->assertSee('Over the liter limit');

        // Open deliveries: Atlas's pending, scheduled and out-for-delivery orders.
        $open = DeliveryOrder::query()->where('company_id', $atlas->id)
            ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Scheduled, DeliveryStatus::OutForDelivery])->pluck('id')->all();
        $this->assertNotEmpty($open);
        $this->assertSame(count($open), $page->viewData('openDeliveryCount'));
        $this->assertEqualsCanonicalizing($open, $page->viewData('openDeliveries')->pluck('id')->all());

        // The USD/LBP rate in use, with its provenance.
        $page->assertSeeInOrder(['USD/LBP rate in use', '89,500.00000000 LBP per USD', 'Fixture (synthetic)'])
            ->assertDontSee('Exchange rate status and overrides');

        $page->assertDontSee('Cedar Catering');
    }

    public function test_the_other_manager_sees_none_of_atlas_and_gets_empty_states(): void
    {
        $page = $this->actingAs($this->cedarManager())->get('/dashboard')->assertOk()
            ->assertSee('Cedar Catering')->assertDontSee('Atlas Logistics')->assertDontSee('ATL-1');

        $cedar = $this->cedar();
        $this->assertSame($this->monthTotals($cedar->id, '2026-09-01'), $page->viewData('current'));
        foreach ($page->viewData('quotaWarnings') as $warning) {
            $this->assertSame($cedar->id, $warning['company_id']);
        }

        // Cedar's demo orders are delivered or cancelled: nothing is open.
        $this->assertSame(0, $page->viewData('openDeliveryCount'));
        $page->assertSee('No delivery is pending, scheduled or on the way.');
    }

    public function test_the_admin_sees_every_company_and_the_rate_status_link(): void
    {
        $page = $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->assertSee('All companies')->assertSee('Exchange rate status and overrides');

        $this->assertSame($this->monthTotals(null, '2026-09-01'), $page->viewData('current'));
        $this->assertSame(2, $page->viewData('companies'));
        $companies = array_unique(array_column($page->viewData('quotaWarnings'), 'company'));
        $this->assertContains('Atlas Logistics', $companies);
    }

    public function test_without_a_valid_rate_the_dashboard_says_so_instead_of_inventing_one(): void
    {
        // Every seeded rate has expired a month later.
        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW)->addDays(40));

        $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->assertSee('No valid USD/LBP rate right now.')
            ->assertDontSee('LBP per USD</p>', false);
    }

    public function test_operators_have_no_dashboard(): void
    {
        $this->actingAs($this->operator())->get('/dashboard')->assertForbidden()->assertSee('Not allowed');
    }

    /** N+1 check: more purchases, cards needing attention and open orders, the same number of queries. */
    public function test_the_dashboard_runs_a_fixed_number_of_queries(): void
    {
        $admin = $this->admin();
        $before = $this->queriesFor($admin, '/dashboard');

        $this->addCardOnlyPurchases(20);
        FuelCard::query()->whereIn('card_no', ['FF-CEDAR-FLEX', 'FF-ATLAS-H01'])->update(['status' => 'blocked']);
        $builder = app(LedgerFixtureBuilder::class);
        foreach (range(1, 4) as $day) {
            $builder->createDelivery($this->cedar(), $this->cedarManager(), [
                'address' => "Cedar test site {$day} (demo)",
                'governorate' => 'Mount Lebanon',
                'liters' => '500.00',
                'preferred_start_at' => CarbonImmutable::now()->addDays($day),
                'preferred_end_at' => CarbonImmutable::now()->addDays($day)->addHours(4),
            ], CarbonImmutable::now());
        }

        $after = $this->queriesFor($admin, '/dashboard');
        $this->assertSame($before, $after, 'queries before and after adding rows');
    }

    /**
     * @return array{purchases: int, liters: string, amount_lbp: string, amount_usd: string}
     */
    private function monthTotals(?int $companyId, string $month): array
    {
        $rows = FuelTransaction::query()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->where('quota_month', $month)
            ->get(['liters', 'amount_lbp', 'amount_usd']);
        $sum = fn (string $column): string => (string) $rows->reduce(
            fn (BigDecimal $total, FuelTransaction $row): BigDecimal => $total->plus((string) $row->getAttribute($column)),
            BigDecimal::zero(),
        )->toScale(2);

        return [
            'purchases' => $rows->count(),
            'liters' => $sum('liters'),
            'amount_lbp' => $sum('amount_lbp'),
            'amount_usd' => $sum('amount_usd'),
        ];
    }
}
