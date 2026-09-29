<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\ProductPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point on a product's LBP-per-liter price timeline. Immutable: a new
 * price is a new row, and old purchases keep the price they snapshotted.
 */
class ProductPrice extends Model
{
    use AppendOnly;

    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_lbp' => 'decimal:4',
            'effective_from' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
