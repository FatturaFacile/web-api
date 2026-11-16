<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Sustainability\Http\Controllers\Concerns\InteractsWithSustainability;
use Modules\Sustainability\Http\Requests\MaterialityAssessmentRequest;
use Modules\Sustainability\Http\Resources\MaterialityAssessmentResource;
use Modules\Sustainability\Models\EsgMaterialityAssessment;
use Modules\Sustainability\Services\MaterialityService;
use OpenApi\Attributes as OA;

/**
 * Double materiality assessments (impact and financial materiality, ESRS 1).
 */
final class MaterialityController extends Controller
{
    use InteractsWithSustainability;

    public function __construct(private readonly MaterialityService $materiality) {}

    #[OA\Get(path: '/api/v1/sustainability/materiality-assessments', operationId: 'listEsgMaterialityAssessments', description: 'Materiality assessments with their topics, newest first.', summary: 'List materiality assessments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], responses: [
        new OA\Response(response: 200, description: 'Assessments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SustainabilityMateriality'))])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EsgMaterialityAssessment::class);

        return MaterialityAssessmentResource::collection(
            EsgMaterialityAssessment::query()->with('topics')->where('tenant_id', $this->tenantId())->orderByDesc('created_at')->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/sustainability/materiality-assessments', operationId: 'storeEsgMaterialityAssessment', description: 'Create a draft assessment with scored topics (0-5 on impact and on financial materiality). A topic is material when either score reaches the threshold (default 3).', summary: 'Create a materiality assessment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityMaterialityRequest')), tags: ['Sustainability'], responses: [
        new OA\Response(response: 201, description: 'Assessment created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityMateriality')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function store(MaterialityAssessmentRequest $request): JsonResponse
    {
        $this->authorize('create', EsgMaterialityAssessment::class);

        return (new MaterialityAssessmentResource($this->materiality->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/sustainability/materiality-assessments/{id}', operationId: 'showEsgMaterialityAssessment', description: 'Return an assessment with its topics.', summary: 'Show a materiality assessment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Assessment', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityMateriality')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
    ])]
    public function show(string $id): MaterialityAssessmentResource
    {
        $assessment = EsgMaterialityAssessment::query()->with('topics')->findOrFail($id);
        $this->authorize('view', $assessment);

        return new MaterialityAssessmentResource($assessment);
    }

    #[OA\Put(path: '/api/v1/sustainability/materiality-assessments/{id}', operationId: 'updateEsgMaterialityAssessment', description: 'Change a draft assessment; the topics, when sent, replace the existing ones and materiality is recomputed against the threshold. An approved assessment cannot change.', summary: 'Update a materiality assessment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SustainabilityMaterialityRequest')), tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Assessment updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityMateriality')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function update(MaterialityAssessmentRequest $request, string $id): MaterialityAssessmentResource
    {
        $assessment = EsgMaterialityAssessment::query()->findOrFail($id);
        $this->authorize('update', $assessment);

        return new MaterialityAssessmentResource($this->materiality->update($assessment, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/sustainability/materiality-assessments/{id}/approve', operationId: 'approveEsgMaterialityAssessment', description: 'Approve a draft assessment with at least one topic; it becomes final. Owners, administrators and members granted sustainability.approve only.', summary: 'Approve a materiality assessment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Sustainability'], parameters: [new OA\Parameter(ref: '#/components/parameters/SustainabilityId')], responses: [
        new OA\Response(response: 200, description: 'Assessment approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SustainabilityMateriality')])),
        new OA\Response(ref: '#/components/responses/SustainabilityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SustainabilityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SustainabilityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SustainabilityUnprocessable', response: 422),
    ])]
    public function approve(string $id): MaterialityAssessmentResource
    {
        $assessment = EsgMaterialityAssessment::query()->findOrFail($id);
        $this->authorize('approve', $assessment);

        return new MaterialityAssessmentResource($this->materiality->approve($assessment, $this->userId()));
    }
}
