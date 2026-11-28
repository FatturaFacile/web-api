<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Presence of a student on one day, recorded against the group the student was in that day.
 *
 * @property string $enrollment_id
 * @property string $class_id
 * @property Carbon $attendance_date
 * @property bool $present
 */
final class CourseAttendance extends BaseEnterpriseModel
{
    protected $table = 'group_course_attendances';

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'present' => 'boolean',
        ];
    }
}
