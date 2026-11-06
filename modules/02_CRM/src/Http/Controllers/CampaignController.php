<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\CampaignServiceInterface;
use Modules\CRM\Http\Requests\StoreCampaignRequest;
use Modules\CRM\Http\Resources\CampaignResource;
use Modules\CRM\Models\Campaign;
use OpenApi\Attributes as OA;

final class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignServiceInterface $campaignService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of campaigns of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/campaigns',
        operationId: 'listCrmCampaigns',
        description: 'Returns the paginated list of campaigns of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List campaigns',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Campaigns'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of campaigns', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMCampaignResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $campaigns = Campaign::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => CampaignResource::collection($campaigns),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Creates a new campaign in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/crm/campaigns',
        operationId: 'createCrmCampaign',
        description: 'Creates a new campaign in the active tenant.',
        summary: 'Create campaign',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Campaigns'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMStoreCampaignRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Campaign created', content: new OA\JsonContent(ref: '#/components/schemas/CRMCampaignResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $campaign = $this->campaignService->createCampaign(array_merge(
            $request->validated(),
            ['tenant_id' => (string) $tenant->id]
        ), $actorId);

        return response()->json(new CampaignResource($campaign), 201);
    }

    /**
     * Returns a single campaign of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/campaigns/{campaign}',
        operationId: 'getCrmCampaign',
        description: 'Returns a single campaign of the active tenant.',
        summary: 'Get campaign',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Campaigns'],
        parameters: [
            new OA\Parameter(name: 'campaign', in: 'path', required: true, description: 'Identifier of the campaign', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMCampaignResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $campaign = Campaign::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new CampaignResource($campaign));
    }

    /**
     * Calculates the return on investment of a campaign.
     */
    #[OA\Get(
        path: '/api/v1/crm/campaigns/{id}/roi',
        operationId: 'getRoiCrmCampaign',
        description: 'Calculates the return on investment of a campaign.',
        summary: 'Get campaign ROI',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Campaigns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the campaign', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'budgeted_cost', type: 'number'), new OA\Property(property: 'actual_cost', type: 'number'), new OA\Property(property: 'expected_revenue', type: 'number'), new OA\Property(property: 'actual_revenue', type: 'number'), new OA\Property(property: 'roi_percentage', type: 'number'), new OA\Property(property: 'total_members', type: 'integer'), new OA\Property(property: 'converted_members', type: 'integer')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function roi(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $campaign = Campaign::where('tenant_id', $tenant->id)->findOrFail($id);

        $roiData = $this->campaignService->calculateROI($campaign);

        return response()->json($roiData);
    }
}
