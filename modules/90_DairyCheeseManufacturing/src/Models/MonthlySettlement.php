<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly milk liquidation of a breeder: itemized payout and the billing document generated from it.
 *
 * @property string $breeder_id
 * @property string $period
 * @property string $grid_id
 * @property string $status
 * @property string $total_kg
 * @property string $total_liters
 * @property string $avg_fat_pct
 * @property string $avg_protein_pct
 * @property string $base_amount
 * @property string $quality_adjustment
 * @property string $supplement_amount
 * @property string $penalty_amount
 * @property string $gross_amount
 * @property string $vat_amount
 * @property string $net_amount
 * @property string $currency
 * @property string|null $document_id
 * @property array<int,array<string,mixed>>|null $lines
 * @property-read Breeder $breeder
 * @property-read PricingGrid $grid
 */
final class MonthlySettlement extends BaseEnterpriseModel
{
    protected $table = 'dairy_monthly_settlements';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_kg' => 'decimal:3',
            'total_liters' => 'decimal:3',
            'avg_fat_pct' => 'decimal:3',
            'avg_protein_pct' => 'decimal:3',
            'base_amount' => 'decimal:2',
            'quality_adjustment' => 'decimal:2',
            'supplement_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'lines' => 'array',
        ];
    }

    public function breeder(): BelongsTo
    {
        return $this->belongsTo(Breeder::class, 'breeder_id');
    }

    public function grid(): BelongsTo
    {
        return $this->belongsTo(PricingGrid::class, 'grid_id');
    }
}
