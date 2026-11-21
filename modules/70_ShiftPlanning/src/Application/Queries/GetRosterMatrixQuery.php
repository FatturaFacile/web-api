<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Queries;

/**
 * The roster matrix of a period, grouped by employee ($groupBy = employee) or by required role / skill / workstation
 * ($groupBy = role).
 */
final readonly class GetRosterMatrixQuery
{
    public const string GroupByEmployee = 'employee';

    public const string GroupByRole = 'role';

    public function __construct(
        public string $tenantId,
        public string $periodId,
        public string $groupBy = self::GroupByEmployee,
    ) {}
}
