<?php

namespace Tests\Feature\Fleet;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Enums\ProductCode;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\FuelCard;
use App\Services\FuelCardService;
use App\Support\Redact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * Fuel card screens on real routes: T07 (assignments), T08 (audit), and the
 * T04/T05 tenant and role checks. Uses the seeded demo: FF-ATLAS-001 is an
 * unused Atlas card, FF-ATLAS-H01 an Atlas card with purchases this month,
 * FF-CEDAR-001 belongs to the other company.
 */
class FuelCardScreensTest extends TestCase
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

    public function test_a_manager_issues_a_card_for_own_vehicle_and_driver_and_it_is_audited(): void
    {
        $manager = $this->atlasManager();
        $this->actingAs($manager);

        // The form offers only this company's active vehicles and drivers.
        $this->get('/cards/create')
            ->assertOk()
            ->assertViewIs('cards.form')
            ->assertSee('ATL-101')
            ->assertDontSee('CED-201')
            ->assertDontSee('Karim Daou');

        $response = $this->post('/cards', [
            'vehicle_id' => $this->vehicle('ATL-101')->id,
            'driver_id' => $this->driver('ATL-DL-1001')->id,
            'allowed_product_id' => $this->product(ProductCode::Diesel)->id,
            'monthly_limit_l' => '150',
            'monthly_limit_usd' => '120.5',
        ]);

        $card = FuelCard::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('cards.show', $card));

        $this->assertSame($this->atlas()->id, $card->company_id);
        $this->assertMatchesRegularExpression('/^FF-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $card->card_no);
        $this->assertSame('150.00', $card->monthly_limit_l);
        $this->assertSame('120.50', $card->monthly_limit_usd);
        $this->assertSame(CardStatus::Active, $card->status);

        $audit = AuditLog::query()->where('action', 'card.created')->sole();
        $this->assertSame($manager->id, $audit->user_id);
        $this->assertSame($card->company_id, $audit->company_id);
        $this->assertSame($card->vehicle_id, $audit->new_values['vehicle_id'] ?? null);
        $this->assertStringNotContainsString($card->card_no, (string) json_encode($audit->new_values), 'card numbers stay out of audit values');
    }

    /** T07: another company's vehicle or driver is refused, whoever asks. */
    public function test_another_companys_vehicle_or_driver_is_refused(): void
    {
        $before = FuelCard::query()->count();

        $this->actingAs($this->atlasManager())
            ->from('/cards/create')
            ->post('/cards', ['vehicle_id' => $this->vehicle('CED-201')->id])
            ->assertRedirect('/cards/create')
            ->assertSessionHasErrors(['vehicle_id' => 'Choose an active vehicle of this company.']);

        $this->post('/cards', ['driver_id' => $this->driver('CED-DL-2001')->id])
            ->assertSessionHasErrors(['driver_id' => 'Choose an active driver of this company.']);

        $this->actingAs($this->admin())
            ->post('/cards', ['company_id' => $this->atlas()->id, 'vehicle_id' => $this->vehicle('CED-201')->id])
            ->assertSessionHasErrors('vehicle_id');

        $this->assertSame($before, FuelCard::query()->count());

        // The service refuses too, for any caller that skips the Form Request.
        $this->expectException(ValidationException::class);
        app(FuelCardService::class)->create($this->atlas(), $this->vehicle('CED-201'), null, null, null, null, $this->admin());
    }

    public function test_a_manager_cannot_choose_the_owning_company(): void
    {
        $before = FuelCard::query()->count();
        $this->actingAs($this->atlasManager());

        foreach ([$this->cedar()->id, $this->atlas()->id] as $companyId) {
            $this->post('/cards', ['company_id' => $companyId])
                ->assertSessionHasErrors(['company_id' => 'The company is set from your account and cannot be chosen.']);
        }

        // ?company= is ignored for managers: the form still shows their own fleet.
        $this->get('/cards/create?company='.$this->cedar()->id)->assertOk()->assertSee('ATL-101')->assertDontSee('CED-201');

        $this->assertSame($before, FuelCard::query()->count());
    }

    public function test_an_admin_picks_an_active_company_first(): void
    {
        $this->actingAs($this->admin());

        $this->get('/cards/create')->assertOk()->assertViewIs('cards.choose-company');
        $this->get('/cards/create?company='.$this->cedar()->id)
            ->assertOk()
            ->assertViewIs('cards.form')
            ->assertSee('CED-201')
            ->assertDontSee('ATL-101');

        $this->post('/cards', ['company_id' => $this->cedar()->id, 'vehicle_id' => $this->vehicle('CED-201')->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->cedar()->id, FuelCard::query()->latest('id')->firstOrFail()->company_id);

        $this->cedar()->forceFill(['status' => CompanyStatus::Inactive])->save();
        $this->post('/cards', ['company_id' => $this->cedar()->id])->assertSessionHasErrors(['company_id' => 'Choose an active company.']);
        $this->post('/cards', [])->assertSessionHasErrors(['company_id' => 'Choose a company.']);
    }

    public function test_the_vehicle_fuel_type_limits_the_product_restriction(): void
    {
        $before = FuelCard::query()->count();

        $this->actingAs($this->atlasManager())
            ->post('/cards', [
                'vehicle_id' => $this->vehicle('ATL-104')->id, // petrol
                'allowed_product_id' => $this->product(ProductCode::Diesel)->id,
            ])
            ->assertSessionHasErrors('allowed_product_id');

        $this->assertSame($before, FuelCard::query()->count());
    }

    public function test_an_unused_cards_assignment_can_change_and_is_audited(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $driver = $this->driver('ATL-DL-1004');

        $this->actingAs($this->atlasManager())
            ->put(route('cards.update', $card), [
                'vehicle_id' => $card->vehicle_id,
                'driver_id' => $driver->id,
                'allowed_product_id' => $card->allowed_product_id,
            ])
            ->assertRedirect(route('cards.show', $card));

        $this->assertSame($driver->id, $card->refresh()->driver_id);

        $audit = AuditLog::query()->where('action', 'card.assignment_changed')->sole();
        $this->assertSame($this->driver('ATL-DL-1001')->id, $audit->old_values['driver_id'] ?? null);
        $this->assertSame($driver->id, $audit->new_values['driver_id'] ?? null);
    }

    /** T07: after the first purchase the assignment is locked. */
    public function test_a_used_cards_assignment_is_locked(): void
    {
        $card = $this->card('FF-ATLAS-H01');
        $this->assertTrue($card->transactions()->exists());
        $originalDriver = $card->driver_id;

        $this->actingAs($this->atlasManager())
            ->get(route('cards.edit', $card))
            ->assertOk()
            ->assertSee('has already been used');

        $this->from(route('cards.edit', $card))
            ->put(route('cards.update', $card), [
                'vehicle_id' => $card->vehicle_id,
                'driver_id' => $this->driver('ATL-DL-1005')->id,
                'allowed_product_id' => $card->allowed_product_id,
            ])
            ->assertRedirect(route('cards.edit', $card))
            ->assertSessionHasErrors(['rule' => BusinessRuleViolation::assignmentLocked()->getMessage()]);

        $this->assertSame($originalDriver, $card->refresh()->driver_id);
        $this->assertSame(0, AuditLog::query()->where('action', 'card.assignment_changed')->count());
    }

    /** T07: company ownership is immutable. */
    public function test_a_card_cannot_move_to_another_company(): void
    {
        $card = $this->card('FF-ATLAS-001');

        $this->actingAs($this->admin())
            ->put(route('cards.update', $card), ['company_id' => $this->cedar()->id])
            ->assertSessionHasErrors(['company_id' => 'A card cannot move to another company.']);
        $this->patch(route('cards.limits', $card), ['company_id' => $this->cedar()->id, 'monthly_limit_l' => '10'])
            ->assertSessionHasErrors('company_id');

        $this->assertSame($this->atlas()->id, $card->refresh()->company_id);
    }

    /** T08: quota changes record the selected before/after values. */
    public function test_a_limit_change_is_audited_with_old_and_new_values(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $manager = $this->atlasManager();

        $this->actingAs($manager)
            ->patch(route('cards.limits', $card), ['monthly_limit_l' => '80', 'monthly_limit_usd' => ''])
            ->assertRedirect(route('cards.show', $card))
            ->assertSessionHas('status', 'Monthly limits saved.');

        $card->refresh();
        $this->assertSame('80.00', $card->monthly_limit_l);
        $this->assertNull($card->monthly_limit_usd, 'an empty limit means unlimited');

        $audit = AuditLog::query()->where('action', 'card.limits_changed')->where('auditable_id', $card->id)->sole();
        $this->assertSame($manager->id, $audit->user_id);
        $this->assertEquals(['monthly_limit_l' => '100.00', 'monthly_limit_usd' => '100.00'], $audit->old_values);
        $this->assertEquals(['monthly_limit_l' => '80.00', 'monthly_limit_usd' => null, 'below_current_usage' => false], $audit->new_values);
        $this->assertNotNull($audit->request_id);

        // Saving the same values again writes nothing.
        $this->patch(route('cards.limits', $card), ['monthly_limit_l' => '80.00', 'monthly_limit_usd' => '']);
        $this->assertSame(1, AuditLog::query()->where('action', 'card.limits_changed')->where('auditable_id', $card->id)->count());
    }

    public function test_lowering_a_limit_below_this_months_usage_needs_confirmation(): void
    {
        $card = $this->card('FF-ATLAS-H01');
        $balance = app(FuelCardService::class)->balance($card);
        $this->assertTrue((float) $balance->usedL > 1, 'fixture: the card has usage this month');
        $this->actingAs($this->atlasManager());

        // Refused and sent back to the card page, which shows the warning and
        // the confirmation box. (Following the redirect in one chain matters:
        // calling assertSessionHasErrors() between two test requests empties
        // Laravel's JSON-serialized error bag, a test-only artifact.)
        $this->from(route('cards.show', $card))
            ->followingRedirects()
            ->patch(route('cards.limits', $card), ['monthly_limit_l' => '1', 'monthly_limit_usd' => $card->monthly_limit_usd])
            ->assertOk()
            ->assertSee('Tick the confirmation box')
            ->assertSee('name="confirm_below_usage"', false);
        $this->assertSame('400.00', $card->refresh()->monthly_limit_l);

        $this->patch(route('cards.limits', $card), [
            'monthly_limit_l' => '1',
            'monthly_limit_usd' => $card->monthly_limit_usd,
            'confirm_below_usage' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1.00', $card->refresh()->monthly_limit_l);
        $audit = AuditLog::query()->where('action', 'card.limits_changed')->where('auditable_id', $card->id)->sole();
        $this->assertTrue($audit->new_values['below_current_usage'] ?? null);
        $this->get(route('cards.show', $card))->assertSee('Over quota this month');
    }

    /** T08: block, unblock and archive are audited; archive is final. */
    public function test_block_unblock_and_archive(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $this->actingAs($this->atlasManager());

        $this->patch(route('cards.status', $card), ['status' => 'blocked'])->assertSessionHasNoErrors();
        $this->assertSame(CardStatus::Blocked, $card->refresh()->status);
        $this->patch(route('cards.status', $card), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->patch(route('cards.status', $card), ['status' => 'archived'])->assertSessionHasNoErrors();
        $this->assertSame(CardStatus::Archived, $card->refresh()->status);

        $this->assertSame(
            [['active', 'blocked'], ['blocked', 'active'], ['active', 'archived']],
            AuditLog::query()->where('action', 'card.status_changed')->orderBy('id')->get()
                ->map(fn (AuditLog $entry) => [$entry->old_values['status'] ?? null, $entry->new_values['status'] ?? null])
                ->all(),
        );

        $archived = BusinessRuleViolation::cardArchived()->getMessage();
        $this->patch(route('cards.status', $card), ['status' => 'active'])->assertSessionHasErrors(['rule' => $archived]);
        $this->patch(route('cards.limits', $card), ['monthly_limit_l' => '5'])->assertSessionHasErrors(['rule' => $archived]);
        $this->put(route('cards.update', $card), ['driver_id' => null])->assertSessionHasErrors(['rule' => $archived]);
        $this->assertSame(CardStatus::Archived, $card->refresh()->status);
    }

    public function test_an_inactive_companys_cards_can_only_be_blocked_or_archived(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $this->atlas()->forceFill(['status' => CompanyStatus::Inactive])->save();
        $inactive = BusinessRuleViolation::companyInactive()->getMessage();
        $before = FuelCard::query()->count();
        $this->actingAs($this->atlasManager());

        $this->post('/cards', [])->assertSessionHasErrors(['rule' => $inactive]);
        $this->assertSame($before, FuelCard::query()->count());
        $this->patch(route('cards.limits', $card), ['monthly_limit_l' => '500'])->assertSessionHasErrors(['rule' => $inactive]);

        $this->patch(route('cards.status', $card), ['status' => 'blocked'])->assertSessionHasNoErrors();
        $this->patch(route('cards.status', $card), ['status' => 'active'])->assertSessionHasErrors(['rule' => $inactive]);
        $this->assertSame(CardStatus::Blocked, $card->refresh()->status);
    }

    /** T05: station operators cannot see or change cards (403, not a tenant 404). */
    public function test_operators_cannot_touch_cards(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $this->actingAs($this->operator());

        $this->get('/cards')->assertForbidden();
        $this->get(route('cards.show', $card))->assertForbidden();
        $this->patch(route('cards.limits', $card), ['monthly_limit_l' => '999'])->assertForbidden();
        $this->patch(route('cards.status', $card), ['status' => 'blocked'])->assertForbidden();

        $this->assertSame('100.00', $card->refresh()->monthly_limit_l);
        $this->assertSame(CardStatus::Active, $card->status);
    }

    /** T04: another company's card is not found through any route, filter or search. */
    public function test_managers_cannot_reach_another_companys_cards(): void
    {
        $foreign = $this->card('FF-CEDAR-001');
        $atlasId = $this->atlas()->id;
        $this->actingAs($this->atlasManager());

        $this->get(route('cards.show', $foreign))->assertNotFound();
        $this->get(route('cards.edit', $foreign))->assertNotFound();
        $this->patch(route('cards.limits', $foreign), ['monthly_limit_l' => '1'])->assertNotFound();
        $this->patch(route('cards.status', $foreign), ['status' => 'blocked'])->assertNotFound();
        $this->put(route('cards.update', $foreign), [])->assertNotFound();
        $this->assertSame(CardStatus::Active, $foreign->refresh()->status);

        foreach (['/cards', '/cards?company='.$this->cedar()->id] as $url) {
            $cards = $this->get($url)->assertOk()->viewData('cards');
            $this->assertNotEmpty($cards);
            foreach ($cards as $card) {
                $this->assertSame($atlasId, $card->company_id, $url);
            }
        }

        $this->assertCount(0, $this->get('/cards?q=CEDAR')->viewData('cards'));
    }

    public function test_lists_mask_card_numbers_and_the_card_page_shows_the_full_number(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $this->actingAs($this->atlasManager());

        $this->get('/cards')->assertOk()
            ->assertDontSee('FF-ATLAS-001')
            ->assertSee(Redact::cardNumber('FF-ATLAS-001'));

        $this->get(route('cards.show', $card))->assertOk()->assertSee('FF-ATLAS-001');
    }

    public function test_limits_must_be_plain_decimals(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $this->actingAs($this->atlasManager());

        foreach (['-5', '1e3', '1,000', '12.345', 'abc', '12345678901'] as $bad) {
            $this->patch(route('cards.limits', $card), ['monthly_limit_l' => $bad])->assertSessionHasErrors('monthly_limit_l');
        }
        $this->assertSame('100.00', $card->refresh()->monthly_limit_l);

        // Zero is a real limit (no further spend), not "unlimited".
        $this->patch(route('cards.limits', $card), ['monthly_limit_l' => '0', 'monthly_limit_usd' => '100'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $card->refresh()->monthly_limit_l);
    }

    public function test_card_changes_lock_the_card_row(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'from `fuel_cards`') && str_ends_with(trim($query->sql), 'for update')) {
                $locks[] = $query->sql;
            }
        });

        $this->actingAs($this->atlasManager())
            ->patch(route('cards.limits', $card), ['monthly_limit_l' => '90', 'monthly_limit_usd' => '100'])
            ->assertSessionHasNoErrors();
        $this->patch(route('cards.status', $card), ['status' => 'blocked'])->assertSessionHasNoErrors();

        $this->assertCount(2, $locks, 'each change reads the card with SELECT ... FOR UPDATE');
    }

    public function test_cards_cannot_be_deleted(): void
    {
        $card = $this->card('FF-ATLAS-H01');

        $this->actingAs($this->admin())->delete(route('cards.show', $card))->assertStatus(405);

        $this->assertTrue(FuelCard::query()->whereKey($card->id)->exists());
    }

    public function test_only_admins_see_the_card_audit_history(): void
    {
        $card = $this->card('FF-ATLAS-001');
        app(FuelCardService::class)->changeStatus($card, CardStatus::Blocked, $this->atlasManager());

        $this->actingAs($this->admin())->get(route('cards.show', $card))
            ->assertOk()
            ->assertSee('Audit history')
            ->assertSee('card.status_changed')
            ->assertSee('Atlas Fleet Manager');

        $this->actingAs($this->atlasManager())->get(route('cards.show', $card))
            ->assertOk()
            ->assertDontSee('Audit history');
    }
}
