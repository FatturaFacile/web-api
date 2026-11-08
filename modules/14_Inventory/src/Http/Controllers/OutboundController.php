<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Http\Requests\ReleasePickWaveRequest;
use Modules\Inventory\Http\Requests\ReserveSalesOrderRequest;
use Modules\Inventory\Http\Resources\StockPickListResource;
use Modules\Inventory\Models\PickWave;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\PickWaveService;
use Modules\Inventory\Services\SalesOrderReservationService;
use Modules\Inventory\Services\ShipmentConfirmationService;
use OpenApi\Attributes as OA;

/**
 * Outbound flow of sales orders: stock reservation per order, picking waves, and the shipment confirmation that
 * issues the delivery note (DDT) through the sales flow.
 */
#[OA\Tag(name: 'Inventory Outbound', description: 'Sales order reservations (FIFO/FEFO/LIFO), picking waves and shipment confirmation feeding the delivery note')]
final class OutboundController extends Controller
{
    #[OA\Post(
        path: '/api/v1/inventory/sales-orders/{id}/reserve',
        operationId: 'wmsReserveSalesOrder',
        summary: 'Reserve stock for a sales order',
        description: 'Reserves, line by line, what the order still has open (ordered - delivered - already reserved or picked) and the warehouse can give, following each item\'s picking strategy (FIFO, FEFO, LIFO) and skipping quarantined, expired, locked or in-transit stock. What cannot be covered is returned as short, not reserved. Only orders in status confirmed, approved, in_production or in_fulfillment can reserve. Reservations lapse at expires_at, else at the end of the order due date, else after the reservation default of the module (7 days).',
        tags: ['Inventory Outbound'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['warehouse_id'], properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Reservation result per order line', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsSalesReservationLine'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function reserve(string $id, ReserveSalesOrderRequest $request, SalesOrderReservationService $reservations): JsonResponse
    {
        $order = SalesOrder::query()->findOrFail($id);
        $warehouse = Warehouse::query()->findOrFail($request->string('warehouse_id')->value());

        return response()->json(['data' => $reservations->reserve($order, $warehouse, (string) auth()->id(), $request->input('expires_at'))]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/sales-orders/{id}/release-reservations',
        operationId: 'wmsReleaseSalesOrderReservations',
        summary: 'Release the stock reserved for a sales order',
        description: 'Releases every active reservation of the order lines (e.g. the order was put on hold or cancelled); the stock becomes available again. Picked reservations are not touched.',
        tags: ['Inventory Outbound'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Number of released reservations', content: new OA\JsonContent(properties: [new OA\Property(property: 'released', type: 'integer', example: 2)])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function releaseReservations(string $id, SalesOrderReservationService $reservations): JsonResponse
    {
        return response()->json(['released' => $reservations->release(SalesOrder::query()->findOrFail($id))]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/waves',
        operationId: 'wmsListPickWaves',
        summary: 'List picking waves',
        description: 'Picking waves, newest first, optionally of one warehouse or status.',
        tags: ['Inventory Outbound'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: PickWave::STATUSES)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Waves', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsPickWave'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        ]
    )]
    public function waves(Request $request): JsonResponse
    {
        $waves = PickWave::query()
            ->with('pickLists')
            ->when($request->filled('warehouse_id'), static fn ($query) => $query->where('warehouse_id', (string) $request->query('warehouse_id')))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $waves->map(static fn (PickWave $wave): array => self::wave($wave, false))->all()]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/waves',
        operationId: 'wmsReleasePickWave',
        summary: 'Release a picking wave',
        description: 'Reserves the open lines of each sales order and builds one pick list per order from its reservations (routed through the warehouse), all staged in the given staging/shipping bin: confirming a pick of the wave moves the goods there. Orders that cannot reserve anything get no list and appear in shortages. 422 when nothing at all can be picked or the staging bin is not a staging/shipping bin of the warehouse.',
        tags: ['Inventory Outbound'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['warehouse_id', 'staging_bin_id', 'sales_order_ids'], properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'staging_bin_id', type: 'string', format: 'uuid', description: 'Bin of type staging or shipping where picks are staged'),
            new OA\Property(property: 'sales_order_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(property: 'assigned_picker_user_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Wave released', content: new OA\JsonContent(ref: '#/components/schemas/WmsPickWave')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function releaseWave(ReleasePickWaveRequest $request, PickWaveService $waves): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($request->string('warehouse_id')->value());
        $staging = WarehouseBin::query()->with('rack.aisle.zone')->findOrFail($request->string('staging_bin_id')->value());

        $wave = $waves->release($warehouse, array_values((array) $request->input('sales_order_ids')), $staging, $request->input('assigned_picker_user_id'), (string) auth()->id(), $request->input('notes'));

        return response()->json(self::wave($wave, true), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/inventory/waves/{id}',
        operationId: 'wmsShowPickWave',
        summary: 'Get a picking wave',
        description: 'The wave with its pick lists and their lines.',
        tags: ['Inventory Outbound'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Wave', content: new OA\JsonContent(ref: '#/components/schemas/WmsPickWave')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function showWave(string $id): JsonResponse
    {
        return response()->json(self::wave(PickWave::query()->with('pickLists.lines')->findOrFail($id), true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/waves/{id}/cancel',
        operationId: 'wmsCancelPickWave',
        summary: 'Cancel a picking wave',
        description: 'Cancels the pick lists of the wave that are not done (their holds are freed); the sales orders keep their reservations. Only a released wave can be cancelled.',
        tags: ['Inventory Outbound'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Wave cancelled', content: new OA\JsonContent(ref: '#/components/schemas/WmsPickWave')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function cancelWave(string $id, PickWaveService $waves): JsonResponse
    {
        return response()->json(self::wave($waves->cancel(PickWave::query()->findOrFail($id)), true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/pick-lists/{id}/ship',
        operationId: 'wmsConfirmPickListShipment',
        summary: 'Confirm the shipment of a pick list (creates the delivery note)',
        description: 'For a completed pick list of a sales order whose picks were staged in a bin: creates the delivery note (DDT) through the sales flow for exactly the picked quantities (gapless DDT number, delivered quantities and order status updated by sales), issuing the stock from the pick list warehouse and first from the staging bins. Links the packing lists to the DDT, marks the reservations fulfilled and closes the wave when all its lists are done. 422 when the list is not completed, already shipped, not of a sales order, has picks confirmed without staging bin (stock already issued) or picked more than the order has open.',
        tags: ['Inventory Outbound'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Delivery note created', content: new OA\JsonContent(ref: '#/components/schemas/WmsShipmentConfirmation')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function ship(string $id, ShipmentConfirmationService $shipments): JsonResponse
    {
        $list = StockPickList::query()->findOrFail($id);
        $note = $shipments->confirm($list);

        return response()->json([
            'pick_list_id' => (string) $list->id,
            'status' => 'shipped',
            'delivery_note_id' => (string) $note->id,
            'ddt_number' => (string) $note->ddt_number,
            'sales_order_id' => (string) $note->sales_order_id,
            'lines' => $note->items->map(static fn ($line): array => ['sales_order_item_id' => $line->sales_order_item_id, 'product_id' => $line->product_id, 'quantity' => (string) $line->quantity])->values()->all(),
        ], Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private static function wave(PickWave $wave, bool $withLists): array
    {
        return [
            'id' => (string) $wave->id,
            'wave_number' => (string) $wave->wave_number,
            'warehouse_id' => (string) $wave->warehouse_id,
            'status' => (string) $wave->status,
            'staging_bin_id' => $wave->staging_bin_id,
            'assigned_picker_user_id' => $wave->assigned_picker_user_id,
            'shortages' => $wave->shortages ?? [],
            'released_at' => $wave->released_at?->toIso8601String(),
            'completed_at' => $wave->completed_at?->toIso8601String(),
            'pick_lists' => $withLists
                ? StockPickListResource::collection($wave->pickLists()->with('lines')->orderBy('pick_list_number')->get())->resolve()
                : $wave->pickLists->map(static fn (StockPickList $list): array => ['id' => (string) $list->id, 'pick_list_number' => $list->pick_list_number, 'status' => $list->status])->values()->all(),
        ];
    }
}
