<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Actions\SubmitCSATAction;
use Modules\ServiceManagement\DTOs\SubmitCSATDTO;
use Modules\ServiceManagement\Http\Requests\SubmitCSATRequest;
use Modules\ServiceManagement\Http\Resources\CSATSurveyResource;
use Modules\ServiceManagement\Http\Resources\ServiceContractResource;
use Modules\ServiceManagement\Models\ServiceContract;
use OpenApi\Attributes as OA;

final class ServiceContractController extends Controller
{
    public function __construct(
        private readonly SubmitCSATAction $csatAction,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of service contracts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/esm/service-contracts',
        operationId: 'listEsmServiceContracts',
        description: 'Returns the paginated list of service contracts of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List service contracts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of service contracts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceManagementServiceContractResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = ServiceContract::where('tenant_id', $tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $contracts = $query->orderByDesc('created_at')->gridPaginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => ServiceContractResource::collection($contracts),
            'meta' => [
                'current_page' => $contracts->currentPage(),
                'total' => $contracts->total(),
            ],
        ]);
    }

    /**
     * Returns a single service contract of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/esm/service-contracts/{serviceContract}',
        operationId: 'getEsmServiceContract',
        description: 'Returns a single service contract of the active tenant.',
        summary: 'Get service contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Service Contracts'],
        parameters: [
            new OA\Parameter(name: 'serviceContract', in: 'path', required: true, description: 'Identifier of the service contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementServiceContractResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(ServiceContract $serviceContract): JsonResponse
    {
        return response()->json([
            'data' => new ServiceContractResource($serviceContract),
        ]);
    }

    /**
     * Records a customer satisfaction score, with optional feedback.
     */
    #[OA\Post(
        path: '/api/v1/esm/csat',
        operationId: 'submitCsatEsmCsat',
        description: 'Records a customer satisfaction score, with optional feedback.',
        summary: 'Submit CSAT response',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM CSAT'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementSubmitCSATRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Csat created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementCSATSurveyResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function submitCsat(SubmitCSATRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new SubmitCSATDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            score: (int) $validated['score'],
            feedback: $validated['feedback'] ?? null,
            ticketId: $validated['ticket_id'] ?? null,
            workOrderId: $validated['work_order_id'] ?? null,
            customerId: $validated['customer_id'] ?? null,
            respondentEmail: $validated['respondent_email'] ?? null
        );

        $survey = $this->csatAction->execute($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Customer satisfaction feedback recorded',
            'data' => new CSATSurveyResource($survey),
        ], 201);
    }
}
