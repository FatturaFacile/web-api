<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An employee taking a course, on her own or as a step of a learning path.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $course_id
 * @property string $employee_id
 * @property string|null $learning_path_id
 * @property string $status
 * @property int $progress_percent
 * @property Carbon|null $due_on
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $score
 */
final class LmsEnrolment extends BaseEnterpriseModel
{
    public const string Enrolled = 'enrolled';

    public const string InProgress = 'in_progress';

    public const string Completed = 'completed';

    public const string Cancelled = 'cancelled';

    protected $table = 'tl_enrolments';

    protected function casts(): array
    {
        return ['progress_percent' => 'integer', 'due_on' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'score' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<LmsCourse, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    /**
     * @return HasOne<LmsCertificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(LmsCertificate::class, 'enrolment_id');
    }
}
