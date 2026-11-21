<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Queries;

use App\Models\User;
use Modules\KpiDashboards\Domain\Enums\RoleCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;

/**
 * Everything a cockpit shows for one role (or one saved dashboard) under the global filters.
 */
final readonly class GetExecutiveKpiCockpitQuery
{
    public function __construct(
        public ?User $user,
        public CockpitFilter $filter,
        public ?RoleCode $role = null,
        public ?string $dashboardId = null,
    ) {}
}
