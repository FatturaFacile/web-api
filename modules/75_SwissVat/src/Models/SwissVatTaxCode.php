<?php

declare(strict_types=1);

namespace Modules\SwissVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Effective-dated Swiss VAT tax code mapped to official AFC/ESTV boxes.
 * Global rows (tenant_id = null) represent canonical Swiss statutory rates and are read-only for tenants.
 *
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $rate_type
 * @property string $exemption_type
 * @property string $rate_percent
 * @property string $afc_box
 * @property bool $is_bezugsteuer
 * @property bool $is_input_tax_deductible
 * @property string|null $sss_sector_code
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 */
final class SwissVatTaxCode extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'swiss_vat_tax_codes';

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
            'is_bezugsteuer' => 'boolean',
            'is_input_tax_deductible' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
