<?php

namespace Tests\Feature\Api;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Models\AuditLog;
use App\Models\FuelCard;
use App\Services\FuelCardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CallsApi;
use Tests\Concerns\ChecksOpenApiContract;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\Concerns\SubmitsPosRequests;
use Tests\TestCase;

/**
 * PATCH /api/v1/cards/{id} on the demo seed: quotas and block/unblock
 * through FuelCardService (card lock + audit, T05/T08), tenant isolation
 * (T04) and the documented error shapes (T24).
 */
class CardPatchApiTest extends TestCase
{
    use BuildsLedgerFixtures;
    use CallsApi;
    use ChecksOpenApiContract;
    use RefreshDatabase;
    use SignsInDemoAccounts;
    use SubmitsPosRequests;

    /** The last audit row written by the demo seed. */
    private int $seededAuditId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
        $this->seededAuditId = (int) AuditLog::query()->max('id');
    }

    public function test_a_manager_lowers_a_limit_below_this_months_usage_and_it_is_audited(): void
    {
        $card = $this->card('FF-ATLAS-H01');
        $this->assertSame('170.00', app(FuelCardService::class)->balance($card)->usedL, 'fixture: usage this month');

        $response = $this->patchCard($card->id, ['monthly_limit_l' => '100.00'], $this->apiToken($this->atlasManager()))->assertOk();

        $response->assertExactJson(['data' => [
            'id' => $card->id,
            'company_id' => $this->atlas()->id,
            'vehicle_id' => $card->vehicle_id,
            'driver_id' => $card->driver_id,
            'card_no' => 'FF-ATLAS-H01',
            'allowed_product_id' => $card->allowed_product_id,
            'monthly_limit_l' => '100.00',
            'monthly_limit_usd' => '400.00',
            'status' => 'active',
        ]]);

        $audit = AuditLog::query()->where('action', 'card.limits_changed')->sole();
        $this->assertSame($this->atlasManager()->id, $audit->user_id);
        $this->assertEquals(['monthly_limit_l' => '400.00', 'monthly_limit_usd' => '400.00'], $audit->old_values);
        $this->assertEquals(['monthly_limit_l' => '100.00', 'monthly_limit_usd' => '400.00', 'below_current_usage' => true], $audit->new_values);

        $this->api('GET', '/api/v1/cards/FF-ATLAS-H01/balance', $this->apiToken($this->atlasManager()))
            ->assertJsonPath('data.remaining_l', '0.00')
            ->assertJsonPath('data.over_quota', true);
    }

    public function test_null_means_unlimited_and_a_block_stops_purchases_until_unblocked(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $manager = $this->apiToken($this->atlasManager());
        $operator = $this->posToken($this->operator());

        $this->patchCard($card->id, ['monthly_limit_usd' => null, 'status' => 'blocked'], $manager)
            ->assertOk()
            ->assertJsonPath('data.monthly_limit_l', '100.00')
            ->assertJsonPath('data.monthly_limit_usd', null)
            ->assertJsonPath('data.status', 'blocked');
        $this->assertSame(['card.limits_changed', 'card.status_changed'], $this->auditActions($card));

        $this->submitPurchase($operator, $this->posPayload('FF-ATLAS-001', ['external_ref' => 'PATCH-1']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'card_blocked');

        $this->patchCard($card->id, ['status' => 'active'], $manager)->assertOk()->assertJsonPath('data.status', 'active');
        $this->submitPurchase($operator, $this->posPayload('FF-ATLAS-001', ['external_ref' => 'PATCH-2']))->assertCreated();
    }

    public function test_an_unchanged_value_writes_no_audit_row(): void
    {
        $card = $this->card('FF-ATLAS-001');

        $this->patchCard($card->id, ['monthly_limit_l' => '100', 'status' => 'active'], $this->apiToken($this->atlasManager()))
            ->assertOk()
            ->assertJsonPath('data.monthly_limit_l', '100.00');

        $this->assertSame([], $this->auditActions($card));
    }

    public function test_the_body_must_be_a_nonempty_subset_of_limits_and_status(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $manager = $this->apiToken($this->atlasManager());

        $this->patchCard($card->id, [], $manager)->assertUnprocessable()->assertJsonPath('error.details.body.0', 'Send at least one of monthly_limit_l, monthly_limit_usd or status.');

        $refused = [
            ['company_id' => $this->cedar()->id],
            ['vehicle_id' => null],
            ['card_no' => 'FF-NEW-0001'],
            ['allowed_product_id' => null],
            ['status' => 'archived'],
            ['status' => null],
            ['monthly_limit_l' => 100],
            ['monthly_limit_l' => '1e3'],
            ['monthly_limit_l' => '-5.00'],
            ['monthly_limit_l' => '10.005'],
            ['monthly_limit_l' => '12345678901.00'],
            ['monthly_limit_usd' => '12345678901234567.00'],
        ];

        foreach ($refused as $body) {
            $this->patchCard($card->id, $body, $manager)->assertUnprocessable()->assertJsonValidationErrors(array_keys($body), 'error.details');
        }

        $card->refresh();
        $this->assertSame(['100.00', '100.00', CardStatus::Active], [$card->monthly_limit_l, $card->monthly_limit_usd, $card->status]);
        $this->assertSame([], $this->auditActions($card));
    }

    public function test_managers_reach_only_their_own_cards_and_operators_none(): void
    {
        $cedarCard = $this->card('FF-CEDAR-001');
        $body = ['status' => 'blocked'];

        $this->patchCard($cedarCard->id, $body, $this->apiToken($this->atlasManager()))->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->patchCard($cedarCard->id, $body, $this->apiToken($this->operator()))->assertForbidden();
        $this->patchCard($cedarCard->id, $body, $this->apiToken($this->cedarManager(), ['cards:read']))->assertForbidden();
        $this->patchCard($cedarCard->id, $body, null)->assertUnauthorized();
        $this->patchCard(999999, $body, $this->apiToken($this->admin()))->assertNotFound();
        $this->assertSame(CardStatus::Active, $cedarCard->refresh()->status);

        $this->patchCard($cedarCard->id, $body, $this->apiToken($this->admin()))->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->patchCard($cedarCard->id, ['status' => 'active'], $this->apiToken($this->cedarManager()))->assertOk();
    }

    public function test_an_archived_card_is_final(): void
    {
        $card = app(FuelCardService::class)->changeStatus($this->card('FF-ATLAS-H02'), CardStatus::Archived, $this->admin());
        $limit = $card->monthly_limit_l;

        $this->patchCard($card->id, ['status' => 'active'], $this->apiToken($this->atlasManager()))
            ->assertConflict()
            ->assertJsonPath('error.code', 'invalid_transition');
        $this->patchCard($card->id, ['monthly_limit_l' => '10.00'], $this->apiToken($this->admin()))->assertConflict();

        $card->refresh();
        $this->assertSame([CardStatus::Archived, $limit], [$card->status, $card->monthly_limit_l]);
        $this->assertSame(['card.status_changed'], $this->auditActions($card));
    }

    public function test_limits_and_status_are_applied_together_or_not_at_all(): void
    {
        $card = $this->card('FF-ATLAS-001');
        $this->atlas()->forceFill(['status' => CompanyStatus::Inactive])->save();
        $manager = $this->apiToken($this->atlasManager());

        // An inactive company's limits are read-only, so the block is not applied either.
        $this->patchCard($card->id, ['status' => 'blocked', 'monthly_limit_l' => '50.00'], $manager)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'company_inactive');
        $card->refresh();
        $this->assertSame([CardStatus::Active, '100.00'], [$card->status, $card->monthly_limit_l]);
        $this->assertSame([], $this->auditActions($card));

        // Blocking alone is always allowed.
        $this->patchCard($card->id, ['status' => 'blocked'], $manager)->assertOk()->assertJsonPath('data.status', 'blocked');
    }

    public function test_a_failure_after_the_limits_are_saved_rolls_the_limits_back(): void
    {
        $card = $this->card('FF-ATLAS-001');
        // The status step runs after the limits were written; make it fail.
        FuelCard::updating(function (FuelCard $updated): void {
            if ($updated->isDirty('status')) {
                throw new RuntimeException('Forced failure while saving the status.');
            }
        });

        $this->patchCard($card->id, ['monthly_limit_l' => '50.00', 'status' => 'blocked'], $this->apiToken($this->atlasManager()))
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error');

        $card->refresh();
        $this->assertSame(['100.00', CardStatus::Active], [$card->monthly_limit_l, $card->status]);
        $this->assertSame([], $this->auditActions($card));
    }

    /**
     * Audit actions written for this card since the demo seed, oldest first.
     *
     * @return list<string>
     */
    private function auditActions(FuelCard $card): array
    {
        return AuditLog::query()
            ->where('id', '>', $this->seededAuditId)
            ->where('auditable_type', 'fuel_card')
            ->where('auditable_id', $card->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function patchCard(int $id, array $body, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('PATCH', "/api/v1/cards/{$id}", $token, $body), 'PATCH', '/cards/{id}');
    }
}
