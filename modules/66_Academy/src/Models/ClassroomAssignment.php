<?php

declare(strict_types=1);

namespace Modules\Academy\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A track assigned to a cohort, optionally with a due date.
 *
 * @property string $cohort_id
 * @property string $track_id
 * @property Carbon|null $due_date
 */
final class ClassroomAssignment extends BaseEnterpriseModel
{
    protected $table = 'academy_classroom_assignments';

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    /**
     * @return BelongsTo<AcademyLearningTrack, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(AcademyLearningTrack::class, 'track_id');
    }
}
