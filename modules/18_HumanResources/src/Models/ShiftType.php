<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\HumanResources\Services\Shifts\ShiftCategory;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;

/**
 * A kind of shift. Times are local; an end at or before the start falls on the next day. Rows without a tenant are
 * generic and read-only for the companies.
 *
 * @property ShiftCategory $category
 */
final class ShiftType extends BaseEnterpriseModel
{
    protected $table = 'hr_shift_types';

    protected function casts(): array
    {
        return [
            'category' => ShiftCategory::class,
            'break_minutes' => 'integer',
            'is_paid_absence' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The instants of the shift on a local day. Absences and types without times have none. A local time skipped by the
     * spring clock change moves forward, as it does on a real clock.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function instantsOn(string $date): ?array
    {
        if ($this->start_time === null || $this->end_time === null) {
            return null;
        }

        $day = SwissWorkingTimeRules::localDate($date);
        $crossesMidnight = substr((string) $this->end_time, 0, 5) <= substr((string) $this->start_time, 0, 5);

        return [
            $day->setTimeFromTimeString((string) $this->start_time),
            ($crossesMidnight ? $day->addDay() : $day)->setTimeFromTimeString((string) $this->end_time),
        ];
    }
}
