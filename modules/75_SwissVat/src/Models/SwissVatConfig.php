<?php

declare(strict_types=1);

namespace Modules\SwissVat\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Tenant-level Swiss VAT (MWST / IVA / TVA) configuration.
 *
 * @property string|null $uid_mwst
 * @property string $accounting_method
 * @property string $consideration_basis
 * @property string $reporting_frequency
 * @property string|null $sss_rate_primary
 * @property string|null $sss_rate_secondary
 * @property string|null $sss_sector_primary
 * @property string|null $sss_sector_secondary
 * @property string $rounding_mode
 * @property bool $auto_detect_bezugsteuer
 * @property string $bezugsteuer_threshold_chf
 * @property string $default_currency
 * @property string|null $canton
 * @property array<string, string>|null $gl_accounts
 */
final class SwissVatConfig extends BaseEnterpriseModel
{
    protected $table = 'swiss_vat_configs';

    protected function casts(): array
    {
        return [
            'sss_rate_primary' => 'decimal:2',
            'sss_rate_secondary' => 'decimal:2',
            'auto_detect_bezugsteuer' => 'boolean',
            'bezugsteuer_threshold_chf' => 'decimal:2',
            'gl_accounts' => 'array',
        ];
    }
}
