<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A garden, a lawn or the green areas of a condominium that the company maintains.
 *
 * @property string $code
 * @property string $name
 * @property string $customer_id
 * @property string|null $condominium_administrator_id
 * @property string $jurisdiction
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property array<string, mixed>|null $geo_polygon_coordinates GeoJSON polygon
 * @property string $lawn_area_sqm
 * @property string $hedge_length_meters
 * @property int $tree_count
 * @property array<string, mixed>|null $irrigation_system_specs
 * @property string|null $access_gate_code
 * @property string|null $access_notes
 * @property array<int, array<string, mixed>>|null $botanical_inventory
 * @property bool $is_active
 */
final class GreenSpaceSite extends BaseEnterpriseModel
{
    protected $table = 'green_space_sites';

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geo_polygon_coordinates' => 'array',
            'lawn_area_sqm' => 'decimal:2',
            'hedge_length_meters' => 'decimal:2',
            'tree_count' => 'integer',
            'irrigation_system_specs' => 'array',
            // The gate code is a secret of the customer: encrypted at rest.
            'access_gate_code' => 'encrypted',
            'botanical_inventory' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function condominiumAdministrator(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'condominium_administrator_id');
    }

    /**
     * @return HasMany<SeasonalMaintenanceContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(SeasonalMaintenanceContract::class, 'site_id');
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
