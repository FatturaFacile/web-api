<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A student of a cohort.
 *
 * @property string $cohort_id
 * @property string $user_id
 * @property Carbon $enrolled_at
 */
final class ClassroomEnrollment extends BaseEnterpriseModel
{
    protected $table = 'academy_classroom_enrollments';

    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime'];
    }
}
