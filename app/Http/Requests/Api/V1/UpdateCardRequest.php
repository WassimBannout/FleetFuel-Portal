<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CardStatus;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\FuelCard;
use App\Models\User;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/v1/cards/{id}: a nonempty subset of the two monthly limits
 * (decimal strings, or null for unlimited) and the status (active or
 * blocked). Everything else, such as the company, the assignment or the
 * card number, is refused as an unknown field. Archiving stays a web
 * action because it is final.
 */
class UpdateCardRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    private const FIELDS = ['monthly_limit_l', 'monthly_limit_usd', 'status'];

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
            // Same bounds as the columns: DECIMAL(12,2) and DECIMAL(18,2).
            'monthly_limit_l' => ['sometimes', 'nullable', new DecimalString(10)],
            'monthly_limit_usd' => ['sometimes', 'nullable', new DecimalString(16)],
            'status' => ['sometimes', 'string', Rule::in([CardStatus::Active->value, CardStatus::Blocked->value])],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...$this->rejectUnknownFields(),
            function (Validator $validator): void {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('body', 'Send at least one of monthly_limit_l, monthly_limit_usd or status.');
                }
            },
        ];
    }

    public function card(): FuelCard
    {
        $card = $this->route('card');
        assert($card instanceof FuelCard);

        return $card;
    }

    /**
     * Only the fields that were sent; a limit sent as null means unlimited.
     *
     * @return array{monthly_limit_l?: string|null, monthly_limit_usd?: string|null, status?: CardStatus}
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];

        foreach (['monthly_limit_l', 'monthly_limit_usd'] as $limit) {
            if (array_key_exists($limit, $validated)) {
                $changes[$limit] = is_string($validated[$limit]) ? $validated[$limit] : null;
            }
        }

        if (isset($validated['status'])) {
            $changes['status'] = CardStatus::from((string) $validated['status']);
        }

        return $changes;
    }
}
