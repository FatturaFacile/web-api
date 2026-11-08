<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Http\Requests\TransferOrderRequest;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\TransferOrderLine;
use Modules\Inventory\Models\TransferOrderLineLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\TransferOrderService;
use Modules\Inventory\Support\Quantity;
use OpenApi\Attributes as OA;

/**
 * Transfer orders between warehouses: draft, ship (stock goes in transit), receive (in one or more receipts), cancel.
 */
#[OA\Tag(name: 'Inventory Transfer Orders', description: 'Stock transfers between warehouses with stock in transit')]
final class TransferOrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/transfer-orders',
        operationId: 'wmsListTransferOrders',
        summary: 'List transfer orders',
        description: 'Transfer orders, newest first, optionally filtered by status or by a warehouse they leave from or go to.',
        tags: ['Inventory Transfer Orders'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: TransferOrder::STATUSES)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transfer orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsTransferOrder'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->filled('warehouse_id') ? (string) $request->query('warehouse_id') : null;

        $orders = TransferOrder::query()
            ->with('lines.shippedLots')
            ->when($warehouseId !== null, static fn ($query) => $query->where(static fn ($either) => $either->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId)))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $orders->map(static fn (TransferOrder $order): array => self::order($order))->all()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfer-orders',
        operationId: 'wmsStoreTransferOrder',
        summary: 'Create a transfer order',
        description: 'A draft transfer of items (optionally of a given lot) from one warehouse to another. The number comes from the gapless document numbering (TO-). Nothing moves until it is shipped.',
        tags: ['Inventory Transfer Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['source_warehouse_id', 'destination_warehouse_id', 'lines'], properties: [
            new OA\Property(property: 'source_warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'destination_warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'requested_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(required: ['stock_item_id', 'quantity'], properties: [
                new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'quantity', type: 'number', example: 12),
            ])),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Transfer order created', content: new OA\JsonContent(ref: '#/components/schemas/WmsTransferOrder')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function store(TransferOrderRequest $request, TransferOrderService $transfers): JsonResponse
    {
        $order = $transfers->create(
            Warehouse::query()->findOrFail($request->string('source_warehouse_id')->value()),
            Warehouse::query()->findOrFail($request->string('destination_warehouse_id')->value()),
            array_values((array) $request->input('lines')),
            (string) auth()->id(),
            $request->input('requested_date'),
            $request->input('notes'),
        );

        return response()->json(self::order($order), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/inventory/transfer-orders/{id}',
        operationId: 'wmsShowTransferOrder',
        summary: 'Get a transfer order',
        description: 'The transfer order with its lines, shipped lots and received quantities.',
        tags: ['Inventory Transfer Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Transfer order', content: new OA\JsonContent(ref: '#/components/schemas/WmsTransferOrder')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return response()->json(self::order(TransferOrder::query()->with('lines.shippedLots')->findOrFail($id)));
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfer-orders/{id}/ship',
        operationId: 'wmsShipTransferOrder',
        summary: 'Ship a transfer order',
        description: 'Takes every line from the source warehouse with the item picking strategy (only stock available to orders) and moves it into the transit bin of the destination warehouse, keeping lots. All lines ship or none: 422 when the source cannot cover a line, a bin is frozen by a physical count or the order is not a draft.',
        tags: ['Inventory Transfer Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Shipped: stock in transit', content: new OA\JsonContent(ref: '#/components/schemas/WmsTransferOrder')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function ship(string $id, TransferOrderService $transfers): JsonResponse
    {
        return response()->json(self::order($transfers->ship(TransferOrder::query()->findOrFail($id), (string) auth()->id())));
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfer-orders/{id}/receive',
        operationId: 'wmsReceiveTransferOrder',
        summary: 'Receive a transfer order',
        description: 'Moves stock from transit into the destination warehouse: the given quantities per line (into the given bin, else the default bin), or everything still in transit when no lines are sent. Lots are received in the order they were shipped. Partial receipts leave the order partially_received.',
        tags: ['Inventory Transfer Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(required: ['line_id', 'quantity'], properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'number', example: 5),
                new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid', nullable: true),
            ])),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Received', content: new OA\JsonContent(ref: '#/components/schemas/WmsTransferOrder')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function receive(string $id, TransferOrderRequest $request, TransferOrderService $transfers): JsonResponse
    {
        $order = TransferOrder::query()->findOrFail($id);

        return response()->json(self::order($transfers->receive($order, array_values((array) $request->input('lines', [])), (string) auth()->id())));
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfer-orders/{id}/cancel',
        operationId: 'wmsCancelTransferOrder',
        summary: 'Cancel a transfer order',
        description: 'Cancels a draft transfer order. A shipped order cannot be cancelled: its stock is in transit and must be received.',
        tags: ['Inventory Transfer Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(ref: '#/components/schemas/WmsTransferOrder')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function cancel(string $id, TransferOrderService $transfers): JsonResponse
    {
        return response()->json(self::order($transfers->cancel(TransferOrder::query()->findOrFail($id))));
    }

    /**
     * @return array<string, mixed>
     */
    private static function order(TransferOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'transfer_number' => (string) $order->transfer_number,
            'source_warehouse_id' => (string) $order->source_warehouse_id,
            'destination_warehouse_id' => (string) $order->destination_warehouse_id,
            'transit_bin_id' => $order->transit_bin_id,
            'status' => (string) $order->status,
            'requested_date' => $order->requested_date?->toDateString(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'received_at' => $order->received_at?->toIso8601String(),
            'notes' => $order->notes,
            'lines' => $order->lines->map(static fn (TransferOrderLine $line): array => [
                'id' => (string) $line->id,
                'stock_item_id' => (string) $line->stock_item_id,
                'lot_id' => $line->lot_id,
                'quantity' => Quantity::of((string) $line->quantity),
                'quantity_shipped' => Quantity::of((string) $line->quantity_shipped),
                'quantity_received' => Quantity::of((string) $line->quantity_received),
                'quantity_in_transit' => Quantity::sub((string) $line->quantity_shipped, (string) $line->quantity_received),
                'shipped_lots' => $line->shippedLots->map(static fn (TransferOrderLineLot $lot): array => [
                    'lot_id' => $lot->lot_id,
                    'quantity_shipped' => Quantity::of((string) $lot->quantity_shipped),
                    'quantity_received' => Quantity::of((string) $lot->quantity_received),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
