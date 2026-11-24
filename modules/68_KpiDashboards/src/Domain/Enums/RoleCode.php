<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Domain\Enums;

/**
 * The role cockpit a dashboard belongs to.
 */
enum RoleCode: string
{
    case Ceo = 'ceo';
    case Cfo = 'cfo';
    case Sales = 'sales';
    case Operations = 'operations';
    case Hr = 'hr';
    case Custom = 'custom';

    /**
     * The roles of the tenant membership that may open the cockpit; a membership permission `kpi.cockpit.<role>` also
     * opens it. Owners and admins open every cockpit.
     *
     * @return list<string>
     */
    public function allowedMembershipRoles(): array
    {
        return match ($this) {
            self::Ceo => ['owner', 'admin'],
            self::Cfo => ['owner', 'admin', 'accountant', 'accountant_readonly'],
            self::Sales => ['owner', 'admin', 'sales', 'sales_manager'],
            self::Operations => ['owner', 'admin', 'production', 'procurement_manager', 'service_manager'],
            self::Hr => ['owner', 'admin', 'hr_manager'],
            self::Custom => ['*'],
        };
    }
}
