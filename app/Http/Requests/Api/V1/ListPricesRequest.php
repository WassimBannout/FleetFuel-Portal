<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\ProductPrice;
use App\Rules\OffsetTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /api/v1/products/prices?at=: the instant defaults to now. It must
 * carry an offset like every API timestamp, and lie within the last 366
 * days (the API's query horizon). A future instant is refused: prices
 * scheduled for later are on the price timeline, and no exchange rate
 * observed today can honestly convert them.
 */
class ListPricesRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    private const HORIZON_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ProductPrice::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'at' => ['nullable', new OffsetTimestamp],
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
                if ($validator->errors()->has('at') || ($at = OffsetTimestamp::parse($this->input('at'))) === null) {
                    return;
                }

                $now = CarbonImmutable::now();

                if ($at->isAfter($now)) {
                    $validator->errors()->add('at', 'The instant cannot be in the future.');
                } elseif ($at->isBefore($now->subDays(self::HORIZON_DAYS))) {
                    $validator->errors()->add('at', 'The instant may be at most '.self::HORIZON_DAYS.' days ago.');
                }
            },
        ];
    }

    /** The requested instant in UTC, or now. */
    public function instant(): CarbonImmutable
    {
        return OffsetTimestamp::parse($this->validated('at')) ?? CarbonImmutable::now()->startOfSecond();
    }
}
