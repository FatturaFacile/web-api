<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A floor or a space of a building. Floors sit directly under the building; every other kind sits under a floor or under
 * a container space (office, warehouse, common area). Desks, parking spots and meeting rooms are leaves. Bookable spaces
 * are the resources of the booking engine.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $building_id
 * @property string|null $parent_id
 * @property string $kind
 * @property string $code
 * @property string $name
 * @property int|null $floor_level
 * @property int|null $capacity
 * @property string|null $area_sqm
 * @property bool $is_bookable
 * @property bool $is_active
 * @property list<string>|null $amenities
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FacilitySpace extends BaseEnterpriseModel
{
    public const string Floor = 'floor';

    /** @var list<string> */
    public const array Kinds = ['floor', 'office', 'meeting_room', 'desk', 'parking', 'warehouse', 'common_area'];

    /** @var list<string> Kinds that cannot contain other spaces. */
    public const array LeafKinds = ['desk', 'parking', 'meeting_room'];

    /** @var list<string> Kinds that can be booked. */
    public const array BookableKinds = ['office', 'meeting_room', 'desk', 'parking', 'warehouse'];

    protected $table = 'fac_spaces';

    protected function casts(): array
    {
        return [
            'floor_level' => 'integer',
            'capacity' => 'integer',
            'area_sqm' => 'decimal:2',
            'is_bookable' => 'boolean',
            'is_active' => 'boolean',
            'amenities' => 'array',
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
     * @return BelongsTo<FacilitySpace, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<FacilitySpace, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<FacilityBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(FacilityBooking::class, 'space_id');
    }
}
