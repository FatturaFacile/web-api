<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Annual VAT reconciliation of a year across its periodic settlements and the Erario c/IVA account.
 *
 * @property int $year
 * @property string $status
 * @property array<int, array<string, mixed>> $periods
 * @property string $total_output_vat
 * @property string $total_input_vat
 * @property int $pro_rata_percentage
 * @property string $pro_rata_adjustment
 * @property string $prior_year_credit
 * @property string $interest_total
 * @property string $paid_total
 * @property string $annual_debit
 * @property string $annual_credit
 * @property string $ledger_balance
 * @property string $difference
 * @property bool $is_reconciled
 * @property array<int, array<string, mixed>>|null $diagnostics
 * @property CarbonImmutable|null $finalized_at
 */
final class AnnualVatReconciliation extends BaseEnterpriseModel
{
    protected $table = 'it_annual_vat_reconciliations';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'periods' => 'array',
            'total_output_vat' => 'decimal:2',
            'total_input_vat' => 'decimal:2',
            'pro_rata_percentage' => 'integer',
            'pro_rata_adjustment' => 'decimal:2',
            'prior_year_credit' => 'decimal:2',
            'interest_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'annual_debit' => 'decimal:2',
            'annual_credit' => 'decimal:2',
            'ledger_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'is_reconciled' => 'boolean',
            'diagnostics' => 'array',
            'finalized_at' => 'immutable_datetime',
        ];
    }
}
