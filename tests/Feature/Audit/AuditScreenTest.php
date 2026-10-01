<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CountsQueries;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The admin audit screen (F10, docs/06-UI-SPEC.md "Audit"): filters by actor,
 * action, record type, company and date; values redacted and escaped; no
 * access for other roles.
 */
class AuditScreenTest extends TestCase
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

    public function test_only_admins_open_the_audit_log(): void
    {
        $page = $this->actingAs($this->admin())->get('/audit')->assertOk();
        $this->assertSame(AuditLog::query()->count(), $page->viewData('entries')->total());
        $this->assertGreaterThan(0, $page->viewData('entries')->total());
        $page->assertSee('href="'.route('audit.index').'"', false);

        foreach ([$this->atlasManager(), $this->operator()] as $user) {
            $this->actingAs($user)->get('/audit')->assertForbidden();
            $this->get('/stations')->assertDontSee('href="'.route('audit.index').'"', false);
        }
    }

    public function test_each_filter_narrows_the_entries(): void
    {
        $admin = $this->admin();
        $card = $this->card('FF-ATLAS-001');
        app(AuditService::class)->record('card.status_changed', $card, null, $card->company_id, ['status' => 'active'], ['status' => 'blocked']);
        $this->actingAs($admin);
        $cedarId = $this->cedar()->id;

        $this->assertOnly('?action=delivery_order.status_changed', fn (AuditLog $entry) => $entry->action === 'delivery_order.status_changed');
        $this->assertOnly('?entity=fuel_card', fn (AuditLog $entry) => $entry->auditable_type === 'fuel_card');
        $this->assertOnly('?actor=system', fn (AuditLog $entry) => $entry->user_id === null);
        $this->assertOnly('?actor='.$admin->id, fn (AuditLog $entry) => $entry->user_id === $admin->id);
        $this->assertOnly('?company_id='.$cedarId, fn (AuditLog $entry) => $entry->company_id === $cedarId);
        // A Beirut calendar day, not a UTC one.
        $this->assertOnly('?from=2026-09-28&to=2026-09-29',
            fn (AuditLog $entry) => $entry->created_at?->setTimezone('Asia/Beirut')->format('Y-m-d') === '2026-09-28');

        $this->get('/audit?actor=system')->assertSee('Command line / system')->assertSee('Card status changed');
    }

    public function test_values_are_redacted_and_escaped(): void
    {
        $card = $this->card('FF-ATLAS-001');
        app(AuditService::class)->record('card.status_changed', $card, $this->admin(), $card->company_id,
            ['password' => 'hunter2-not-shown', 'remember_token' => 'tok-not-shown', 'card_no' => 'FF-ATLAS-001'],
            ['tokens_revoked' => 2, 'reason' => '<script>alert("x")</script>', 'is_active' => false],
        );

        $this->actingAs($this->admin())->get('/audit?entity=fuel_card&actor='.$this->admin()->id)->assertOk()
            ->assertDontSee('hunter2-not-shown')->assertDontSee('tok-not-shown')->assertDontSee('FF-ATLAS-001')
            ->assertSee('[redacted]')->assertSee('••••-001')
            ->assertSeeInOrder(['tokens_revoked', '—', '2'])
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert', false)
            ->assertSee('href="'.route('cards.show', $card->id).'"', false);
    }

    public function test_filter_values_are_checked_against_fixed_lists(): void
    {
        $this->actingAs($this->admin());

        $this->get('/audit?entity='.urlencode('App\Models\User'))->assertRedirect('/audit')->assertSessionHasErrors('entity');
        $this->get("/audit?action=x'%20OR%201=1")->assertRedirect('/audit')->assertSessionHasErrors('action');
        $this->get('/audit?actor=admin')->assertRedirect('/audit')->assertSessionHasErrors('actor');
    }

    /** N+1 check: actors and companies are loaded once per page, not once per row. */
    public function test_the_audit_log_runs_a_fixed_number_of_queries(): void
    {
        $admin = $this->admin();
        $before = $this->queriesFor($admin, '/audit');

        $audit = app(AuditService::class);
        foreach (['FF-ATLAS-001', 'FF-ATLAS-TINY', 'FF-CEDAR-001'] as $number) {
            $card = $this->card($number);
            foreach ([$admin, $this->atlasManager(), $this->cedarManager(), null] as $actor) {
                $audit->record('card.limits_changed', $card, $actor, $card->company_id, ['monthly_limit_l' => '100.00'], ['monthly_limit_l' => '90.00']);
            }
        }

        $this->assertSame($before, $this->queriesFor($admin, '/audit'));
    }

    /**
     * @param  callable(AuditLog): bool  $matches
     */
    private function assertOnly(string $query, callable $matches): void
    {
        $entries = $this->get('/audit'.$query)->assertOk()->viewData('entries');

        $this->assertGreaterThan(0, $entries->total(), "{$query} finds something");
        $this->assertLessThan(AuditLog::query()->count(), $entries->total(), "{$query} leaves something out");
        foreach ($entries as $entry) {
            $this->assertTrue($matches($entry), "{$query} returned entry {$entry->id} ({$entry->action})");
        }
    }
}
