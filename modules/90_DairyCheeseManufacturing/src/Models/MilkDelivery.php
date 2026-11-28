<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A raw milk intake of a breeder into a tank; the remaining quantity is what has not yet been drawn into a vat (FIFO layer).
 *
 * @property string $breeder_id
 * @property string $tank_id
 * @property string|null $route_code
 * @property string|null $tanker_plate
 * @property Carbon $collection_date
 * @property string $session
 * @property string $liters
 * @property string $density
 * @property string $kg
 * @property string $remaining_liters
 * @property string $remaining_kg
 * @property string|null $temperature_c
 * @property string $status
 * @property string|null $monthly_settlement_id
 * @property string|null $notes
 * @property-read Breeder $breeder
 * @property-read MilkTank $tank
 */
final class MilkDelivery extends BaseEnterpriseModel
{
    protected $table = 'dairy_milk_deliveries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'liters' => 'decimal:3',
            'density' => 'decimal:4',
            'kg' => 'decimal:3',
            'remaining_liters' => 'decimal:3',
            'remaining_kg' => 'decimal:3',
            'temperature_c' => 'decimal:1',
        ];
    }

    public function breeder(): BelongsTo
    {
        return $this->belongsTo(Breeder::class, 'breeder_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(MilkTank::class, 'tank_id');
    }
}
