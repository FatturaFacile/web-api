<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * State of a payroll run towards the ledger. It keeps what the closing event said, so a run that could not be posted
 * can be posted later without touching the payroll.
 */
final class PayrollLedgerPosting extends BaseEnterpriseModel
{
    public const string Pending = 'pending';

    public const string Posted = 'posted';

    /** Reopened after posting, and the reversal could not be booked yet. */
    public const string ReversalPending = 'reversal_pending';

    public const string Reversed = 'reversed';

    /** Reopened before it ever reached the ledger. */
    public const string Withdrawn = 'withdrawn';

    protected $table = 'payroll_ledger_postings';

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'payload' => 'array',
        ];
    }
}
