<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of a delivery (so of a breeder) that went into a vat: the upstream traceability record.
 *
 * @property string $vat_batch_id
 * @property string $tank_id
 * @property string $delivery_id
 * @property string $breeder_id
 * @property string $liters
 * @property string $kg
 * @property-read CheeseVatBatch $batch
 * @property-read MilkTank $tank
 * @property-read MilkDelivery $delivery
 * @property-read Breeder $breeder
 */
final class VatBatchMilkDraw extends BaseEnterpriseModel
{
    protected $table = 'dairy_vat_batch_milk_draws';

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

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(MilkDelivery::class, 'delivery_id');
    }

    public function breeder(): BelongsTo
    {
        return $this->belongsTo(Breeder::class, 'breeder_id');
    }
}
