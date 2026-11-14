<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\SupplierPortal\Http\Controllers\Concerns\InteractsWithSupplierPortal;
use Modules\SupplierPortal\Http\Requests\QualificationAssessmentRequest;
use Modules\SupplierPortal\Http\Requests\QualificationCriterionRequest;
use Modules\SupplierPortal\Http\Resources\QualificationAssessmentResource;
use Modules\SupplierPortal\Http\Resources\QualificationCriterionResource;
use Modules\SupplierPortal\Models\QualificationAssessment;
use Modules\SupplierPortal\Models\QualificationCriterion;
use Modules\SupplierPortal\Services\SupplierQualificationScoringService;
use OpenApi\Attributes as OA;

/**
 * The weighted qualification checklist of the company and the assessments of the suppliers against it.
 */
final class QualificationController extends Controller
{
    use InteractsWithSupplierPortal;

    public function __construct(private readonly SupplierQualificationScoringService $scoring) {}

    #[OA\Get(path: '/api/v1/supplier-portal/qualification-criteria', operationId: 'listSupplierQualificationCriteria', description: 'The checklist of the company with the weight of every criterion. Filter: active=1.', summary: 'List qualification criteria', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))], responses: [
        new OA\Response(response: 200, description: 'Criteria', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpQualificationCriterion'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function criteria(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QualificationCriterion::class);

        return QualificationCriterionResource::collection(
            QualificationCriterion::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->boolean('active'), static fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
        );
    }

    #[OA\Post(path: '/api/v1/supplier-portal/qualification-criteria', operationId: 'storeSupplierQualificationCriterion', description: 'Adds a criterion to the checklist. The weights are relative: the score is normalised by their sum.', summary: 'Add a qualification criterion', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpQualificationCriterionRequest')), tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 201, description: 'Criterion created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpQualificationCriterion')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function storeCriterion(QualificationCriterionRequest $request): JsonResponse
    {
        $this->authorize('create', QualificationCriterion::class);
        $criterion = QualificationCriterion::create([...$request->validated(), 'tenant_id' => $this->tenantId()]);

        return (new QualificationCriterionResource($criterion->refresh()))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/supplier-portal/qualification-criteria/{id}', operationId: 'updateSupplierQualificationCriterion', description: 'Changes a criterion. Completed assessments keep the score they were given.', summary: 'Update a qualification criterion', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpQualificationCriterionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Criterion updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpQualificationCriterion')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function updateCriterion(QualificationCriterionRequest $request, string $id): QualificationCriterionResource
    {
        $criterion = QualificationCriterion::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $criterion);
        $criterion->update($request->validated());

        return new QualificationCriterionResource($criterion->refresh());
    }

    #[OA\Get(path: '/api/v1/supplier-portal/qualification-assessments', operationId: 'listSupplierQualificationAssessments', description: 'Paginated assessments, newest first. Filters: supplier_id, status.', summary: 'List qualification assessments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [
        new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'completed'])),
        new OA\Parameter(ref: '#/components/parameters/SupplierPortalPerPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Assessments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpQualificationAssessment'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function assessments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QualificationAssessment::class);

        return QualificationAssessmentResource::collection(
            QualificationAssessment::query()
                ->with('scores.criterion')
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('supplier_id'), static fn ($query) => $query->where('supplier_id', (string) $request->query('supplier_id')))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->orderByDesc('created_at')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/supplier-portal/qualification-assessments', operationId: 'storeSupplierQualificationAssessment', description: 'Opens a draft assessment of a supplier, optionally with the first scores (0 to the max score of each criterion).', summary: 'Open a qualification assessment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpQualificationAssessmentRequest')), tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 201, description: 'Assessment opened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpQualificationAssessment')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function storeAssessment(QualificationAssessmentRequest $request): JsonResponse
    {
        $this->authorize('create', QualificationAssessment::class);
        $supplier = $this->supplier((string) $request->validated('supplier_id'));

        $assessment = $this->scoring->start($this->tenantId(), (string) $supplier->id, $request->validated('onboarding_id'), $request->validated('notes'), $request->validated('scores') ?? [], $this->userId());

        return (new QualificationAssessmentResource($assessment))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/supplier-portal/qualification-assessments/{id}', operationId: 'showSupplierQualificationAssessment', description: 'An assessment with its scores.', summary: 'Show a qualification assessment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Assessment', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpQualificationAssessment')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function showAssessment(string $id): QualificationAssessmentResource
    {
        $assessment = $this->find($id);
        $this->authorize('view', $assessment);

        return new QualificationAssessmentResource($assessment);
    }

    #[OA\Put(path: '/api/v1/supplier-portal/qualification-assessments/{id}/scores', operationId: 'recordSupplierQualificationScores', description: 'Records or changes scores of a draft assessment.', summary: 'Record qualification scores', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpRecordScoresRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Scores recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpQualificationAssessment')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function recordScores(QualificationAssessmentRequest $request, string $id): QualificationAssessmentResource
    {
        $assessment = $this->find($id);
        $this->authorize('update', $assessment);

        if ($request->filled('notes')) {
            $assessment->update(['notes' => $request->validated('notes')]);
        }

        return new QualificationAssessmentResource($this->scoring->recordScores($assessment, $request->validated('scores')));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/qualification-assessments/{id}/complete', operationId: 'completeSupplierQualificationAssessment', description: 'Completes the assessment: every active criterion needs a score. The weighted score (0-100, exact decimals), the band (A from 80, B from 60, C below) and the outcome (qualified, conditional, not_qualified) are fixed.', summary: 'Complete a qualification assessment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Assessment completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpQualificationAssessment')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function complete(string $id): QualificationAssessmentResource
    {
        $assessment = $this->find($id);
        $this->authorize('update', $assessment);

        return new QualificationAssessmentResource($this->scoring->complete($assessment, $this->userId()));
    }

    private function find(string $id): QualificationAssessment
    {
        return QualificationAssessment::query()->with('scores.criterion')->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
