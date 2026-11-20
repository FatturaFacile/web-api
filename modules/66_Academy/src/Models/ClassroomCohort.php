<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class of students led by a teacher. Students join with the join code.
 *
 * @property string $id
 * @property string $teacher_id
 * @property string $name
 * @property string $join_code
 */
final class ClassroomCohort extends BaseEnterpriseModel
{
    protected $table = 'academy_classroom_cohorts';

    /**
     * @return HasMany<ClassroomEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassroomEnrollment::class, 'cohort_id');
    }

    /**
     * @return HasMany<ClassroomAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClassroomAssignment::class, 'cohort_id');
    }
}
