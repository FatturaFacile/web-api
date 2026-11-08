<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Ledger policy of a tenant: whether manual journals need the approval of a second person, and from which amount.
 */
final class LedgerSetting extends BaseEnterpriseModel
{
    protected $table = 'gl_ledger_settings';

    protected function casts(): array
    {
        return [
            'manual_journal_approval_required' => 'boolean',
            'manual_journal_approval_threshold' => 'decimal:4',
        ];
    }
}
