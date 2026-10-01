<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Http\Requests\Reports\ReportRequest;
use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * GET /api/v1/reports/consumption: Beirut dates, group_by (company,
 * vehicle or product; anything else is refused, never put into SQL) and,
 * for admins only, company_id. Unlike the web screen, a manager's
 * company_id is refused (422), as everywhere in the API.
 */
class ConsumptionReportRequest extends ReportRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            ...parent::rules(),
            'company_id' => $user instanceof User && $user->isAdmin() ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [...$this->rejectUnknownFields(), ...parent::after()];
    }
}
