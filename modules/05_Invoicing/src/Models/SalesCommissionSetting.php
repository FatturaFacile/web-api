<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The accounts commission accruals post to. There are no defaults: without them a statement waits for the ledger.
 */
final class SalesCommissionSetting extends BaseEnterpriseModel
{
    protected $table = 'sales_commission_settings';
}
