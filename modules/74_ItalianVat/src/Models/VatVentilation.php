<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Ventilation of the daily takings of a retailer that cannot split them by rate (art. 24 DPR 633/1972).
 *
 * @property CarbonImmutable $entry_date
 * @property string $gross_takings
 * @property array<int, array<string, mixed>> $purchase_basis
 * @property array<int, array<string, mixed>> $breakdown
 * @property string $total_taxable
 * @property string $total_vat
 * @property CarbonImmutable|null $registered_at
 */
final class VatVentilation extends BaseEnterpriseModel
{
    protected $table = 'it_vat_ventilations';

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'gross_takings' => 'decimal:2',
            'purchase_basis' => 'array',
            'breakdown' => 'array',
            'total_taxable' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'registered_at' => 'immutable_datetime',
        ];
    }
}
