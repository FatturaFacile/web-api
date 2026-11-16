<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

/**
 * A seasonal price of a room type (or of every type) over a date range. The plan with the highest priority that covers every night wins.
 *
 * @property string $id
 * @property string|null $room_type_id
 * @property string $code
 * @property string $name
 * @property string|null $meal_plan
 * @property string $nightly_rate
 * @property int $min_stay
 * @property int $priority
 * @property bool $is_active
 */
final class RatePlan extends HospitalityRecord
{
    protected $table = 'hosp_rate_plans';

    protected function casts(): array
    {
        return ['nightly_rate' => 'decimal:2', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d', 'min_stay' => 'integer', 'priority' => 'integer', 'is_active' => 'boolean'];
    }
}
