<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An emission factor (kg CO2e per unit of an activity) of one version. The value of a factor that activity records
 * already use never changes: a new version is created instead, and every record keeps the version it was computed with.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property int $version
 * @property string $name
 * @property string $activity_type
 * @property string $unit
 * @property int $scope
 * @property string $category
 * @property string $kg_co2e_per_unit
 * @property string $region
 * @property string $source
 * @property int $source_year
 * @property bool $is_example
 * @property bool $is_active
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class EsgEmissionFactor extends BaseEnterpriseModel
{
    protected $table = 'esg_emission_factors';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'scope' => 'integer',
            'source_year' => 'integer',
            'kg_co2e_per_unit' => 'decimal:8',
            'is_example' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EsgActivityRecord, $this>
     */
    public function activityRecords(): HasMany
    {
        return $this->hasMany(EsgActivityRecord::class, 'emission_factor_id');
    }
}
