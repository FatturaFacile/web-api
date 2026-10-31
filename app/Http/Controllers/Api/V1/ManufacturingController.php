<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manufacturing\TrackProductionStageRequest;
use App\Http\Responses\ApiResponse;
use App\Models\BillOfMaterial;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderStage;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ManufacturingController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/manufacturing/bom',
        operationId: 'getBillsOfMaterial',
        description: 'List production Bills of Materials (Distinta Base) with multi-level component trees.',
        summary: 'List bills of materials',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Manufacturing'],
        responses: [
            new OA\Response(response: 200, description: 'Bills of Materials retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function bom(): JsonResponse
    {
        $boms = BillOfMaterial::latest()->paginate(25);

        return $this->successResponse(data: $boms, message: 'Bills of Materials (Distinta Base) retrieved');
    }

    #[OA\Get(
        path: '/api/v1/manufacturing/production-orders',
        operationId: 'getProductionOrders',
        description: 'List manufacturing production orders with scheduled routing stages.',
        summary: 'List production orders',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Manufacturing'],
        responses: [
            new OA\Response(response: 200, description: 'Production orders retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function productionOrders(): JsonResponse
    {
        $orders = ProductionOrder::latest('start_date')->paginate(25);

        return $this->successResponse(data: $orders, message: 'Production orders retrieved');
    }

    #[OA\Post(
        path: '/api/v1/manufacturing/stages/{stageId}/track',
        operationId: 'trackProductionOrderStage',
        description: 'Record actual hours worked and updated progress status for a production order routing stage.',
        summary: 'Track production stage',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Manufacturing'],
        parameters: [
            new OA\Parameter(name: 'stageId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['actual_hours', 'status'],
                properties: [
                    new OA\Property(property: 'actual_hours', type: 'number', format: 'float', example: 4.5),
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'running', 'done']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Stage tracked successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Stage not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function trackStage(TrackProductionStageRequest $request, string $stageId): JsonResponse
    {
        $validated = $request->validated();

        $stage = ProductionOrderStage::whereHas('productionOrder')->findOrFail($stageId);
        $stage->update($validated);

        return $this->successResponse(message: 'Production order stage tracked successfully');
    }
}
