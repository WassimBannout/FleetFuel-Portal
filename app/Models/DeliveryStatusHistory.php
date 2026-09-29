<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('delivery_status_history')]
#[WithoutTimestamps]
class DeliveryStatusHistory extends Model
{
    use AppendOnly;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => DeliveryStatus::class,
            'to_status' => DeliveryStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeliveryOrder, $this> */
    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
