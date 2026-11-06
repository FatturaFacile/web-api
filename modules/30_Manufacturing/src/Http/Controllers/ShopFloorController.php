<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\DTOs\RecordShopFloorExecutionDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\RecordShopFloorExecutionRequest;
use Modules\Manufacturing\Http\Resources\MfgShopFloorExecutionResource;
use OpenApi\Attributes as OA;

final class ShopFloorController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private ShopFloorServiceInterface $shopFloorService
    ) {}

    /**
     * Records the execution of a production order operation on a work center.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/shop-floor/executions',
        operationId: 'recordExecutionManufacturingShopFloorExecutions',
        description: 'Records the execution of a production order operation on a work center.',
        summary: 'Record shop floor execution',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Shop Floor'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManufacturingRecordShopFloorExecutionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Execution created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgShopFloorExecutionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function recordExecution(RecordShopFloorExecutionRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RecordShopFloorExecutionDTO(
            tenantId: $tenantId,
            productionOrderId: $validated['production_order_id'],
            productionOrderOperationId: $validated['production_order_operation_id'],
            workCenterId: $validated['work_center_id'],
            operatorId: $validated['operator_id'] ?? null,
            machineId: $validated['machine_id'] ?? null,
            goodUnits: (float) ($validated['good_units'] ?? 0.0),
            scrapUnits: (float) ($validated['scrap_units'] ?? 0.0),
            downtimeReason: $validated['downtime_reason'] ?? null,
            laborLogs: $validated['labor_logs'] ?? [],
            machineLogs: $validated['machine_logs'] ?? []
        );

        $execution = $this->shopFloorService->recordExecution($dto);

        return response()->json([
            'message' => 'Shop floor execution recorded.',
            'data' => new MfgShopFloorExecutionResource($execution),
        ], 201);
    }

    /**
     * Completes a shop floor execution with its good and scrap units.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/shop-floor/executions/{id}/complete',
        operationId: 'completeExecutionManufacturingShopFloorExecution',
        description: 'Completes a shop floor execution with its good and scrap units.',
        summary: 'Complete shop floor execution',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Shop Floor'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the execution', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'good_units', type: 'number', default: 1), new OA\Property(property: 'scrap_units', type: 'number', default: 0)])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgShopFloorExecutionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function completeExecution(Request $request, string $id): JsonResponse
    {
        $goodUnits = (float) $request->input('good_units', 1.0);
        $scrapUnits = (float) $request->input('scrap_units', 0.0);

        $execution = $this->shopFloorService->completeExecution($id, $goodUnits, $scrapUnits);

        return response()->json([
            'message' => 'Shop floor execution completed.',
            'data' => new MfgShopFloorExecutionResource($execution),
        ]);
    }

    /**
     * Records machine downtime on a shop floor execution.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/shop-floor/executions/{id}/downtime',
        operationId: 'recordDowntimeManufacturingShopFloorExecution',
        description: 'Records machine downtime on a shop floor execution.',
        summary: 'Record machine downtime',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Shop Floor'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the execution', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'downtime_minutes', type: 'number', default: 15), new OA\Property(property: 'reason', type: 'string', default: 'Machine adjustment')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgShopFloorExecutionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function recordDowntime(Request $request, string $id): JsonResponse
    {
        $minutes = (float) $request->input('downtime_minutes', 15.0);
        $reason = (string) $request->input('reason', 'Machine adjustment');

        $execution = $this->shopFloorService->recordDowntime($id, $minutes, $reason);

        return response()->json([
            'message' => 'Machine downtime recorded.',
            'data' => new MfgShopFloorExecutionResource($execution),
        ]);
    }
}
