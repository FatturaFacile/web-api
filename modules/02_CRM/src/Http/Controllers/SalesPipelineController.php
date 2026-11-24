<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\SalesDashboardRequest;
use Modules\CRM\Http\Requests\SavePipelineRequest;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Services\SalesBoardService;
use Modules\CRM\Services\SalesPipelineService;
use OpenApi\Attributes as OA;

/**
 * Sales pipelines of the tenant (per business line / market) and their Kanban board.
 */
final class SalesPipelineController extends Controller
{
    public function __construct(
        private readonly SalesPipelineService $pipelines,
        private readonly SalesBoardService $boards,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Lists the pipelines of the tenant with their stages, localized in the active language.
     */
    #[OA\Get(
        path: '/api/v1/crm/pipelines',
        operationId: 'listCrmSalesPipelines',
        description: 'Lists the sales pipelines of the active tenant with their ordered stages. The default pipeline is created on first use. Names are served localized (it-CH, de-CH, fr-CH, en, it-IT) from the seeded catalog.',
        summary: 'List sales pipelines',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        responses: [
            new OA\Response(response: 200, description: 'Pipelines with stages', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $this->pipelines->ensureDefaultPipeline($tenantId);

        $pipelines = Pipeline::where('tenant_id', $tenantId)->with('stages')->orderBy('sequence')->orderBy('code')->get();

        return response()->json(['data' => $pipelines->map(fn (Pipeline $pipeline): array => $this->present($pipeline, true))->values()]);
    }

    /**
     * Creates a pipeline, optionally with the standard stages.
     */
    #[OA\Post(
        path: '/api/v1/crm/pipelines',
        operationId: 'createCrmSalesPipeline',
        description: 'Creates a sales pipeline for a business line or market. With install_standard_stages the six standard stages are created with a code prefix derived from the pipeline code.',
        summary: 'Create sales pipeline',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['code', 'name'], properties: [
            new OA\Property(property: 'code', type: 'string', maxLength: 40),
            new OA\Property(property: 'name', type: 'string', maxLength: 150),
            new OA\Property(property: 'business_line', type: 'string', nullable: true),
            new OA\Property(property: 'market', type: 'string', nullable: true),
            new OA\Property(property: 'translations', type: 'object', nullable: true),
            new OA\Property(property: 'is_default', type: 'boolean'),
            new OA\Property(property: 'install_standard_stages', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Created pipeline', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(SavePipelineRequest $request): JsonResponse
    {
        $pipeline = $this->pipelines->createPipeline((string) $this->tenantContext->getTenantId(), $request->validated());

        return response()->json($this->present($pipeline->load('stages'), true), 201);
    }

    /**
     * Returns one pipeline with its stages.
     */
    #[OA\Get(
        path: '/api/v1/crm/pipelines/{id}',
        operationId: 'getCrmSalesPipeline',
        description: 'Returns a sales pipeline of the active tenant with its stages.',
        summary: 'Get sales pipeline',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the pipeline', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Pipeline', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $pipeline = Pipeline::where('tenant_id', $this->tenantContext->getTenantId())->with('stages')->findOrFail($id);

        return response()->json($this->present($pipeline, true));
    }

    /**
     * Updates a pipeline.
     */
    #[OA\Put(
        path: '/api/v1/crm/pipelines/{id}',
        operationId: 'updateCrmSalesPipeline',
        description: 'Updates a sales pipeline; is_default makes it the default one. The default pipeline cannot be deactivated. Also accepts the PATCH method.',
        summary: 'Update sales pipeline',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the pipeline', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Updated pipeline', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(SavePipelineRequest $request, string $id): JsonResponse
    {
        $pipeline = Pipeline::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $updated = $this->pipelines->updatePipeline($pipeline, $request->validated());

        return response()->json($this->present($updated->load('stages'), true));
    }

    /**
     * Deletes an unused, non-default pipeline.
     */
    #[OA\Delete(
        path: '/api/v1/crm/pipelines/{id}',
        operationId: 'deleteCrmSalesPipeline',
        description: 'Deletes a pipeline that no opportunity uses. The default pipeline and pipelines in use answer 422: deactivate them instead.',
        summary: 'Delete sales pipeline',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the pipeline', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 422, description: 'Pipeline in use or default', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $pipeline = Pipeline::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $this->pipelines->deletePipeline($pipeline);

        return response()->json(null, 204);
    }

    /**
     * Installs the standard pipelines, stages and loss reasons the tenant lacks.
     */
    #[OA\Post(
        path: '/api/v1/crm/pipelines/catalog/install',
        operationId: 'installCrmSalesPipelineCatalog',
        description: 'Installs the standard pipelines, stages and loss reasons (localized it-CH, de-CH, fr-CH, en, it-IT) that the active tenant does not have yet. Existing rows are not changed, so the call can be repeated.',
        summary: 'Install the sales pipeline catalog',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        responses: [
            new OA\Response(response: 200, description: 'Rows created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'created', type: 'object')])),
        ]
    )]
    public function installCatalog(): JsonResponse
    {
        return response()->json(['created' => $this->pipelines->installCatalog((string) $this->tenantContext->getTenantId())]);
    }

    /**
     * The Kanban board of a pipeline: one column per stage with its deals and raw / weighted totals.
     */
    #[OA\Get(
        path: '/api/v1/crm/pipelines/{id}/board',
        operationId: 'getCrmSalesPipelineBoard',
        description: 'Returns the Kanban board of a pipeline: ordered stage columns with their deals and the deal value and weighted value (deal value x probability) totals. Filters: owner_user_id, from/to (expected close date), forecast_category, search, per_column.',
        summary: 'Get pipeline board',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the pipeline, or "default"', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'owner_user_id', in: 'query', required: false, description: 'Sales rep', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Earliest expected close date', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'Latest expected close date', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'forecast_category', in: 'query', required: false, description: 'Forecast category', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Text in name or number', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Board', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function board(SalesDashboardRequest $request, string $id): JsonResponse
    {
        $board = $this->boards->board((string) $this->tenantContext->getTenantId(), $id === 'default' ? null : $id, $request->validated());

        return response()->json([
            'pipeline' => $this->present($board['pipeline'], false),
            'columns' => array_map(fn (array $column): array => [
                'stage' => $this->presentStage($column['stage']),
                'totals' => $column['totals'],
                'opportunities' => OpportunityResource::collection($column['opportunities'])->resolve(),
            ], $board['columns']),
            'totals' => $board['totals'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Pipeline $pipeline, bool $withStages): array
    {
        $data = [
            'id' => (string) $pipeline->id,
            'code' => $pipeline->code,
            'name' => $pipeline->label(),
            'business_line' => $pipeline->business_line,
            'market' => $pipeline->market,
            'currency' => $pipeline->currency,
            'translations' => $pipeline->translations,
            'sequence' => $pipeline->sequence,
            'is_default' => $pipeline->is_default,
            'is_active' => $pipeline->is_active,
            'opportunities_count' => Opportunity::where('pipeline_id', $pipeline->id)->count(),
        ];

        if ($withStages) {
            $data['stages'] = $pipeline->stages->map(fn (OpportunityStage $stage): array => $this->presentStage($stage))->values()->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStage(OpportunityStage $stage): array
    {
        return [
            'id' => (string) $stage->id,
            'pipeline_id' => $stage->pipeline_id,
            'code' => $stage->code,
            'name' => $stage->label(),
            'translations' => $stage->translations,
            'sequence' => $stage->sequence,
            'default_probability' => (float) $stage->default_probability,
            'forecast_category' => $stage->forecast_category,
            'is_won' => $stage->is_won,
            'is_lost' => $stage->is_lost,
            'is_active' => $stage->is_active,
            'stale_days' => $stage->stale_days,
        ];
    }
}
