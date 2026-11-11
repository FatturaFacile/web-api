<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A row of an ESTV withholding tax tariff.
 */
final class PayrollWithholdingTaxTariff extends PayrollRecord
{
    use AllowsGlobalRecords, BelongsToTenant;

    protected $table = 'payroll_withholding_tax_tariffs';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'income_from' => 'decimal:2',
            'income_step' => 'decimal:2',
            'minimum_tax' => 'decimal:2',
            'rate_percent' => 'decimal:4',
        ];
    }
}
