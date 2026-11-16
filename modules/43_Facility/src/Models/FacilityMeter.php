<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A utility meter (electricity, gas or water) of a building or space, with an optional tariff per unit to cost the
 * consumption and an optional roll-over value for meters whose dial wraps around.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $building_id
 * @property string|null $space_id
 * @property string $kind
 * @property string $code
 * @property string $name
 * @property string $unit
 * @property string|null $tariff_per_unit
 * @property string $currency
 * @property string|null $rollover_value
 * @property bool $is_active
 * @property Carbon|null $installed_on
 * @property Carbon|null $last_read_on
 * @property string|null $last_value
 * @property string|null $notes
 */
final class FacilityMeter extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Kinds = ['electricity', 'gas', 'water'];

    /** @var array<string, list<string>> Units accepted for each kind. */
    public const array Units = [
        'electricity' => ['kWh', 'MWh'],
        'gas' => ['Sm3', 'm3', 'kWh'],
        'water' => ['m3', 'l'],
    ];

    /** @var list<string> */
    public const array Currencies = ['EUR', 'CHF'];

    protected $table = 'fac_meters';

    protected function casts(): array
    {
        return [
            'tariff_per_unit' => 'decimal:6',
            'rollover_value' => 'decimal:3',
            'is_active' => 'boolean',
            'installed_on' => 'date',
            'last_read_on' => 'date',
            'last_value' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<FacilityBuilding, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(FacilityBuilding::class, 'building_id');
    }

    /**
     * @return HasMany<FacilityMeterReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(FacilityMeterReading::class, 'meter_id');
    }
}
