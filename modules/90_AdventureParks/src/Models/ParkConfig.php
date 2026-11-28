<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Operating parameters of a park (one row per company); the defaults come from config/adventure_parks.php.
 *
 * @property int $buffer_minutes
 * @property int $teacher_free_ratio
 * @property int $teacher_free_per_disabled
 * @property int $picnic_capacity
 * @property string $waiver_version_id
 * @property list<string>|null $wristband_palette
 */
final class ParkConfig extends BaseEnterpriseModel
{
    protected $table = 'adv_park_configs';

    protected function casts(): array
    {
        return [
            'buffer_minutes' => 'integer',
            'teacher_free_ratio' => 'integer',
            'teacher_free_per_disabled' => 'integer',
            'picnic_capacity' => 'integer',
            'wristband_palette' => 'array',
        ];
    }
}
