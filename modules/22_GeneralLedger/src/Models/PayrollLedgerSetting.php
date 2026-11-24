<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Payroll accounts of a tenant: what each role of a payroll run posts to, and per wage type code the exceptions.
 */
final class PayrollLedgerSetting extends BaseEnterpriseModel
{
    protected $table = 'payroll_ledger_settings';

    protected function casts(): array
    {
        return [
            'wage_type_accounts' => 'array',
        ];
    }
}
