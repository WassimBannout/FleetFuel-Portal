<?php

namespace Tests\Feature\Database;

use App\Enums\DeliveryStatus;
use App\Models\AuditLog;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\ExchangeRate;
use App\Models\ProductPrice;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\LedgerFixtureBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\TestCase;

class AppendOnlyModelsTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;

    public function test_history_rows_cannot_be_updated_or_deleted_through_eloquent(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $world = $this->ledgerWorld();
        $builder = app(LedgerFixtureBuilder::class);

        $purchase = $builder->recordPurchase(
            $world['card'], $world['station'], $world['operator'], $world['diesel'],
            '20.00', CarbonImmutable::now()->subHour(), 'POS-TEST-0001',
        );
        $order = DeliveryOrder::factory()->create(['company_id' => $world['company']->id]);
        $builder->transitionDelivery($order, DeliveryStatus::Cancelled, $world['admin'], CarbonImmutable::now(), [
            'cancel_reason' => 'Test cancellation',
        ]);

        /** @var array<string, array{Model, string, string}> $rows */
        $rows = [
            'fuel transaction' => [$purchase, 'liters', '1.00'],
            'product price' => [ProductPrice::query()->firstOrFail(), 'price_lbp', '1.0000'],
            'exchange rate' => [ExchangeRate::query()->firstOrFail(), 'rate', '1.00000000'],
            'delivery history' => [DeliveryStatusHistory::query()->firstOrFail(), 'note', 'edited'],
            'audit log' => [AuditLog::query()->firstOrFail(), 'action', 'edited'],
        ];

        foreach ($rows as $label => [$model, $attribute, $value]) {
            $stored = $model->getRawOriginal($attribute);

            try {
                $model->forceFill([$attribute => $value])->save();
                $this->fail("Updating a {$label} was allowed.");
            } catch (LogicException) {
                // Expected: append-only.
            }

            try {
                $model->delete();
                $this->fail("Deleting a {$label} was allowed.");
            } catch (LogicException) {
                // Expected: append-only.
            }

            $this->assertSame($stored, $model->newQuery()->whereKey($model->getKey())->value($attribute), "The {$label} changed.");
        }
    }
}
