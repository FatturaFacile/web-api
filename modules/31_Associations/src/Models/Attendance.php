<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presence of a participant on a day of a course.
 */
final class Attendance extends AssociationEntry
{
    public const string Present = 'present';

    public const string Absent = 'absent';

    protected $table = 'assoc_attendances';

    protected function casts(): array
    {
        return ['attended_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
