<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\RequestId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Appends one audit row for a sensitive change. Callers pass explicitly
 * selected business fields; passwords, tokens and raw requests never go in.
 * Call it inside the same database transaction as the change itself.
 */
class AuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  CarbonInterface|null  $at  When the change happened, for seeded history only; live changes use now.
     */
    public function record(
        string $action,
        Model $auditable,
        ?User $actor,
        ?int $companyId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?CarbonInterface $at = null,
    ): AuditLog {
        return AuditLog::query()->forceCreate([
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'company_id' => $companyId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'request_id' => RequestId::current(),
        ] + ($at === null ? [] : ['created_at' => $at]));
    }
}
