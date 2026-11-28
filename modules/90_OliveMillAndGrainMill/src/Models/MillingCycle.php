<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\OliveMillAndGrainMill\Domain\Enums\CycleStatus;

/**
 * Milling (crushing) cycle of one delivery: process data, extracted oil or flour, yield, lab values, by-products and where the product goes.
 *
 * @property string $delivery_id
 * @property string|null $line_code
 * @property CycleStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $malaxation_temperature_c
 * @property string $water_added_l
 * @property string $input_net_kg
 * @property string $output_kg
 * @property string $output_liters
 * @property string $density_kg_per_l
 * @property string $yield_percent
 * @property bool $cold_extraction
 * @property string|null $acidity_pct
 * @property string|null $peroxides
 * @property string|null $polyphenols_mg_kg
 * @property string|null $panel_notes
 * @property string|null $quality_class
 * @property array|null $quality_warnings
 * @property string $sansa_kg
 * @property string $nocciolino_kg
 * @property string $vegetation_water_l
 * @property string $bran_kg
 * @property string $pickup_kg
 * @property string $molenda_kg
 * @property string $mill_stock_kg
 * @property string|null $target_tank_id
 * @property Carbon|null $completed_at
 * @property string|null $created_by
 */
final class MillingCycle extends BaseEnterpriseModel
{
    protected $table = 'mill_milling_cycles';

    protected function casts(): array
    {
        return [
            'status' => CycleStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'malaxation_temperature_c' => 'decimal:2',
            'water_added_l' => 'decimal:2',
            'input_net_kg' => 'decimal:3',
            'output_kg' => 'decimal:3',
            'output_liters' => 'decimal:3',
            'density_kg_per_l' => 'decimal:4',
            'yield_percent' => 'decimal:2',
            'cold_extraction' => 'boolean',
            'acidity_pct' => 'decimal:2',
            'peroxides' => 'decimal:2',
            'polyphenols_mg_kg' => 'decimal:2',
            'quality_warnings' => 'array',
            'sansa_kg' => 'decimal:3',
            'nocciolino_kg' => 'decimal:3',
            'vegetation_water_l' => 'decimal:3',
            'bran_kg' => 'decimal:3',
            'pickup_kg' => 'decimal:3',
            'molenda_kg' => 'decimal:3',
            'mill_stock_kg' => 'decimal:3',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OliveDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(OliveDelivery::class, 'delivery_id');
    }

    /**
     * @return BelongsTo<SianTank, $this>
     */
    public function targetTank(): BelongsTo
    {
        return $this->belongsTo(SianTank::class, 'target_tank_id');
    }

    /**
     * @return HasOne<MillingSettlement, $this>
     */
    public function settlement(): HasOne
    {
        return $this->hasOne(MillingSettlement::class, 'cycle_id');
    }
}
