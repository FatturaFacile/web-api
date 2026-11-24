<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Queries;

use Carbon\CarbonImmutable;

/**
 * Billable utilization (target against actual) by resource, department or cost centre over a window.
 */
final readonly class GetBillableUtilizationReportQuery
{
    public const string ByResource = 'resource';

    public const string ByDepartment = 'department';

    public const string ByCostCenter = 'cost_center';

    public function __construct(
        public string $tenantId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $groupBy = self::ByResource,
    ) {}
}
