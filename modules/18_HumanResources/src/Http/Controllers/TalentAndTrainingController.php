<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
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
use OpenApi\Attributes as OA;

final class TalentAndTrainingController extends Controller
{
    public function __construct(
        private readonly PerformanceManagementServiceInterface $performanceService,
        private readonly TrainingAndCertificationServiceInterface $trainingService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the performance reviews of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/hr/performance-reviews',
        operationId: 'getReviewsHrPerformanceReviews',
        description: 'Returns the performance reviews of the active tenant, latest first.',
        summary: 'List performance reviews',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Performance Reviews'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesPerformanceReviewResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reviews(): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();

        return PerformanceReviewResource::collection(PerformanceReview::where('tenant_id', $tenant->id)->orderByDesc('review_date')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Submits a performance review for an employee.
     */
    #[OA\Post(
        path: '/api/v1/hr/performance-reviews',
        operationId: 'submitReviewHrPerformanceReviews',
        description: 'Submits a performance review for an employee.',
        summary: 'Submit performance review',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Performance Reviews'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSubmitPerformanceReviewRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Performance review created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesPerformanceReviewResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Completes a performance review on behalf of the authenticated user.
     */
    #[OA\Post(
        path: '/api/v1/hr/performance-reviews/{id}/complete',
        operationId: 'completeReviewHrPerformanceReview',
        description: 'Completes a performance review on behalf of the authenticated user.',
        summary: 'Complete performance review',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Performance Reviews'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the performance review', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesPerformanceReviewResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function completeReview(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $review = PerformanceReview::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->performanceService->completeReview($review, $actorId);

        return response()->json(['data' => new PerformanceReviewResource($updated)]);
    }

    /**
     * Returns the training courses of the active tenant ordered by course code.
     */
    #[OA\Get(
        path: '/api/v1/hr/training-courses',
        operationId: 'getCoursesHrTrainingCourses',
        description: 'Returns the training courses of the active tenant ordered by course code.',
        summary: 'List training courses',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Training Courses'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesTrainingCourseModel'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function courses(): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();

        return JsonResource::collection(TrainingCourse::where('tenant_id', $tenant->id)->orderBy('course_code')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Creates a training course in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/hr/training-courses',
        operationId: 'storeCourseHrTrainingCourses',
        description: 'Creates a training course in the active tenant.',
        summary: 'Create training course',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Training Courses'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HrTrainingCourseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Training course created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTrainingCourseModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Returns the training records of the active tenant, latest assignment first.
     */
    #[OA\Get(
        path: '/api/v1/hr/training-records',
        operationId: 'getTrainingRecordsHr',
        description: 'Returns the training records of the active tenant, latest assignment first.',
        summary: 'List training records',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Training Records'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesTrainingRecordResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function trainingRecords(): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();

        return TrainingRecordResource::collection(TrainingRecord::where('tenant_id', $tenant->id)->orderByDesc('assigned_date')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Assigns a training course to an employee.
     */
    #[OA\Post(
        path: '/api/v1/hr/training-records',
        operationId: 'assignTrainingHrTrainingRecords',
        description: 'Assigns a training course to an employee.',
        summary: 'Assign training',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Training Records'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesAssignTrainingRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Training record created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTrainingRecordResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Completes a training record with the final score.
     */
    #[OA\Post(
        path: '/api/v1/hr/training-records/{id}/complete',
        operationId: 'completeTrainingHrTrainingRecord',
        description: 'Completes a training record with the final score.',
        summary: 'Complete training',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Training Records'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the training record', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/HrCompleteTrainingRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTrainingRecordResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Returns the certifications of the active tenant ordered by expiry date.
     */
    #[OA\Get(
        path: '/api/v1/hr/certifications',
        operationId: 'getCertificationsHr',
        description: 'Returns the certifications of the active tenant ordered by expiry date.',
        summary: 'List certifications',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Certifications'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesCertificationModel'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function certifications(): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();

        return JsonResource::collection(Certification::where('tenant_id', $tenant->id)->orderBy('expiry_date')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    /**
     * Raises alerts for the certifications that expire within the given days and returns how many were alerted.
     */
    #[OA\Post(
        path: '/api/v1/hr/certifications/scan-expiring',
        operationId: 'scanExpiringCertificationsHrCertificationsScanExpiring',
        description: 'Raises alerts for the certifications that expire within the given days and returns how many were alerted.',
        summary: 'Scan expiring certifications',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Certifications'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/HrScanExpiringCertificationsRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Number of certifications alerted', content: new OA\JsonContent(ref: '#/components/schemas/HrScanExpiringCertificationsResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function scanExpiringCertifications(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $days = (int) $request->input('within_days', 30);
        $alerted = $this->trainingService->scanExpiringCertifications((string) $tenant->id, $days);

        return response()->json(['data' => ['expiring_certifications_alerted' => $alerted]]);
    }
}
