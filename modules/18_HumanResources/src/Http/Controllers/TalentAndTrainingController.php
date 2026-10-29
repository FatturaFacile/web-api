<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\PerformanceManagementServiceInterface;
use Modules\HumanResources\Contracts\TrainingAndCertificationServiceInterface;
use Modules\HumanResources\DTOs\AssignTrainingDTO;
use Modules\HumanResources\DTOs\SubmitPerformanceReviewDTO;
use Modules\HumanResources\Http\Requests\AssignTrainingRequest;
use Modules\HumanResources\Http\Requests\SubmitPerformanceReviewRequest;
use Modules\HumanResources\Http\Resources\PerformanceReviewResource;
use Modules\HumanResources\Http\Resources\TrainingRecordResource;
use Modules\HumanResources\Models\Certification;
use Modules\HumanResources\Models\PerformanceReview;
use Modules\HumanResources\Models\TrainingCourse;
use Modules\HumanResources\Models\TrainingRecord;

final class TalentAndTrainingController extends Controller
{
    public function __construct(
        private readonly PerformanceManagementServiceInterface $performanceService,
        private readonly TrainingAndCertificationServiceInterface $trainingService,
        private readonly TenantContext $tenantContext
    ) {}

    public function reviews(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $reviews = PerformanceReview::where('tenant_id', $tenant->id)->orderByDesc('review_date')->get();

        return response()->json(['data' => PerformanceReviewResource::collection($reviews)]);
    }

    public function submitReview(SubmitPerformanceReviewRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $review = $this->performanceService->submitReview(new SubmitPerformanceReviewDTO(
            tenantId: (string) $tenant->id,
            employeeId: (string) $request->input('employee_id'),
            reviewPeriod: (string) $request->input('review_period'),
            goalsScore: (float) $request->input('goals_score'),
            competenciesScore: (float) $request->input('competencies_score'),
            reviewerEmployeeId: $request->input('reviewer_employee_id'),
            reviewType: (string) $request->input('review_type', 'annual'),
            promotionRecommended: (bool) $request->input('promotion_recommended', false),
            recommendedJobGradeId: $request->input('recommended_job_grade_id'),
            recommendedSalaryIncreasePercent: (float) $request->input('recommended_salary_increase_percent', 0.0),
            successorReadiness: $request->input('successor_readiness'),
            goals: $request->input('goals', []),
            competencies: $request->input('competencies', []),
            strengths: $request->input('strengths'),
            improvementAreas: $request->input('improvement_areas'),
            careerPathNotes: $request->input('career_path_notes')
        ), $actorId);

        return response()->json(['data' => new PerformanceReviewResource($review)], 201);
    }

    public function completeReview(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $review = PerformanceReview::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->performanceService->completeReview($review, $actorId);

        return response()->json(['data' => new PerformanceReviewResource($updated)]);
    }

    public function courses(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        return response()->json([
            'data' => TrainingCourse::where('tenant_id', $tenant->id)->orderBy('course_code')->get(),
        ]);
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $course = TrainingCourse::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'company_id' => $tenant->id,
            'course_code' => (string) $request->input('course_code'),
            'title' => (string) $request->input('title'),
            'category' => (string) $request->input('category', 'safety'),
            'provider' => (string) $request->input('provider', 'Enterprise Academy'),
            'duration_hours' => (float) $request->input('duration_hours', 8.0),
            'cost_per_participant' => (float) $request->input('cost_per_participant', 0.0),
            'validity_months' => (int) $request->input('validity_months', 24),
            'is_mandatory_compliance' => (bool) $request->input('is_mandatory_compliance', false),
            'is_active' => true,
        ]);

        return response()->json(['data' => $course], 201);
    }

    public function trainingRecords(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $records = TrainingRecord::where('tenant_id', $tenant->id)->orderByDesc('assigned_date')->get();

        return response()->json(['data' => TrainingRecordResource::collection($records)]);
    }

    public function assignTraining(AssignTrainingRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $record = $this->trainingService->assignTraining(new AssignTrainingDTO(
            tenantId: (string) $tenant->id,
            employeeId: (string) $request->input('employee_id'),
            courseId: (string) $request->input('course_id'),
            scheduledDate: $request->input('scheduled_date'),
            overrideCost: $request->input('override_cost') !== null ? (float) $request->input('override_cost') : null,
            notes: $request->input('notes')
        ), $actorId);

        return response()->json(['data' => new TrainingRecordResource($record)], 201);
    }

    public function completeTraining(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $record = TrainingRecord::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->trainingService->completeTraining(
            $record,
            (float) $request->input('score', 100.0),
            $actorId
        );

        return response()->json(['data' => new TrainingRecordResource($updated)]);
    }

    public function certifications(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        return response()->json([
            'data' => Certification::where('tenant_id', $tenant->id)->orderBy('expiry_date')->get(),
        ]);
    }

    public function scanExpiringCertifications(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $days = (int) $request->input('within_days', 30);
        $alerted = $this->trainingService->scanExpiringCertifications((string) $tenant->id, $days);

        return response()->json(['data' => ['expiring_certifications_alerted' => $alerted]]);
    }
}
