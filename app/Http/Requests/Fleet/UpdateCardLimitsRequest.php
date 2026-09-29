<?php

namespace App\Http\Requests\Fleet;

use App\Models\FuelCard;
use App\Models\User;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Monthly liter and USD limits. Empty means unlimited; "0" means no further
 * spend. confirm_below_usage is the tick box shown after the warning that a
 * new limit is below this month's usage.
 */
class UpdateCardLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('update', $this->card());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'monthly_limit_l' => ['nullable', new DecimalString(10)],
            'monthly_limit_usd' => ['nullable', new DecimalString(16)],
            'confirm_below_usage' => ['nullable', 'boolean'],
        ];
    }

    public function card(): FuelCard
    {
        $card = $this->route('card');
        assert($card instanceof FuelCard);

        return $card;
    }

    public function limitL(): ?string
    {
        $value = $this->validated('monthly_limit_l');

        return is_string($value) ? $value : null;
    }

    public function limitUsd(): ?string
    {
        $value = $this->validated('monthly_limit_usd');

        return is_string($value) ? $value : null;
    }
}
