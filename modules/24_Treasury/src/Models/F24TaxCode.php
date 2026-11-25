<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * Reference tributo / causale codes of the Modello F24: global rows (tenant_id null, seeded by
 * TreasuryBankingReferenceSeeder), readable by every company.
 */
final class F24TaxCode extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'f24_tax_codes';

    protected function casts(): array
    {
        return ['requires_installment' => 'boolean', 'is_active' => 'boolean'];
    }
}
