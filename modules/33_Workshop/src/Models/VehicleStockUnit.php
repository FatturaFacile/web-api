<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A new or used vehicle held for sale.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $stock_number
 * @property string $status
 * @property string $condition
 * @property ?string $license_plate
 * @property ?string $vin
 * @property string $make
 * @property string $model
 * @property ?int $year
 * @property int $mileage_km
 * @property string $currency
 */
final class VehicleStockUnit extends WorkshopRecord
{
    use SoftDeletes;

    protected $table = 'workshop_vehicle_stock';

    protected function casts(): array
    {
        return ['purchase_price' => 'decimal:2', 'list_price' => 'decimal:2', 'year' => 'integer', 'mileage_km' => 'integer'];
    }

    /**
     * @return HasOne<VehicleSale, $this>
     */
    public function sale(): HasOne
    {
        return $this->hasOne(VehicleSale::class, 'stock_id');
    }
}
