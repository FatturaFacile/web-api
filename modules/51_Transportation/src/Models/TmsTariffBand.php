<?php

declare(strict_types=1);

namespace Modules\Transportation\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price line of a tariff table for a zone pair, a weight range and a distance range (from inclusive, to exclusive, empty to means open ended). Bands of the same zones never overlap.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $tariff_table_id
 * @property string|null $origin_zone
 * @property string|null $destination_zone
 * @property string $weight_from_kg
 * @property string|null $weight_to_kg
 * @property string $distance_from_km
 * @property string|null $distance_to_km
 * @property string $fixed_amount
 * @property string $price_per_kg
 * @property string $price_per_km
 */
final class TmsTariffBand extends BaseEnterpriseModel
{
    protected $table = 'tms_tariff_bands';

    protected function casts(): array
    {
        return [
            'weight_from_kg' => 'decimal:3',
            'weight_to_kg' => 'decimal:3',
            'distance_from_km' => 'decimal:2',
            'distance_to_km' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
            'price_per_kg' => 'decimal:4',
            'price_per_km' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<TmsTariffTable, $this>
     */
    public function tariffTable(): BelongsTo
    {
        return $this->belongsTo(TmsTariffTable::class, 'tariff_table_id');
    }
}
