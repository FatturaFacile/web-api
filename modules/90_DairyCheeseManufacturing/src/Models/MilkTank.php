<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A raw milk silo / tank (cisterna). The volume it holds is the remaining liters of its deliveries.
 *
 * @property string $code
 * @property string $name
 * @property string $capacity_liters
 * @property string $status
 * @property Carbon|null $last_cleaned_at
 * @property-read Collection<int,MilkDelivery> $deliveries
 */
final class MilkTank extends BaseEnterpriseModel
{
    protected $table = 'dairy_milk_tanks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity_liters' => 'decimal:3',
            'last_cleaned_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MilkDelivery::class, 'tank_id');
    }
}
