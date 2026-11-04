<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\FieldService\SaveWorkOrder;
use App\Actions\FieldService\SignWorkOrder;
use App\Actions\Sales\ConvertToInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldService\SignWorkOrderRequest;
use App\Http\Requests\FieldService\StoreWorkOrderRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\WorkOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Work Orders', description: 'Work orders (rapportini) and field service operations')]
class WorkOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaveWorkOrder $save,
        private readonly SignWorkOrder $sign,
        private readonly ConvertToInvoice $toInvoice,
    ) {}

    #[OA\Post(
        path: '/api/v1/field-service/work-orders',
        summary: 'Create work order',
        description: 'Create a new work order report',
        tags: ['Work Orders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_id', 'date'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Work order created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource($this->save->create($request->validated())),
            __('work_orders.messages.created'),
            Response::HTTP_CREATED
        );
    }

    #[OA\Get(
        path: '/api/v1/field-service/work-orders/{id}',
        summary: 'Get work order',
        description: 'Retrieve work order details and lines',
        tags: ['Work Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Work order retrieved successfully'),
            new OA\Response(response: 404, description: 'Work order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource(WorkOrder::with(['client', 'lines'])->findOrFail($id)),
            __('work_orders.messages.retrieved')
        );
    }

    #[OA\Put(
        path: '/api/v1/field-service/work-orders/{id}',
        summary: 'Update work order',
        description: 'Update work order details and lines',
        tags: ['Work Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Work order updated successfully'),
            new OA\Response(response: 404, description: 'Work order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function update(StoreWorkOrderRequest $request, string $id): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource($this->save->update(WorkOrder::findOrFail($id), $request->validated())),
            __('work_orders.messages.updated')
        );
    }

    #[OA\Post(
        path: '/api/v1/field-service/work-orders/{id}/complete',
        summary: 'Complete work order',
        description: 'Mark work order as completed',
        tags: ['Work Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Work order marked as completed'),
            new OA\Response(response: 404, description: 'Work order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        return $this->successResponse(
            new WorkOrderResource($this->save->complete(WorkOrder::findOrFail($id))),
            __('work_orders.messages.completed')
        );
    }

    /**
     * Store the customer's signature (tablet or smartphone) on the work report.
     */
    #[OA\Post(
        path: '/api/v1/field-service/work-orders/{id}/signature',
        summary: 'Sign work order',
        description: 'Save customer digital signature on work order',
        tags: ['Work Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['signed_by', 'signature_base64'],
                properties: [
                    new OA\Property(property: 'signed_by', type: 'string'),
                    new OA\Property(property: 'signature_base64', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Work order signed successfully'),
            new OA\Response(response: 404, description: 'Work order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function signature(SignWorkOrderRequest $request, string $id): JsonResponse
    {
        $workOrder = $this->sign->execute(WorkOrder::findOrFail($id), $request->validated('signed_by'), $request->validated('signature_base64'));

        return $this->successResponse(new WorkOrderResource($workOrder), __('work_orders.messages.signed'));
    }

    #[OA\Post(
        path: '/api/v1/field-service/work-orders/{id}/invoice',
        summary: 'Convert work order to invoice',
        description: 'Generate an invoice from a completed work order',
        tags: ['Work Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Invoice created successfully'),
            new OA\Response(response: 404, description: 'Work order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function invoice(string $id): JsonResponse
    {
        $invoice = $this->toInvoice->fromWorkOrder(WorkOrder::with('lines')->findOrFail($id));

        return $this->successResponse(new InvoiceResource($invoice), __('work_orders.messages.invoiced'), Response::HTTP_CREATED);
    }
}
