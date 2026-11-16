<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Requests\CrossDockOrderRequest;
use Modules\Wms\Http\Resources\CrossDockOrderResource;
use Modules\Wms\Models\WmsCrossDockOrder;
use Modules\Wms\Services\CrossDockService;
use OpenApi\Attributes as OA;

/**
 * Cross-dock orders: inbound goods matched to an outbound shipment without storage, with receipt, allocation and shipment.
 */
final class CrossDockController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly CrossDockService $orders) {}

    #[OA\Get(path: '/api/v1/wms/cross-dock-orders', operationId: 'listWmsCrossDockOrders', description: 'Cross-dock orders with their lines, latest first. Filters: warehouse_id, status, search (number, references).', summary: 'List cross-dock orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Cross-dock orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsCrossDockOrder'))])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsCrossDockOrder::class);
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(WmsCrossDockOrder::Statuses)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $term = isset($data['search']) ? '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $data['search']).'%' : null;
        $orders = WmsCrossDockOrder::query()
            ->with(['lines.stockItem:id,sku,name'])
            ->where('tenant_id', $this->tenantId())
            ->when(isset($data['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $data['warehouse_id']))
            ->when(isset($data['status']), static fn ($query) => $query->where('status', $data['status']))
            ->when($term !== null, static fn ($query) => $query->where(static fn ($inner) => $inner->where('number', 'like', $term)->orWhere('inbound_reference', 'like', $term)->orWhere('outbound_reference', 'like', $term)))
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        return CrossDockOrderResource::collection($orders);
    }

    #[OA\Post(path: '/api/v1/wms/cross-dock-orders', operationId: 'storeWmsCrossDockOrder', description: 'Create a planned cross-dock order with its lines (one per stock item). The doors must belong to the warehouse and accept the direction; the outbound quantity of a line cannot exceed its inbound quantity.', summary: 'Create a cross-dock order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsCrossDockOrderRequest')), responses: [
        new OA\Response(response: 201, description: 'Cross-dock order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsCrossDockOrder')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function store(CrossDockOrderRequest $request): JsonResponse
    {
        $this->authorize('create', WmsCrossDockOrder::class);

        return (new CrossDockOrderResource($this->orders->create($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/wms/cross-dock-orders/{id}', operationId: 'showWmsCrossDockOrder', description: 'Return a cross-dock order with its lines and the match status of inbound against outbound.', summary: 'Show a cross-dock order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsCrossDockOrder')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
    ])]
    public function show(string $id): CrossDockOrderResource
    {
        $record = WmsCrossDockOrder::query()->with(['lines.stockItem:id,sku,name'])->findOrFail($id);
        $this->authorize('view', $record);

        return new CrossDockOrderResource($record);
    }

    #[OA\Put(path: '/api/v1/wms/cross-dock-orders/{id}', operationId: 'updateWmsCrossDockOrder', description: 'Change a planned cross-dock order; when lines are sent they replace the existing ones.', summary: 'Update a cross-dock order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsCrossDockOrderRequest')), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsCrossDockOrder')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function update(CrossDockOrderRequest $request, string $id): CrossDockOrderResource
    {
        $record = WmsCrossDockOrder::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new CrossDockOrderResource($this->orders->update($record, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/wms/cross-dock-orders/{id}', operationId: 'deleteWmsCrossDockOrder', description: 'Delete a planned or cancelled cross-dock order (soft delete).', summary: 'Delete a cross-dock order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 204, description: 'Cross-dock order deleted'),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = WmsCrossDockOrder::query()->findOrFail($id);
        $this->authorize('delete', $record);

        $this->orders->delete($record);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/wms/cross-dock-orders/{id}/receive', operationId: 'receiveWmsCrossDockOrder', description: 'Records what arrived on the inbound side. Without lines the whole inbound quantity is taken as received; a received quantity cannot exceed the inbound one or fall below what is already matched to the outbound side.', summary: 'Receive the inbound goods', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'line_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity', type: 'string', example: '10')]))])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsCrossDockOrder')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function receive(Request $request, string $id): CrossDockOrderResource
    {
        $record = WmsCrossDockOrder::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        return new CrossDockOrderResource($this->orders->receive($record, $data['lines'] ?? null));
    }

    #[OA\Post(path: '/api/v1/wms/cross-dock-orders/{id}/allocate', operationId: 'allocateWmsCrossDockOrder', description: 'Sets the quantity of each given line that goes to the outbound shipment. It cannot exceed the received quantity (the inbound quantity before receipt).', summary: 'Match inbound to outbound', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['lines'], properties: [new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'line_id', type: 'string', format: 'uuid'), new OA\Property(property: 'outbound_quantity', type: 'string', example: '10')]))])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsCrossDockOrder')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function allocate(Request $request, string $id): CrossDockOrderResource
    {
        $record = WmsCrossDockOrder::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.outbound_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        return new CrossDockOrderResource($this->orders->allocate($record, $data['lines']));
    }

    #[OA\Post(path: '/api/v1/wms/cross-dock-orders/{id}/ship', operationId: 'shipWmsCrossDockOrder', description: 'Ships a received cross-dock order that has at least one line matched to the outbound side.', summary: 'Ship the outbound goods', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsCrossDockOrder')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function ship(string $id): CrossDockOrderResource
    {
        $record = WmsCrossDockOrder::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new CrossDockOrderResource($this->orders->ship($record));
    }

    #[OA\Post(path: '/api/v1/wms/cross-dock-orders/{id}/cancel', operationId: 'cancelWmsCrossDockOrder', description: 'Cancels a planned or received cross-dock order.', summary: 'Cancel a cross-dock order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsCrossDockOrder')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): CrossDockOrderResource
    {
        $record = WmsCrossDockOrder::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new CrossDockOrderResource($this->orders->cancel($record));
    }
}
