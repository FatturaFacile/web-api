<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\CourseEnrolmentStatus;

final class CourseEnrolment extends AssociationRecord
{
    protected $table = 'assoc_course_enrolments';

    protected function casts(): array
    {
        return [
            'status' => CourseEnrolmentStatus::class,
            'waitlist_position' => 'integer',
            'enrolled_on' => 'date',
            'withdrawn_on' => 'date',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
