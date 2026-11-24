<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of accommodation (single, double, suite) with its capacity and the base nightly rate used when no rate plan applies.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $max_occupancy
 * @property string $base_rate
 * @property bool $is_active
 */
final class RoomType extends HospitalityRecord
{
    protected $table = 'hosp_room_types';

    protected function casts(): array
    {
        return ['max_occupancy' => 'integer', 'base_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Accommodation, $this>
     */
    public function accommodations(): HasMany
    {
        return $this->hasMany(Accommodation::class, 'room_type_id');
    }
}
