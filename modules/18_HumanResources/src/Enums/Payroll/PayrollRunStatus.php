<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums\Payroll;

/**
 * Lifecycle of a Swiss payroll run: draft -> calculated -> approved -> closed. Closed runs are immutable.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Closed = 'closed';

    public function canBeCalculated(): bool
    {
        return $this === self::Draft || $this === self::Calculated;
    }

    public function canBeEdited(): bool
    {
        return $this === self::Draft || $this === self::Calculated;
    }

    public function canBeApproved(): bool
    {
        return $this === self::Calculated;
    }

    public function canBeClosed(): bool
    {
        return $this === self::Approved;
    }

    public function isDeclarable(): bool
    {
        return $this === self::Approved || $this === self::Closed;
    }
}
