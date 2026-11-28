<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shelf (asse) of a wooden rack (scalera) in an aging room: room / rack / shelf / level with a wheel capacity.
 *
 * @property string $room
 * @property string $rack
 * @property string $shelf
 * @property int $level
 * @property int $capacity_wheels
 * @property-read Collection<int,CheeseAgingWheel> $wheels
 */
final class AgingRack extends BaseEnterpriseModel
{
    protected $table = 'dairy_aging_racks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'capacity_wheels' => 'integer',
        ];
    }

    public function wheels(): HasMany
    {
        return $this->hasMany(CheeseAgingWheel::class, 'rack_id');
    }
}
