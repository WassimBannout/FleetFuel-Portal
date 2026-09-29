<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductCode;
use App\Exceptions\PurchaseDeclined;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\FuelTransaction;
use App\Models\Station;
use App\Models\User;
use App\Rules\DecimalString;
use App\Rules\OffsetTimestamp;
use App\Support\PosPurchase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * POST /api/v1/transactions, steps 1–2 of the POS algorithm
 * (docs/04-BUSINESS-RULES.md):
 *
 * 1. An active station operator of an active station. The station is the
 *    operator's own; a station_id in the payload is refused like any other
 *    unknown field, as are amounts, prices, rates and company_id.
 * 2. Structural validation only: types, formats and scale. Rules that can
 *    change over time (card status, quota, price, the 72-hour window) are
 *    checked after the replay lookup, in FuelTransactionService.
 */
class StorePosTransactionRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! $user->can('create', FuelTransaction::class)) {
            return false;
        }

        if (! Station::query()->whereKey($user->station_id)->where('is_active', true)->exists()) {
            throw PurchaseDeclined::stationInactive();
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_ref' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/'],
            'card_no' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'product_code' => ['required', 'string', Rule::enum(ProductCode::class)],
            // A JSON string such as "20.00"; a JSON number is refused, so no float is ever involved.
            'liters' => ['required', new DecimalString(8, 2, allowZero: false)],
            'transacted_at' => ['required', new OffsetTimestamp],
            // A JSON integer or null; "45000" as a string is refused.
            'odometer_km' => ['nullable', 'integer:strict', 'min:0', 'max:9999999999'],
        ];
    }

    public function purchase(): PosPurchase
    {
        $odometer = $this->validated('odometer_km');

        return PosPurchase::normalized(
            (string) $this->validated('external_ref'),
            (string) $this->validated('card_no'),
            (string) $this->validated('product_code'),
            (string) $this->validated('liters'),
            OffsetTimestamp::parse($this->validated('transacted_at')) ?? throw new LogicException('transacted_at was validated.'),
            is_int($odometer) ? $odometer : null,
        );
    }
}
