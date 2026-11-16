<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One quantity of activity data (kWh, m3, litres, km, spend, kg) with the emission factor frozen on it: the factor code,
 * version, value and source are copied, so CO2e never changes when the library does.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $emission_factor_id
 * @property Carbon $activity_date
 * @property string $quantity
 * @property string $unit
 * @property string $activity_type
 * @property int $scope
 * @property string $category
 * @property string $factor_code
 * @property int $factor_version
 * @property string $factor_kg_co2e_per_unit
 * @property string $factor_source
 * @property string $co2e_kg
 * @property string $data_quality
 * @property string|null $site
 * @property string|null $reference
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $notes
 * @property string|null $recorded_by
 */
final class EsgActivityRecord extends BaseEnterpriseModel
{
    protected $table = 'esg_activity_records';

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'quantity' => 'decimal:4',
            'factor_kg_co2e_per_unit' => 'decimal:8',
            'co2e_kg' => 'decimal:4',
            'scope' => 'integer',
            'factor_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EsgEmissionFactor, $this>
     */
    public function emissionFactor(): BelongsTo
    {
        return $this->belongsTo(EsgEmissionFactor::class, 'emission_factor_id');
    }
}
