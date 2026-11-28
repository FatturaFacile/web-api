<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Interprofessional laboratory analysis of a breeder milk (fat, protein, TBC, SCC, freezing point, inhibitors).
 *
 * @property string $breeder_id
 * @property string|null $delivery_id
 * @property Carbon $sample_date
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string|null $fat_pct
 * @property string|null $protein_pct
 * @property string|null $casein_pct
 * @property string|null $urea_mg_l
 * @property int|null $tbc_cfu_ml
 * @property int|null $scc_cells_ml
 * @property string|null $freezing_point_c
 * @property string $inhibitors
 * @property string|null $laboratory
 * @property string|null $source_format
 * @property-read Breeder $breeder
 * @property-read MilkDelivery|null $delivery
 */
final class QualityTest extends BaseEnterpriseModel
{
    protected $table = 'dairy_milk_quality_tests';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sample_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'fat_pct' => 'decimal:3',
            'protein_pct' => 'decimal:3',
            'casein_pct' => 'decimal:3',
            'urea_mg_l' => 'decimal:2',
            'tbc_cfu_ml' => 'integer',
            'scc_cells_ml' => 'integer',
            'freezing_point_c' => 'decimal:4',
        ];
    }

    public function breeder(): BelongsTo
    {
        return $this->belongsTo(Breeder::class, 'breeder_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(MilkDelivery::class, 'delivery_id');
    }
}
