<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Analytical data mart table `fact_financial_transactions` (Mod 093). Rebuilt from the operational data by the projectors, never edited by hand.
 */
final class FactFinancialTransaction extends BaseEnterpriseModel
{
    protected $table = 'fact_financial_transactions';

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'amount_transaction' => 'decimal:4', 'amount_functional' => 'decimal:4', 'amount_chf' => 'decimal:4', 'amount_eur' => 'decimal:4'];
    }
}
