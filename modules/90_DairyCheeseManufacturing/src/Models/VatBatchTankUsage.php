<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Milk taken from a tank by a vat batch.
 *
 * @property string $vat_batch_id
 * @property string $tank_id
 * @property string $liters
 * @property string $kg
 * @property-read CheeseVatBatch $batch
 * @property-read MilkTank $tank
 */
final class VatBatchTankUsage extends BaseEnterpriseModel
{
    protected $table = 'dairy_vat_batch_tank_usages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'liters' => 'decimal:3',
            'kg' => 'decimal:3',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CheeseVatBatch::class, 'vat_batch_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(MilkTank::class, 'tank_id');
    }
}
