<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Which rows a report may read: a half-open UTC range [from, to) and the
 * company the rows must belong to. A manager is always pinned to their own
 * company, whatever the request says; an admin sees every company unless
 * they filter by one. Station operators get no reports.
 */
final readonly class ReportScope
{
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $companyId,
    ) {}

    /**
     * A scope chosen on the command line (reports:explain), not by a
     * signed-in user. Read-only diagnostics only.
     */
    public static function forConsole(CarbonImmutable $from, CarbonImmutable $to, ?int $companyId): self
    {
        return new self($from, $to, $companyId);
    }

    public static function for(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $adminCompanyFilter = null): self
    {
        return match ($user->role) {
            UserRole::Admin => new self($from, $to, $adminCompanyFilter),
            UserRole::CompanyManager => new self($from, $to, (int) $user->company_id),
            UserRole::StationOperator => throw new LogicException('Reports are for admins and company managers.'),
        };
    }
}
