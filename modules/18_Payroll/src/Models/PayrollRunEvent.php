<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use LogicException;

/**
 * The history of a run: who calculated, closed and reopened it, and why. Append-only.
 */
final class PayrollRunEvent extends PayrollRecord
{
    public const string Calculated = 'Calculated';

    public const string Approved = 'Approved';

    public const string ApprovalRevoked = 'ApprovalRevoked';

    public const string Finalized = 'Finalized';

    public const string Reopened = 'Reopened';

    public const UPDATED_AT = null;

    protected $table = 'payroll_run_events';

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('The history of a payroll run cannot be updated.'));
        self::deleting(static fn () => throw new LogicException('The history of a payroll run cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['totals' => 'array', 'occurred_at' => 'datetime'];
    }
}
