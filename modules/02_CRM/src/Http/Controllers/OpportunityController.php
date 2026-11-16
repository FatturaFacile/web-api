<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\OpportunityServiceInterface;
use Modules\CRM\DTOs\CreateOpportunityDTO;
use Modules\CRM\DTOs\UpdateOpportunityStageDTO;
use Modules\CRM\Http\Requests\StoreOpportunityRequest;
use Modules\CRM\Http\Requests\UpdateOpportunityStageRequest;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Models\Opportunity;
use OpenApi\Attributes as OA;

final class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityServiceInterface $opportunityService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of opportunities of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/opportunities',
        operationId: 'listCrmOpportunities',
        description: 'Returns the paginated list of opportunities of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List opportunities',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'forecast_category', in: 'query', required: false, description: 'Filter by forecast category', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'stage_code', in: 'query', required: false, description: 'Filter by pipeline stage code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Text contained in the name or the opportunity number', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of opportunities', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMOpportunityResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Opportunity::where('tenant_id', $tenant->id)->with(['account', 'stage']);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('forecast_category')) {
            $query->where('forecast_category', $request->query('forecast_category'));
        }

        if ($request->filled('stage_code')) {
            $query->whereHas('stage', fn ($stage) => $stage->where('code', $request->query('stage_code')));
        }

        if ($request->filled('search')) {
            $term = (string) $request->query('search');
            $query->where(function ($search) use ($term): void {
                $search->whereLike('name', "%{$term}%")->orWhereLike('opportunity_number', "%{$term}%");
            });
        }

        $opportunities = $query->orderByDesc('expected_revenue')->gridPaginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => OpportunityResource::collection($opportunities),
            'meta' => [
                'current_page' => $opportunities->currentPage(),
                'last_page' => $opportunities->lastPage(),
                'per_page' => $opportunities->perPage(),
                'total' => $opportunities->total(),
            ],
        ]);
    }

    /**
     * Creates a new opportunity in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/crm/opportunities',
        operationId: 'createCrmOpportunity',
        description: 'Creates a new opportunity in the active tenant.',
        summary: 'Create opportunity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMStoreOpportunityRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Opportunity created', content: new OA\JsonContent(ref: '#/components/schemas/CRMOpportunityResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $opportunity = $this->opportunityService->createOpportunity(new CreateOpportunityDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            accountId: $request->input('account_id'),
            contactId: $request->input('contact_id'),
            name: $request->input('name'),
            amount: (float) $request->input('amount'),
            currency: $request->input('currency', 'EUR'),
            stageCode: $request->input('stage_code', 'discovery'),
            forecastCategory: $request->input('forecast_category', 'pipeline'),
            expectedCloseDate: $request->input('expected_close_date'),
            ownerUserId: $request->input('owner_user_id'),
            campaignId: $request->input('campaign_id'),
            leadId: $request->input('lead_id'),
            discountPercentage: (float) $request->input('discount_percentage', 0.0),
            lineItems: $request->input('line_items', []),
            tags: $request->input('tags', [])
        ), $actorId);

        return response()->json(new OpportunityResource($opportunity), 201);
    }

    /**
     * Returns a single opportunity of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/opportunities/{opportunity}',
        operationId: 'getCrmOpportunity',
        description: 'Returns a single opportunity of the active tenant.',
        summary: 'Get opportunity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [
            new OA\Parameter(name: 'opportunity', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMOpportunityResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new OpportunityResource($opp));
    }

    /**
     * Moves an opportunity to the target stage, optionally updating amount and probability.
     */
    #[OA\Post(
        path: '/api/v1/crm/opportunities/{id}/stage',
        operationId: 'changeStageCrmOpportunity',
        description: 'Moves an opportunity to the target stage, optionally updating amount and probability.',
        summary: 'Change opportunity stage',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMUpdateOpportunityStageRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMOpportunityResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function changeStage(UpdateOpportunityStageRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $updated = $this->opportunityService->changeStage($opp, new UpdateOpportunityStageDTO(
            targetStageCode: $request->input('target_stage_code'),
            amount: $request->input('amount') ? (float) $request->input('amount') : null,
            probability: $request->input('probability') ? (float) $request->input('probability') : null,
            forecastCategory: $request->input('forecast_category'),
            winLossReason: $request->input('win_loss_reason'),
            competitor: $request->input('competitor'),
            actualCloseDate: $request->input('actual_close_date')
        ), $actorId);

        return response()->json(new OpportunityResource($updated));
    }

    /**
     * Creates a sales quote from an opportunity and returns its identifier, number and status.
     */
    #[OA\Post(
        path: '/api/v1/crm/opportunities/{id}/quote',
        operationId: 'requestQuoteCrmOpportunity',
        description: 'Creates a sales quote from an opportunity and returns its identifier, number and status.',
        summary: 'Request quote from opportunity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'sales_quote_id', type: 'string'), new OA\Property(property: 'quote_number'), new OA\Property(property: 'status')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function requestQuote(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $quote = $this->opportunityService->requestQuote($opp, $actorId);

        return response()->json([
            'sales_quote_id' => (string) $quote->id,
            'quote_number' => $quote->quote_number,
            'status' => $quote->status,
        ]);
    }

    /**
     * Creates a project from an opportunity and returns the identifiers of both.
     */
    #[OA\Post(
        path: '/api/v1/crm/opportunities/{id}/project',
        operationId: 'convertToProjectCrmOpportunity',
        description: 'Creates a project from an opportunity and returns the identifiers of both.',
        summary: 'Convert opportunity to project',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'opportunity_id', type: 'string'), new OA\Property(property: 'project_id')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function convertToProject(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $result = $this->opportunityService->convertToProject($opp, $actorId);

        return response()->json([
            'opportunity_id' => (string) $result['opportunity']->id,
            'project_id' => $result['project_id'],
        ]);
    }

    /**
     * Deletes a opportunity of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/crm/opportunities/{opportunity}',
        operationId: 'deleteCrmOpportunity',
        description: 'Deletes a opportunity of the active tenant.',
        summary: 'Delete opportunity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Opportunities'],
        parameters: [
            new OA\Parameter(name: 'opportunity', in: 'path', required: true, description: 'Identifier of the opportunity', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Completed, no content returned'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $opp->delete();

        return response()->json(null, 204);
    }
}
