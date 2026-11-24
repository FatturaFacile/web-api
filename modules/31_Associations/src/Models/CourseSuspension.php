<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A pause of a course between two dates: the course stays, its lessons in the period do not happen.
 */
final class CourseSuspension extends AssociationRecord
{
    protected $table = 'assoc_course_suspensions';

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }
}
