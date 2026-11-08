<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\CreateProductionOrderRequest;
use Modules\Manufacturing\Http\Requests\PostingDateRequest;
use Modules\Manufacturing\Http\Requests\ReasonRequest;
use Modules\Manufacturing\Http\Requests\RescheduleRequest;
use Modules\Manufacturing\Http\Resources\MfgProductionOrderResource;
use Modules\Manufacturing\Models\MfgProductionOrder;
use OpenApi\Attributes as OA;

/**
 * Production orders: list, create, read, reschedule and the lifecycle (release, start, complete, close, cancel).
 */
final class ProductionOrderController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly ProductionPlanningServiceInterface $planning,
        private readonly ProductionExecutionServiceInterface $execution,
    ) {}

    /**
     * Paginated production orders.
     */
    #[OA\Get(path: '/api/v1/manufacturing/production-orders', operationId: 'listManufacturingProductionOrders', description: 'Paginated production orders of the active company, filtered by status and item.', summary: 'List production orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(ref: '#/components/parameters/MfgItemFilter'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Production orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgProductionOrder'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgProductionOrder::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('mfg_item_id')) {
            $query->where('mfg_item_id', (string) $request->query('mfg_item_id'));
        }

        return MfgProductionOrderResource::collection($query->latest()->paginate($this->perPage($request)));
    }

    /**
     * Creates a planned production order.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders', operationId: 'createManufacturingProductionOrder', description: 'Creates a planned order on the BOM and routing given, or on the revisions effective on the planned start date.', summary: 'Create production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgCreateProductionOrderRequest')),
        responses: [new OA\Response(response: 201, description: 'Order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function store(CreateProductionOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $order = $this->planning->planOrder(new CreateProductionOrderDTO(
            tenantId: $this->tenantId(),
            mfgItemId: (string) $data['mfg_item_id'],
            targetQuantity: (string) $data['target_quantity'],
            plannedStartDate: (string) $data['planned_start_date'],
            plannedEndDate: (string) $data['planned_end_date'],
            bomId: $data['bom_id'] ?? null,
            routingId: $data['routing_id'] ?? null,
            projectId: $data['project_id'] ?? null,
            notes: $data['notes'] ?? null,
            salesOrderId: $data['sales_order_id'] ?? null,
            warehouseId: $data['warehouse_id'] ?? null,
            receiptWarehouseId: $data['receipt_warehouse_id'] ?? null,
            createdBy: $this->actor($request),
        ));

        return (new MfgProductionOrderResource($order))->response()->setStatusCode(201);
    }

    /**
     * One production order with lines and operations.
     */
    #[OA\Get(path: '/api/v1/manufacturing/production-orders/{id}', operationId: 'getManufacturingProductionOrder', description: 'Returns a production order of the active company with its component lines and operations.', summary: 'Get production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Production order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgProductionOrderResource
    {
        return new MfgProductionOrderResource($this->owned(MfgProductionOrder::class, $id)->load(['lines', 'operations']));
    }

    /**
     * Moves the planned dates of an order not yet started.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/reschedule', operationId: 'rescheduleManufacturingProductionOrder', description: 'Moves the planned dates of a planned or released order.', summary: 'Reschedule production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgRescheduleRequest')),
        responses: [new OA\Response(response: 200, description: 'Order rescheduled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function reschedule(RescheduleRequest $request, string $id): MfgProductionOrderResource
    {
        $order = $this->executable($id);
        $data = $request->validated();

        return new MfgProductionOrderResource($this->planning->scheduleOrder($order, (string) $data['planned_start_date'], (string) $data['planned_end_date']));
    }

    /**
     * Releases a planned order.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/release', operationId: 'releaseManufacturingProductionOrder', description: 'Releases a planned order: freezes the standard unit cost, explodes the components of the BOM revision, reserves them in the warehouse (as far as stock allows) and copies and schedules the routing operations on the work centre calendars.', summary: 'Release production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Order released', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function release(Request $request, string $id): MfgProductionOrderResource
    {
        return new MfgProductionOrderResource($this->execution->releaseOrder($this->executable($id), $this->actor($request)));
    }

    /**
     * Starts a released order.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/start', operationId: 'startManufacturingProductionOrder', description: 'Marks a released order as in progress (the first issue, confirmation or receipt does it as well).', summary: 'Start production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Order started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function start(Request $request, string $id): MfgProductionOrderResource
    {
        return new MfgProductionOrderResource($this->execution->startOrder($this->executable($id), $this->actor($request)));
    }

    /**
     * Completes an order technically.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/complete', operationId: 'completeManufacturingProductionOrder', description: 'Completes the order technically: no more issues, confirmations or receipts; remaining reservations are released. Components still at a subcontractor block it.', summary: 'Complete production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Order completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function complete(Request $request, string $id): MfgProductionOrderResource
    {
        return new MfgProductionOrderResource($this->execution->completeOrder($this->executable($id), $this->actor($request)));
    }

    /**
     * Closes a completed order and settles its variances.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/close', operationId: 'closeManufacturingProductionOrder', description: 'Closes a completed order (manufacturing managers only): material, labour, overhead and subcontracting variances are computed from the actual costs against the standard of the output received, and the WIP balance is posted to the variance accounts on the posting date.', summary: 'Close production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/MfgPostingDateRequest')),
        responses: [new OA\Response(response: 200, description: 'Order closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function close(PostingDateRequest $request, string $id): MfgProductionOrderResource
    {
        $order = $this->owned(MfgProductionOrder::class, $id);
        $this->authorize('close', $order);

        return new MfgProductionOrderResource($this->execution->closeOrder($order, $request->validated('posting_date'), $this->actor($request)));
    }

    /**
     * Cancels an order that moved nothing.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/cancel', operationId: 'cancelManufacturingProductionOrder', description: 'Cancels a planned or released order without issues, confirmations or receipts, releasing its reservations.', summary: 'Cancel production order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgReasonRequest')),
        responses: [new OA\Response(response: 200, description: 'Order cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function cancel(ReasonRequest $request, string $id): MfgProductionOrderResource
    {
        return new MfgProductionOrderResource($this->execution->cancelOrder($this->executable($id), (string) $request->validated('reason'), $this->actor($request)));
    }

    /**
     * The order of the company the user may execute.
     */
    private function executable(string $id): MfgProductionOrder
    {
        $order = $this->owned(MfgProductionOrder::class, $id);
        $this->authorize('execute', $order);

        return $order;
    }
}
