<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hive inspection with the automated alerts it raised.
 *
 * @property string $hive_id
 * @property string $apiary_id
 * @property Carbon $inspected_at
 * @property string|null $operator_id
 * @property string|null $weather
 * @property string|null $temperature_c
 * @property bool $queen_seen
 * @property bool $eggs_present
 * @property bool $larvae_present
 * @property bool $capped_brood_present
 * @property int $brood_frames
 * @property int $store_frames
 * @property int $supers_added
 * @property int $supers_removed
 * @property int $swarm_cells_found
 * @property int $swarm_cells_destroyed
 * @property string|null $feeding_type
 * @property string|null $feeding_kg
 * @property int|null $strength_score
 * @property string|null $observations
 * @property list<string>|null $alerts
 */
final class Inspection extends BaseEnterpriseModel
{
    protected $table = 'bee_inspections';

    protected function casts(): array
    {
        return [
            'inspected_at' => 'datetime',
            'queen_seen' => 'boolean',
            'eggs_present' => 'boolean',
            'larvae_present' => 'boolean',
            'capped_brood_present' => 'boolean',
            'brood_frames' => 'integer',
            'store_frames' => 'integer',
            'supers_added' => 'integer',
            'supers_removed' => 'integer',
            'swarm_cells_found' => 'integer',
            'swarm_cells_destroyed' => 'integer',
            'strength_score' => 'integer',
            'alerts' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Hive, $this>
     */
    public function hive(): BelongsTo
    {
        return $this->belongsTo(Hive::class, 'hive_id');
    }
}
