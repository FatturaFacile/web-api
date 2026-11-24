<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A step of a learning path.
 *
 * @property string $id
 * @property string $learning_path_id
 * @property string $course_id
 * @property int $position
 */
final class LmsPathCourse extends BaseEnterpriseModel
{
    protected $table = 'tl_learning_path_courses';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<LmsCourse, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }
}
