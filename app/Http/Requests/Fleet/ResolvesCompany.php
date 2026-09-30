<?php

namespace App\Http\Requests\Fleet;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Who owns a new vehicle, driver or card is decided by the server: an admin
 * must pick an active company, and a manager may not send company_id at all
 * (even their own), because their company comes from their account.
 */
trait ResolvesCompany
{
    /**
     * @param  bool  $jsonInteger  API bodies: accept a JSON integer only, not "3".
     * @return list<mixed>
     */
    protected function companyRules(bool $jsonInteger = false): array
    {
        return $this->actor()->isAdmin()
            ? ['required', $jsonInteger ? 'integer:strict' : 'integer', Rule::exists('companies', 'id')->where('status', CompanyStatus::Active->value)]
            : ['prohibited'];
    }

    /**
     * @return array<string, string>
     */
    protected function companyMessages(): array
    {
        return [
            'company_id.required' => 'Choose a company.',
            'company_id.exists' => 'Choose an active company.',
            'company_id.prohibited' => 'The company is set from your account and cannot be chosen.',
        ];
    }

    /** The owning company, after validation. */
    public function company(): Company
    {
        $user = $this->actor();

        return Company::query()->findOrFail($user->isAdmin() ? $this->integer('company_id') : (int) $user->company_id);
    }

    /** The owning company's ID before validation (used by per-company unique rules). */
    protected function targetCompanyId(): ?int
    {
        $user = $this->actor();

        if (! $user->isAdmin()) {
            return $user->company_id;
        }

        return ctype_digit((string) $this->input('company_id')) ? (int) $this->input('company_id') : null;
    }

    protected function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }
}
