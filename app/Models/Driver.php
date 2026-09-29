<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'phone', 'license_no'])]
class Driver extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<DriverFactory> */
    use HasFactory;

    /** License numbers are compared and stored trimmed and uppercase. */
    public static function normalizeLicense(string $license): string
    {
        return Str::upper(trim($license));
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<FuelCard, $this> */
    public function fuelCards(): HasMany
    {
        return $this->hasMany(FuelCard::class);
    }
}
