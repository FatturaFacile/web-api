<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\HumanResources\Casts\UtcInstant;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;

/**
 * A daily working time record for employees with simplified recording (art. 73b OLL 1): only the daily duration, plus
 * start and end for night or Sunday work.
 *
 * @property CarbonImmutable|null $start_time
 * @property CarbonImmutable|null $end_time
 */
final class TimeDailyRecord extends BaseEnterpriseModel
{
    protected $table = 'time_daily_records';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'worked_minutes' => 'integer',
            'start_time' => UtcInstant::class,
            'end_time' => UtcInstant::class,
        ];
    }

    /**
     * The values kept in the history of the changes.
     *
     * @return array{work_date: string, worked_minutes: int, start_time: string|null, end_time: string|null, note: string|null}
     */
    public function auditValues(): array
    {
        return [
            'work_date' => $this->work_date->format('Y-m-d'),
            'worked_minutes' => $this->worked_minutes,
            'start_time' => $this->start_time === null ? null : SwissWorkingTimeRules::toLocal($this->start_time)->toIso8601String(),
            'end_time' => $this->end_time === null ? null : SwissWorkingTimeRules::toLocal($this->end_time)->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
