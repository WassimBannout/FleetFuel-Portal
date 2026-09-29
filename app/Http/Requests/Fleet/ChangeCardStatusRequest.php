<?php

namespace App\Http\Requests\Fleet;

use App\Enums\CardStatus;
use App\Models\FuelCard;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Block, unblock or archive a card (archiving is final). */
class ChangeCardStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $card = $this->route('card');

        return $user instanceof User && $card instanceof FuelCard && $user->can('update', $card);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(CardStatus::class)],
        ];
    }

    public function status(): CardStatus
    {
        return CardStatus::from((string) $this->validated('status'));
    }
}
