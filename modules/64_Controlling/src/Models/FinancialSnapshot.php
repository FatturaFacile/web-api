<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Controlling record of the table bi_financial_snapshots.
 *
 * @property Carbon $snapshot_date
 * @property int $fiscal_year
 * @property int $fiscal_month
 * @property string $accounts_receivable_balance
 * @property string $overdue_receivables_balance
 * @property string $accounts_payable_balance
 * @property string $inventory_valuation_balance
 * @property string $cash_and_bank_balance
 * @property string $net_working_capital
 * @property string $credit_sales_period
 * @property string $credit_purchases_period
 * @property string $cogs_period
 */
final class FinancialSnapshot extends BaseEnterpriseModel
{
    protected $table = 'bi_financial_snapshots';

    protected function casts(): array
    {
        return ['snapshot_date' => 'date'];
    }
}
