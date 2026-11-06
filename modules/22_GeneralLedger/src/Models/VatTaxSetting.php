<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * VAT configuration of a tenant: ledger accounts of the settlement, filing period and LIPE declarant.
 */
final class VatTaxSetting extends BaseEnterpriseModel
{
    protected $table = 'vat_tax_settings';

    protected function casts(): array
    {
        return [
            'output_vat_account_ids' => 'array',
            'input_vat_account_ids' => 'array',
        ];
    }
}
