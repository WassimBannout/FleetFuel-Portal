<?php

namespace App\Http\Requests\Audit;

use App\Http\Requests\Concerns\FiltersBusinessDates;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filters of the audit screen: actor (a user, or "system" for the command
 * line), action, entity type, company and optional Beirut dates. Every value
 * is validated against a fixed format or list and then bound; the entity is
 * one of the morph-map aliases, never a class name.
 */
class ListAuditLogsRequest extends FormRequest
{
    use FiltersBusinessDates;

    public const PER_PAGE = 25;

    protected $redirectRoute = 'audit.index';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AuditLog::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->dateRangeRules(),
            'actor' => ['nullable', 'string', 'regex:/^(system|[1-9][0-9]{0,18})$/'],
            'action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z_]+\.[a-z_]+$/'],
            'entity' => ['nullable', 'string', Rule::in(array_keys(AuditDisplay::entityOptions()))],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->dateRangeLimit()];
    }

    /**
     * [from, to) in UTC when both dates are given; null shows every date.
     *
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    public function range(): ?array
    {
        return $this->filterValue('from') !== null && $this->filterValue('to') !== null ? $this->utcRange() : null;
    }

    public function filterValue(string $key): ?string
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }
}
