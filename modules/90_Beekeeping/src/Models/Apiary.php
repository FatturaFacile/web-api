<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Beekeeping\Domain\ValueObjects\GeoPoint;

/**
 * An apiary (stand): a place where hives stand, with the registry code of the country (BDA code in Italy, Bienenstand-Nr. / TVD in Switzerland).
 *
 * @property string $code
 * @property string $name
 * @property string $country
 * @property string|null $municipality
 * @property string|null $province_canton
 * @property string|null $registry_code
 * @property string $apiary_type
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $altitude_m
 * @property int $foraging_radius_m
 * @property string|null $flora_notes
 * @property string|null $blooming_calendar
 * @property string|null $weather_notes
 * @property bool $is_active
 * @property string|null $created_by
 * @property string|null $updated_by
 */
final class Apiary extends BaseEnterpriseModel
{
    protected $table = 'bee_apiaries';

    protected function casts(): array
    {
        return [
            'altitude_m' => 'integer',
            'foraging_radius_m' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function geoPoint(): ?GeoPoint
    {
        return $this->hasCoordinates() ? new GeoPoint((float) $this->latitude, (float) $this->longitude, $this->foraging_radius_m) : null;
    }

    /**
     * @return HasMany<Hive, $this>
     */
    public function hives(): HasMany
    {
        return $this->hasMany(Hive::class, 'apiary_id');
    }
}
