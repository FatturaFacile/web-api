<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\OliveMillAndGrainMill\Domain\Enums\OilCategory;
use Modules\OliveMillAndGrainMill\Domain\Enums\OriginDesignation;

/**
 * Stainless steel tank or silo of the mill with its stock, the oil category and origin it holds, the lot composition and its place on the synoptic map.
 *
 * @property string $code
 * @property string|null $description
 * @property string $material
 * @property string $capacity_kg
 * @property string $capacity_l
 * @property string $stock_kg
 * @property string $stock_l
 * @property OilCategory|null $category
 * @property OriginDesignation|null $origin_designation
 * @property array|null $lot_composition
 * @property string|null $temperature_c
 * @property bool $is_active
 * @property int $grid_row
 * @property int $grid_col
 * @property string|null $zone_label
 */
final class SianTank extends BaseEnterpriseModel
{
    protected $table = 'mill_tanks';

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'decimal:3',
            'capacity_l' => 'decimal:3',
            'stock_kg' => 'decimal:3',
            'stock_l' => 'decimal:3',
            'category' => OilCategory::class,
            'origin_designation' => OriginDesignation::class,
            'lot_composition' => 'array',
            'temperature_c' => 'decimal:2',
            'is_active' => 'boolean',
            'grid_row' => 'integer',
            'grid_col' => 'integer',
        ];
    }

    public function fillPercent(): float
    {
        return (float) $this->capacity_kg > 0.0 ? round((float) $this->stock_kg / (float) $this->capacity_kg * 100, 2) : 0.0;
    }

    public function freeKg(): float
    {
        return round((float) $this->capacity_kg - (float) $this->stock_kg, 3);
    }
}
