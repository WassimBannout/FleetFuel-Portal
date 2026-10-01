<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Concerns\FiltersBusinessDates;
use App\Models\User;
use App\Support\ReportScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Filters of the report screens: Beirut dates (default the current month),
 * an admin's company and the consumption grouping. As on the other list
 * screens, a manager's ?company_id= is ignored and can never widen what
 * they see: ReportScope pins them to their own company.
 */
class ReportRequest extends FormRequest
{
    use FiltersBusinessDates;

    public const GROUPINGS = ['company', 'vehicle', 'product'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewReports') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->dateRangeRules(),
            'company_id' => ['nullable', 'integer', 'min:1'],
            'group_by' => ['nullable', 'string', 'in:'.implode(',', self::GROUPINGS)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->dateRangeLimit()];
    }

    public function scope(): ReportScope
    {
        [$from, $to] = $this->utcRange();
        $company = $this->validated('company_id');

        return ReportScope::for($this->actor(), $from, $to, $company === null ? null : (int) $company);
    }

    public function groupBy(): string
    {
        return (string) ($this->validated('group_by') ?? 'company');
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }
}
