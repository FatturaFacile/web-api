<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * History of the moves of a student between level groups.
 *
 * @property string $enrollment_id
 * @property string $from_class_id
 * @property string $to_class_id
 * @property string|null $reason
 * @property string|null $transferred_by
 * @property Carbon $transferred_at
 */
final class CourseTransfer extends BaseEnterpriseModel
{
    protected $table = 'group_course_transfers';

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }
}
