<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

/**
 * A table of a room, placed on the room plan.
 *
 * @property string $id
 * @property string $room_id
 * @property string $name
 * @property bool $is_active
 */
final class DiningTable extends HospitalityRecord
{
    protected $table = 'hosp_tables';

    protected function casts(): array
    {
        return ['seats' => 'integer', 'pos_x' => 'integer', 'pos_y' => 'integer', 'width' => 'integer', 'height' => 'integer', 'is_active' => 'boolean'];
    }
}
