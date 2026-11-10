<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * VAT configuration of a tenant: ledger accounts of the settlement, filing period and LIPE declarant.
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
        ];
    }
}
