<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The balance of one account of one member in a run: `local_net` (debit minus credit in the accounting currency of the
 * member) and `group_net` (the same translated into the group currency at `rate`, chosen by `rate_basis`: closing,
 * average or historical). Rows are written once, together with the run.
 *
 * @property string $account_code
 * @property string $account_type
 * @property string $member_tenant_id
 * @property string $local_net
 * @property string $group_net
 */
final class ConsolidationRunBalance extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_run_balances';

    protected function casts(): array
    {
        return [
            'ownership_percentage' => 'decimal:4',
            'local_net' => 'decimal:4',
            'rate' => 'decimal:6',
            'group_net' => 'decimal:4',
        ];
    }
}
