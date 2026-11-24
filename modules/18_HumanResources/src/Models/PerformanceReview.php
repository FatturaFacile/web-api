<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Workflow\Contracts\LockableByWorkflowInterface;
use Modules\Workflow\Models\WorkflowInstance;

final class PerformanceReview extends BaseEnterpriseModel implements LockableByWorkflowInterface
{
    protected $table = 'hr_performance_reviews';

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
            'overall_rating' => 'decimal:2',
            'goals_score' => 'decimal:2',
            'competencies_score' => 'decimal:2',
            'promotion_recommended' => 'boolean',
            'recommended_salary_increase_percent' => 'decimal:2',
            'goals' => 'array',
            'competencies' => 'array',
            'is_locked' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function lockByWorkflow(WorkflowInstance $instance): void
    {
        $this->update([
            'workflow_instance_id' => $instance->id,
            'status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function isLockedByWorkflow(): bool
    {
        return (bool) $this->is_locked;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_employee_id');
    }

    public function recommendedJobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class, 'recommended_job_grade_id');
    }
}
