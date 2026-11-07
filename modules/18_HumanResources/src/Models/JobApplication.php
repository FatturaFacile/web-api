<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An application. Its personal data are encrypted, and it disappears for good when its retention ends.
 */
final class JobApplication extends CandidateRecord
{
    public const string StatusActive = 'active';

    public const string StatusHired = 'hired';

    public const string StatusRejected = 'rejected';

    public const string StatusWithdrawn = 'withdrawn';

    protected $table = 'hr_job_applications';

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    /**
     * @return HasMany<InterviewNote, $this>
     */
    public function interviewNotes(): HasMany
    {
        return $this->hasMany(InterviewNote::class, 'job_application_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<ApplicationStageEvent, $this>
     */
    public function stageEvents(): HasMany
    {
        return $this->hasMany(ApplicationStageEvent::class, 'job_application_id')->orderBy('occurred_at');
    }

    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'cover_note' => 'encrypted',
            'consent_until' => 'date:Y-m-d',
            'retention_until' => 'date:Y-m-d',
            'decided_at' => 'datetime',
        ];
    }
}
