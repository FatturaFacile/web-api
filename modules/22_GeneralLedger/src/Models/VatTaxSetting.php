<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * VAT configuration of a tenant: ledger accounts of the settlement, filing period and LIPE declarant.
 *
 * @property string|null $periodicity
 * @property string|null $regime
 * @property string|null $sss_rate_1
 * @property string|null $sss_rate_2
 * @property array<int|string, mixed>|null $flat_tax_rates
 * @property string|null $flat_rate_adjustment_account_id
 * @property string|null $acquisition_tax_account_id
 * @property array<int, string>|null $output_vat_account_ids
 * @property array<int, string>|null $input_vat_account_ids
 * @property string|null $settlement_account_id
 * @property string|null $interest_expense_account_id
 * @property string|null $declarant_tax_code
 * @property string|null $declarant_role_code
 * @property bool $carry_over_below_minimum Italian debt not above the minimum payment moves to the next period (default) or is paid
 */
final class VatTaxSetting extends BaseEnterpriseModel
{
    public const string REGIME_EFFECTIVE = 'effective';

    public const string REGIME_NET_TAX_RATE = 'net_tax_rate';

    public const string REGIME_FLAT_TAX_RATE = 'flat_tax_rate';

    protected $table = 'vat_tax_settings';

    protected function casts(): array
    {
        return [
            'output_vat_account_ids' => 'array',
            'input_vat_account_ids' => 'array',
            'sss_rate_1' => 'decimal:2',
            'sss_rate_2' => 'decimal:2',
            'flat_tax_rates' => 'array',
            'carry_over_below_minimum' => 'boolean',
        ];
    }
}
