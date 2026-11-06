<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\ScheduleOccurrence;

/**
 * When a course meets: every day, on a weekday (ISO, 1 is Monday) or on a date.
 */
final class CourseSchedule extends AssociationRecord
{
    protected $table = 'assoc_course_schedules';

    protected function casts(): array
    {
        return ['pattern' => ScheduleOccurrence::class, 'weekday' => 'integer', 'on_date' => 'immutable_date'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
