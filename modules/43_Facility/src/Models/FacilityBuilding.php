<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A building of the company: the root of the floors and spaces hierarchy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $city
 * @property string|null $country
 * @property string|null $notes
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FacilityBuilding extends BaseEnterpriseModel
{
    protected $table = 'fac_buildings';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<FacilitySpace, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(FacilitySpace::class, 'building_id');
    }
}
