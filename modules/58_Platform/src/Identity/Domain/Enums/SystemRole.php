<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Enums;

enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Sales = 'sales';
    case SalesManager = 'sales_manager';
    case ProjectManager = 'project_manager';
    case Technician = 'technician';
    case Warehouse = 'warehouse';
    case Hr = 'hr';
    case Member = 'member';
    case Viewer = 'viewer';
    case ExternalAuditor = 'external_auditor';

    /** Privilege rank used by the escalation guard: a higher number means more privileges. */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 100,
            self::Admin => 90,
            self::Accountant, self::Hr, self::SalesManager => 60,
            self::ProjectManager => 50,
            self::Sales, self::Technician, self::Warehouse => 40,
            self::Member => 20,
            self::Viewer, self::ExternalAuditor => 10,
        };
    }

    /** Roles for which two-factor authentication can never be turned off. */
    public function requiresMandatoryMfa(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
