<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\PerformanceManagementServiceInterface;
use Modules\HumanResources\DTOs\SubmitPerformanceReviewDTO;
use Modules\HumanResources\Events\PerformanceReviewCompletedEvent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\PerformanceReview;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

final readonly class PerformanceManagementService implements PerformanceManagementServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private ?WorkflowEngineInterface $workflowEngine = null
    ) {}

    public function submitReview(SubmitPerformanceReviewDTO $dto, ?string $actorUserId = null): PerformanceReview
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $employee = Employee::findOrFail($dto->employeeId);
            $overallRating = round(($dto->goalsScore * 0.60) + ($dto->competenciesScore * 0.40), 2);
            $revNumber = 'PRV-'.date('Ymd').'-'.strtoupper(Str::random(5));

            $review = PerformanceReview::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'company_id' => $employee->company_id ?? $dto->tenantId,
                'employee_id' => $employee->id,
                'reviewer_employee_id' => $dto->reviewerEmployeeId ?? $employee->manager_id,
                'review_number' => $revNumber,
                'review_period' => $dto->reviewPeriod,
                'review_type' => $dto->reviewType,
                'review_date' => date('Y-m-d'),
                'overall_rating' => $overallRating,
                'goals_score' => $dto->goalsScore,
                'competencies_score' => $dto->competenciesScore,
                'status' => 'submitted',
                'promotion_recommended' => $dto->promotionRecommended,
                'recommended_job_grade_id' => $dto->recommendedJobGradeId,
                'recommended_salary_increase_percent' => $dto->recommendedSalaryIncreasePercent,
                'career_path_notes' => $dto->careerPathNotes,
                'successor_readiness' => $dto->successorReadiness,
                'goals' => $dto->goals,
                'competencies' => $dto->competencies,
                'strengths' => $dto->strengths,
                'improvement_areas' => $dto->improvementAreas,
            ]);

            if ($this->workflowEngine !== null) {
                $hasDef = WorkflowDefinition::where('tenant_id', $dto->tenantId)
                    ->where('code', 'HR_PERFORMANCE_REVIEW_APPROVAL')
                    ->where('is_active', true)
                    ->exists();

                if ($hasDef) {
                    $initiatorId = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7();
                    $instance = $this->workflowEngine->startWorkflow(new StartWorkflowDTO(
                        workflowCode: 'HR_PERFORMANCE_REVIEW_APPROVAL',
                        approvable: $review,
                        contextSnapshot: [
                            'review_number' => $revNumber,
                            'overall_rating' => $overallRating,
                        ],
                        tenantId: $dto->tenantId,
                        companyId: $dto->tenantId,
                        initiatorUserId: $initiatorId
                    ));
                    $review->lockByWorkflow($instance);
                }
            }

            $this->auditLedger->record(
                action: 'HR_PERFORMANCE_REVIEW_SUBMITTED',
                model: $review,
                payloadBefore: null,
                payloadAfter: $review->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            return $review->fresh();
        });
    }

    public function completeReview(PerformanceReview $review, ?string $actorUserId = null): PerformanceReview
    {
        return DB::transaction(function () use ($review) {
            $locked = PerformanceReview::where('id', $review->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();

            $locked->update([
                'status' => 'completed',
                'is_locked' => false,
                'completed_at' => now(),
            ]);

            $this->auditLedger->record(
                action: 'HR_PERFORMANCE_REVIEW_COMPLETED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_18_HR'
            );

            $event = new PerformanceReviewCompletedEvent(
                reviewId: (string) $locked->id,
                reviewNumber: (string) $locked->review_number,
                employeeId: (string) $locked->employee_id,
                reviewPeriod: (string) $locked->review_period,
                overallRating: (float) $locked->overall_rating,
                promotionRecommended: (bool) $locked->promotion_recommended,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            );
            $this->dispatcher->publish($event);
            event($event);

            return $locked->fresh();
        });
    }
}
