<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hospitality\Http\Requests\HospitalityRequest;
use Modules\Hospitality\Http\Resources\HospitalityResource;
use Modules\Hospitality\Models\DiningTable;
use Modules\Hospitality\Models\KitchenTicket;
use Modules\Hospitality\Models\Order;
use Modules\Hospitality\Models\OrderItem;
use Modules\Hospitality\Services\HospitalityService;
use OpenApi\Attributes as OA;

/**
 * Orders by course, kitchen tickets per station, and the bill paid at once or split.
 */
final class OrderController extends Controller
{
    public function __construct(private readonly HospitalityService $service) {}

    #[OA\Post(path: '/api/v1/hospitality/orders', operationId: 'openHospitalityOrder', description: 'Seat guests at a free table and open its order.', summary: 'Open order', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityOrderRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Order opened', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function store(HospitalityRequest $request): JsonResponse
    {
        $this->authorize('create', Order::class);
        $order = $this->service->openOrder(DiningTable::query()->findOrFail($request->validated('table_id')), (int) $request->validated('covers'), $request->user()?->getKey(), $request->validated('notes'));

        return (new HospitalityResource($order))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/hospitality/orders/{id}', operationId: 'showHospitalityOrder', description: 'Return an order with its items and payments.', summary: 'Show order', security: [['bearerAuth' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Order', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
    ])]
    public function show(string $id): HospitalityResource
    {
        return new HospitalityResource($this->order($id, 'view')->load(['items', 'payments']));
    }

    #[OA\Post(path: '/api/v1/hospitality/orders/{id}/items', operationId: 'storeHospitalityOrderItems', description: 'Add dishes and drinks to an order, each with its course and station; prices default to the product.', summary: 'Add order items', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityItemsRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 201, description: 'Items added', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeItems(HospitalityRequest $request, string $id): JsonResponse
    {
        $items = $this->service->addItems($this->order($id, 'update'), $request->validated('items'));

        return HospitalityResource::collection($items)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/hospitality/orders/{id}/fire', operationId: 'fireHospitalityCourse', description: 'Send the pending items of a course to the stations: one kitchen ticket per station.', summary: 'Fire course', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityFireRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 201, description: 'Tickets created', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function fire(HospitalityRequest $request, string $id): JsonResponse
    {
        return HospitalityResource::collection($this->service->fireCourse($this->order($id, 'update'), (int) $request->validated('course')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/hospitality/orders/{id}/move', operationId: 'moveHospitalityOrder', description: 'Move an open order to a free table.', summary: 'Move order', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityMoveRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Order moved', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function move(HospitalityRequest $request, string $id): HospitalityResource
    {
        return new HospitalityResource($this->service->moveOrder($this->order($id, 'update'), DiningTable::query()->findOrFail($request->validated('table_id'))));
    }

    #[OA\Post(path: '/api/v1/hospitality/order-items/{id}/void', operationId: 'voidHospitalityOrderItem', description: 'Void an unpaid item with the reason. Managers only.', summary: 'Void item', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityVoidRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Item voided', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function void(HospitalityRequest $request, string $id): HospitalityResource
    {
        $item = OrderItem::query()->findOrFail($id);
        $this->authorize('manage', $item);

        return new HospitalityResource($this->service->voidItem($item, (string) $request->validated('reason')));
    }

    #[OA\Get(path: '/api/v1/hospitality/orders/{id}/bill', operationId: 'hospitalityOrderBill', description: 'Return what is left to pay of an order, line by line.', summary: 'Bill', security: [['bearerAuth' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Bill', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityDataResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
    ])]
    public function bill(string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->bill($this->order($id, 'view'))]);
    }

    #[OA\Post(path: '/api/v1/hospitality/orders/{id}/payments', operationId: 'payHospitalityOrder', description: 'Pay a part of the bill as a POS sale: chosen items, or an equal share among the guests still to pay. The order closes when nothing is left.', summary: 'Pay bill', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityPaymentRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 201, description: 'Payment registered', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function pay(HospitalityRequest $request, string $id): JsonResponse
    {
        return (new HospitalityResource($this->service->pay($this->order($id, 'update'), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/hospitality/kitchen-tickets', operationId: 'listHospitalityKitchenTickets', description: 'Return the tickets of a station, oldest first, for the kitchen display.', summary: 'Kitchen tickets', security: [['bearerAuth' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'station', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['kitchen', 'bar', 'pizza', 'pastry'])),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'ready', 'served'], default: 'open')),
    ], responses: [
        new OA\Response(response: 200, description: 'Tickets', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function tickets(HospitalityRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', KitchenTicket::class);

        return HospitalityResource::collection(KitchenTicket::query()
            ->when($request->validated('station') !== null, fn ($query) => $query->where('station', $request->validated('station')))
            ->where('status', $request->validated('status') ?? 'open')
            ->orderBy('created_at')->get());
    }

    #[OA\Post(path: '/api/v1/hospitality/kitchen-tickets/{id}/status', operationId: 'advanceHospitalityKitchenTicket', description: 'Mark a kitchen ticket ready or served; its items follow.', summary: 'Advance kitchen ticket', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityTicketStatusRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Ticket advanced', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function advanceTicket(HospitalityRequest $request, string $id): HospitalityResource
    {
        $ticket = KitchenTicket::query()->findOrFail($id);
        $this->authorize('update', $ticket);

        return new HospitalityResource($this->service->advanceTicket($ticket, (string) $request->validated('status')));
    }

    private function order(string $id, string $ability): Order
    {
        $order = Order::query()->findOrFail($id);
        $this->authorize($ability, $order);

        return $order;
    }
}
