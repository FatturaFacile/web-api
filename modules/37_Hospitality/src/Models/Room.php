<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dining room with the plan of its tables.
 */
final class Room extends HospitalityRecord
{
    protected $table = 'hosp_rooms';

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    /**
     * @return HasMany<DiningTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class, 'room_id')->orderBy('name');
    }
}
