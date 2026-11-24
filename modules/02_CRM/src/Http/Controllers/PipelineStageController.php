<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\SavePipelineStageRequest;
use Modules\CRM\Models\OpportunityStage;
use Modules\CRM\Services\PipelineStageService;
use OpenApi\Attributes as OA;

/**
 * Per-tenant configuration of the sales pipeline stages and their win probabilities.
 */
final class PipelineStageController extends Controller
{
    public function __construct(
        private readonly PipelineStageService $stageService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Lists the stages of the tenant in pipeline order.
     */
    #[OA\Get(
        path: '/api/v1/crm/pipeline-stages',
        operationId: 'listCrmPipelineStages',
        description: 'Lists the pipeline stages of the active tenant in pipeline order, with their win probability.',
        summary: 'List pipeline stages',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        responses: [
            new OA\Response(response: 200, description: 'Stages', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMPipelineStage'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $stages = OpportunityStage::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('sequence')->orderBy('code')->get();

        return response()->json(['data' => $stages->map(fn (OpportunityStage $stage): array => $this->present($stage))->values()]);
    }

    /**
     * Creates a stage.
     */
    #[OA\Post(
        path: '/api/v1/crm/pipeline-stages',
        operationId: 'createCrmPipelineStage',
        description: 'Creates a pipeline stage. The code is unique in the tenant and cannot be changed later.',
        summary: 'Create pipeline stage',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMSavePipelineStageRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Stage created', content: new OA\JsonContent(ref: '#/components/schemas/CRMPipelineStage')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SavePipelineStageRequest $request): JsonResponse
    {
        try {
            $stage = $this->stageService->create((string) $this->tenantContext->getTenantId(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->present($stage), 201);
    }

    /**
     * Installs the standard stages the tenant does not have.
     */
    #[OA\Post(
        path: '/api/v1/crm/pipeline-stages/defaults',
        operationId: 'installCrmDefaultPipelineStages',
        description: 'Installs the standard stages (discovery, qualification, proposal, negotiation, closed won, closed lost) that the active tenant does not have yet. Existing stages are not changed, so the call can be repeated.',
        summary: 'Install default pipeline stages',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        responses: [
            new OA\Response(response: 200, description: 'Stages installed', content: new OA\JsonContent(properties: [new OA\Property(property: 'created', type: 'integer'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMPipelineStage'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function installDefaults(): JsonResponse
    {
        $created = $this->stageService->installDefaults((string) $this->tenantContext->getTenantId());
        $stages = OpportunityStage::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('sequence')->get();

        return response()->json(['created' => $created, 'data' => $stages->map(fn (OpportunityStage $stage): array => $this->present($stage))->values()]);
    }

    /**
     * Updates a stage, optionally repricing its open opportunities.
     */
    #[OA\Put(
        path: '/api/v1/crm/pipeline-stages/{id}',
        operationId: 'updateCrmPipelineStage',
        description: 'Updates a pipeline stage. With reprice_open_opportunities, a new default_probability is applied to the open opportunities of the stage (probability and expected revenue), so weighted pipeline and forecasts follow. Also accepts the PATCH method.',
        summary: 'Update pipeline stage',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the stage', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMSavePipelineStageRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Stage updated', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/CRMPipelineStage'), new OA\Schema(properties: [new OA\Property(property: 'repriced_opportunities', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SavePipelineStageRequest $request, string $id): JsonResponse
    {
        $stage = OpportunityStage::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);

        try {
            $result = $this->stageService->update($stage, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([...$this->present($result['stage']), 'repriced_opportunities' => $result['repriced']]);
    }

    /**
     * Deletes an unused stage.
     */
    #[OA\Delete(
        path: '/api/v1/crm/pipeline-stages/{id}',
        operationId: 'deleteCrmPipelineStage',
        description: 'Deletes a pipeline stage that no opportunity uses. A used stage answers 409: deactivate it instead.',
        summary: 'Delete pipeline stage',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the stage', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Stage in use', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $stage = OpportunityStage::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);

        try {
            $this->stageService->delete($stage);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OpportunityStage $stage): array
    {
        return [
            'id' => (string) $stage->id,
            'code' => $stage->code,
            'name' => $stage->name,
            'sequence' => $stage->sequence,
            'default_probability' => (float) $stage->default_probability,
            'is_won' => $stage->is_won,
            'is_lost' => $stage->is_lost,
            'stale_days' => $stage->stale_days,
            'is_active' => $stage->is_active,
        ];
    }
}
