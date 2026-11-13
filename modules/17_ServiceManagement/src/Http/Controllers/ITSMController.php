<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Contracts\IncidentManagementServiceInterface;
use Modules\ServiceManagement\Contracts\ProblemAndChangeServiceInterface;
use Modules\ServiceManagement\DTOs\CreateChangeRequestDTO;
use Modules\ServiceManagement\DTOs\CreateIncidentDTO;
use Modules\ServiceManagement\Http\Requests\StoreChangeRequestRequest;
use Modules\ServiceManagement\Http\Requests\StoreIncidentRequest;
use Modules\ServiceManagement\Http\Resources\ChangeRequestResource;
use Modules\ServiceManagement\Http\Resources\IncidentResource;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Incident;
use Modules\ServiceManagement\Models\Problem;
use OpenApi\Attributes as OA;

final class ITSMController extends Controller
{
    public function __construct(
        private readonly IncidentManagementServiceInterface $incidentService,
        private readonly ProblemAndChangeServiceInterface $itsmService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated incidents of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/esm/incidents',
        operationId: 'getIncidentsEsm',
        description: 'Returns the paginated incidents of the active tenant, latest first.',
        summary: 'List incidents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Incidents'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceManagementIncidentResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function incidents(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $incidents = Incident::where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => IncidentResource::collection($incidents),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'total' => $incidents->total(),
            ],
        ]);
    }

    /**
     * Creates an incident in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/esm/incidents',
        operationId: 'storeIncidentEsmIncidents',
        description: 'Creates an incident in the active tenant.',
        summary: 'Create incident',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Incidents'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementStoreIncidentRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Incident created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementIncidentResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeIncident(StoreIncidentRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new CreateIncidentDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            title: $validated['title'],
            description: $validated['description'] ?? null,
            severity: $validated['severity'] ?? 'medium',
            impact: $validated['impact'] ?? 'moderate',
            urgency: $validated['urgency'] ?? 'medium',
            serviceCategoryId: $validated['service_category_id'] ?? null,
            affectedAssetId: $validated['affected_asset_id'] ?? null,
            reportedById: $request->user()?->id
        );

        $incident = $this->incidentService->createIncident($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Incident recorded successfully',
            'data' => new IncidentResource($incident),
        ], 201);
    }

    /**
     * Resolves an incident with the resolution summary.
     */
    #[OA\Post(
        path: '/api/v1/esm/incidents/{incident}/resolve',
        operationId: 'resolveIncidentEsmIncident',
        description: 'Resolves an incident with the resolution summary.',
        summary: 'Resolve incident',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Incidents'],
        parameters: [
            new OA\Parameter(name: 'incident', in: 'path', required: true, description: 'Identifier of the incident', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'resolution_summary')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementIncidentResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function resolveIncident(Incident $incident, Request $request): JsonResponse
    {
        $resolved = $this->incidentService->resolveIncident(
            $incident->id,
            $request->input('resolution_summary'),
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Incident resolved successfully',
            'data' => new IncidentResource($resolved),
        ]);
    }

    /**
     * Returns the paginated problem records of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/esm/problems',
        operationId: 'getProblemsEsm',
        description: 'Returns the paginated problem records of the active tenant, latest first.',
        summary: 'List problems',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Problems'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceManagementProblemModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function problems(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $problems = Problem::where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => $problems,
        ]);
    }

    /**
     * Returns the paginated change requests of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/esm/changes',
        operationId: 'getChangesEsm',
        description: 'Returns the paginated change requests of the active tenant, latest first.',
        summary: 'List change requests',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Changes'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceManagementChangeRequestResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function changes(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $changes = ChangeRequest::where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => ChangeRequestResource::collection($changes),
            'meta' => [
                'current_page' => $changes->currentPage(),
                'total' => $changes->total(),
            ],
        ]);
    }

    /**
     * Creates a change request in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/esm/changes',
        operationId: 'storeChangeEsmChanges',
        description: 'Creates a change request in the active tenant.',
        summary: 'Create change request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Changes'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementStoreChangeRequestRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Change created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementChangeRequestResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeChange(StoreChangeRequestRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new CreateChangeRequestDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            title: $validated['title'],
            description: $validated['description'] ?? null,
            changeType: $validated['change_type'] ?? 'standard',
            riskLevel: $validated['risk_level'] ?? 'medium',
            priority: $validated['priority'] ?? 'medium',
            serviceCategoryId: $validated['service_category_id'] ?? null,
            rolloutPlan: $validated['rollout_plan'] ?? null,
            rollbackPlan: $validated['rollback_plan'] ?? null,
            testPlan: $validated['test_plan'] ?? null,
        );

        $change = $this->itsmService->createChangeRequest($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Change request submitted successfully',
            'data' => new ChangeRequestResource($change),
        ], 201);
    }

    /**
     * Approves a change request on behalf of the authenticated user.
     */
    #[OA\Post(
        path: '/api/v1/esm/changes/{change}/approve',
        operationId: 'approveChangeEsmChange',
        description: 'Approves a change request on behalf of the authenticated user.',
        summary: 'Approve change request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Changes'],
        parameters: [
            new OA\Parameter(name: 'change', in: 'path', required: true, description: 'Identifier of the change', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementChangeRequestResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approveChange(ChangeRequest $change, Request $request): JsonResponse
    {
        $approved = $this->itsmService->approveChangeRequest(
            $change->id,
            $request->user()?->id ?? 'system',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Change request approved successfully',
            'data' => new ChangeRequestResource($approved),
        ]);
    }
}
