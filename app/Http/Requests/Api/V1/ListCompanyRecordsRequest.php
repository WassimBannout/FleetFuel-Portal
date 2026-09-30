<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lists of one company's records (vehicles, drivers). Only an admin may
 * filter by company; a manager's company comes from their account, so
 * company_id is refused even when it names their own company.
 */
abstract class ListCompanyRecordsRequest extends PaginatedListRequest
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', $this->model()) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'company_id' => $user instanceof User && $user->isAdmin() ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            ...$this->paginationRules(),
        ];
    }

    public function companyFilter(): ?int
    {
        $company = $this->validated('company_id');

        return $company === null ? null : (int) $company;
    }
}
