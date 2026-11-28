<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A boiler / vat batch (lotto di caldaia / cotta): the milk drawn from tanks, the process log and the casein plaque range.
 *
 * @property string $code
 * @property string $cheese_type
 * @property string $status
 * @property Carbon $production_date
 * @property string|null $vat_number
 * @property string $milk_kg
 * @property string|null $starter_culture
 * @property string|null $starter_amount
 * @property string|null $rennet_type
 * @property string|null $rennet_ml
 * @property array<int,array<string,mixed>>|null $ph_log
 * @property string|null $curd_yield_kg
 * @property string|null $fresh_yield_24h_kg
 * @property string|null $fresh_yield_24h_pct
 * @property string|null $final_aged_yield_kg
 * @property string|null $final_aged_yield_pct
 * @property string|null $plaque_prefix
 * @property int|null $plaque_from
 * @property int|null $plaque_to
 * @property int $wheels_count
 * @property-read Collection<int,VatBatchTankUsage> $tankUsages
 * @property-read Collection<int,VatBatchMilkDraw> $draws
 * @property-read Collection<int,CheeseAgingWheel> $wheels
 */
final class CheeseVatBatch extends BaseEnterpriseModel
{
    protected $table = 'dairy_cheese_vat_batches';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'milk_kg' => 'decimal:3',
            'starter_amount' => 'decimal:3',
            'rennet_ml' => 'decimal:3',
            'ph_log' => 'array',
            'curd_yield_kg' => 'decimal:3',
            'fresh_yield_24h_kg' => 'decimal:3',
            'fresh_yield_24h_pct' => 'decimal:3',
            'final_aged_yield_kg' => 'decimal:3',
            'final_aged_yield_pct' => 'decimal:3',
            'plaque_from' => 'integer',
            'plaque_to' => 'integer',
            'wheels_count' => 'integer',
        ];
    }

    public function tankUsages(): HasMany
    {
        return $this->hasMany(VatBatchTankUsage::class, 'vat_batch_id');
    }

    public function draws(): HasMany
    {
        return $this->hasMany(VatBatchMilkDraw::class, 'vat_batch_id');
    }

    public function wheels(): HasMany
    {
        return $this->hasMany(CheeseAgingWheel::class, 'vat_batch_id');
    }
}
