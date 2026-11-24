<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\SaveLossReasonRequest;
use Modules\CRM\Models\LossReason;
use Modules\CRM\Services\SalesPipelineService;
use OpenApi\Attributes as OA;

/**
 * Catalog of structured loss reasons behind the mandatory loss questionnaire.
 */
final class LossReasonController extends Controller
{
    public function __construct(
        private readonly SalesPipelineService $pipelines,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Lists the loss reasons of the tenant, localized.
     */
    #[OA\Get(
        path: '/api/v1/crm/loss-reasons',
        operationId: 'listCrmLossReasons',
        description: 'Lists the loss reasons of the active tenant in display order, localized in the active language (it-CH, de-CH, fr-CH, en, it-IT) from the seeded catalog.',
        summary: 'List loss reasons',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        responses: [
            new OA\Response(response: 200, description: 'Loss reasons', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        ]
    )]
    public function index(): JsonResponse
    {
        $reasons = LossReason::where('tenant_id', $this->tenantContext->getTenantId())->orderBy('sequence')->orderBy('code')->get();

        return response()->json(['data' => $reasons->map(fn (LossReason $reason): array => $this->present($reason))->values()]);
    }

    /**
     * Creates a loss reason.
     */
    #[OA\Post(
        path: '/api/v1/crm/loss-reasons',
        operationId: 'createCrmLossReason',
        description: 'Creates a loss reason. The code is unique in the tenant and cannot be changed later.',
        summary: 'Create loss reason',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['code', 'name'], properties: [
            new OA\Property(property: 'code', type: 'string', maxLength: 50),
            new OA\Property(property: 'name', type: 'string', maxLength: 150),
            new OA\Property(property: 'translations', type: 'object', nullable: true),
            new OA\Property(property: 'requires_competitor', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Created loss reason', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(SaveLossReasonRequest $request): JsonResponse
    {
        $reason = $this->pipelines->createLossReason((string) $this->tenantContext->getTenantId(), $request->validated());

        return response()->json($this->present($reason), 201);
    }

    /**
     * Updates a loss reason.
     */
    #[OA\Put(
        path: '/api/v1/crm/loss-reasons/{id}',
        operationId: 'updateCrmLossReason',
        description: 'Updates a loss reason (labels, order, activation). Also accepts the PATCH method.',
        summary: 'Update loss reason',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the loss reason', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Updated loss reason', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(SaveLossReasonRequest $request, string $id): JsonResponse
    {
        $reason = LossReason::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);

        return response()->json($this->present($this->pipelines->updateLossReason($reason, $request->validated())));
    }

    /**
     * Deletes an unused loss reason.
     */
    #[OA\Delete(
        path: '/api/v1/crm/loss-reasons/{id}',
        operationId: 'deleteCrmLossReason',
        description: 'Deletes a loss reason that no opportunity uses. A used reason answers 422: deactivate it instead.',
        summary: 'Delete loss reason',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Pipeline'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the loss reason', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 422, description: 'Loss reason in use', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $reason = LossReason::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
        $this->pipelines->deleteLossReason($reason);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LossReason $reason): array
    {
        return [
            'id' => (string) $reason->id,
            'code' => $reason->code,
            'name' => $reason->label(),
            'translations' => $reason->translations,
            'requires_competitor' => $reason->requires_competitor,
            'sequence' => $reason->sequence,
            'is_active' => $reason->is_active,
        ];
    }
}
