<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Pro-rata of deduction of a year (art. 19 c. 5 and 19-bis DPR 633/1972).
 *
 * @property int $year
 * @property string $deductible_operations
 * @property string $total_operations
 * @property int $provisional_percentage
 * @property int|null $definitive_percentage
 * @property bool $is_definitive
 */
final class VatProRataRate extends BaseEnterpriseModel
{
    protected $table = 'it_vat_pro_rata_rates';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'deductible_operations' => 'decimal:2',
            'total_operations' => 'decimal:2',
            'provisional_percentage' => 'integer',
            'definitive_percentage' => 'integer',
            'is_definitive' => 'boolean',
        ];
    }
}
