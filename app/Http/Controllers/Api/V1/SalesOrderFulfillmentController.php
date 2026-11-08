<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BackorderIndexRequest;
use App\Http\Requests\Sales\CancelSalesOrderRequest;
use App\Http\Requests\Sales\SalesOrderWarehouseRequest;
use App\Http\Resources\SalesOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesOrder as ModuleSalesOrder;
use Modules\Invoicing\Services\SalesOrderStockReservations;
use OpenApi\Attributes as OA;

/**
 * Order fulfilment of the sales API: confirmation behind the credit check, cancellation, availability to promise and
 * stock reservation through the inventory module, and the backorder of the open order lines.
 */
class SalesOrderFulfillmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SalesOrderServiceInterface $orders) {}

    /**
     * Confirms a draft order behind the credit check of the company.
     */
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/confirm',
        operationId: 'confirmSalesOrder',
        description: 'Confirms an order. The credit check of the company runs first (sales preferences `credit_check_mode`: block, warn or off): an order that would take the customer over its credit limit, or a customer blocked for overdue invoices, puts the order on credit hold (status on_hold, credit_status on_hold, with the reason) instead; a credit manager releases it from the credit endpoints. In warn mode the order is confirmed with credit_status warning.',
        summary: 'Confirm sales order (credit check)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleOrderId')],
        responses: [
            new OA\Response(response: 200, description: 'Order confirmed, or put on credit hold', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'object', description: 'The order with its lines'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function confirm(string $id): JsonResponse
    {
        $order = SalesOrder::query()->findOrFail($id);
        $this->orders->submitOrder(ModuleSalesOrder::query()->findOrFail($order->id), (string) (auth()->id() ?? 'system'));
        $order = SalesOrder::query()->with(['items', 'contact'])->findOrFail($id);

        return $this->successResponse(new SalesOrderResource($order), $order->status === 'on_hold' ? __('sales.messages.order_on_credit_hold') : __('sales.messages.order_confirmed'));
    }

    /**
     * Cancels an order and releases its reserved stock.
     */
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/cancel',
        operationId: 'cancelSalesOrder',
        description: 'Cancels an open order (not yet shipped): the stock reserved for it in the inventory module is released and the reason is kept in its notes.',
        summary: 'Cancel sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleCancelOrderRequest')),
        tags: ['Sales Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleOrderId')],
        responses: [
            new OA\Response(response: 200, description: 'Order cancelled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function cancel(CancelSalesOrderRequest $request, string $id): JsonResponse
    {
        SalesOrder::query()->findOrFail($id);
        $this->orders->cancelOrder(ModuleSalesOrder::query()->findOrFail($id), (string) $request->validated('reason'), (string) (auth()->id() ?? 'system'));

        return $this->successResponse(new SalesOrderResource(SalesOrder::query()->with(['items', 'contact'])->findOrFail($id)), __('sales.messages.order_cancelled'));
    }

    /**
     * Availability to promise of the open lines of an order.
     */
    #[OA\Get(
        path: '/api/v1/sales/orders/{id}/availability',
        operationId: 'salesOrderAvailability',
        description: 'Availability to promise of the open lines of the order in a warehouse: for each line the open quantity (backorder), what is reserved for it, what the warehouse can still give (on hand less every reservation, only from the areas that serve orders) and the shortage. Lines of products without inventory tracking are always available.',
        summary: 'Order availability to promise',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleOrderId'), new OA\Parameter(ref: '#/components/parameters/SalesCycleWarehouseId')],
        responses: [
            new OA\Response(response: 200, description: 'Availability per line', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/SalesCycleAvailability'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function availability(SalesOrderWarehouseRequest $request, SalesOrderStockReservations $reservations, string $id): JsonResponse
    {
        SalesOrder::query()->findOrFail($id);
        $order = ModuleSalesOrder::query()->with('items')->findOrFail($id);
        $warehouse = $reservations->warehouseFor($request->validated('warehouse_id'));
        $lines = $reservations->availability($order, $warehouse);

        return $this->successResponse([
            'sales_order_id' => (string) $order->id,
            'warehouse_id' => $warehouse?->id,
            'fully_available' => collect($lines)->every(static fn (array $line): bool => $line['fully_available']),
            'lines' => $lines,
        ], __('sales.messages.order_availability'));
    }

    /**
     * Reserves the stock of the open lines of an order.
     */
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/reserve',
        operationId: 'reserveSalesOrderStock',
        description: 'Reserves in the inventory module the open quantity of every line of a product with inventory tracking, as far as the warehouse can give it (lines reserved before are reserved again from scratch). inventory_status of the order becomes reserved, partially_reserved, not_reserved or not_required; quantity_reserved of each line shows what was reserved. Delivery notes release the reservation of what they ship.',
        summary: 'Reserve sales order stock',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SalesCycleWarehouseRequest')),
        tags: ['Sales Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleOrderId')],
        responses: [
            new OA\Response(response: 200, description: 'Stock reserved', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'object'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function reserve(SalesOrderWarehouseRequest $request, string $id): JsonResponse
    {
        SalesOrder::query()->findOrFail($id);
        $this->orders->reserveInventoryIn(ModuleSalesOrder::query()->findOrFail($id), $request->validated('warehouse_id'), auth()->id() !== null ? (string) auth()->id() : null);

        return $this->successResponse(new SalesOrderResource(SalesOrder::query()->with(['items', 'contact'])->findOrFail($id)), __('sales.messages.order_reserved'));
    }

    /**
     * Backorder: the open order lines still to be delivered.
     */
    #[OA\Get(
        path: '/api/v1/sales/backorders',
        operationId: 'listSalesBackorders',
        description: 'Lists the order lines still to be delivered (ordered less delivered) of the open orders (confirmed up to in fulfilment, on hold included), oldest order first, with the reserved quantity and the open net value. Filter by customer or product.',
        summary: 'List backorders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Orders'],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Customer', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, description: 'Product', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/SalesCyclePage'),
            new OA\Parameter(ref: '#/components/parameters/SalesCyclePerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Open order lines', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesCycleBackorderLine')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function backorders(BackorderIndexRequest $request): JsonResponse
    {
        $orderIds = SalesOrder::query()
            ->whereIn('status', ['confirmed', 'approved', 'on_hold', 'in_production', 'in_fulfillment'])
            ->when($request->validated('contact_id'), static fn ($query, $contactId) => $query->where('contact_id', $contactId))
            ->pluck('id');

        $page = SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->whereIn('sales_order_items.sales_order_id', $orderIds)
            ->whereNull('sales_order_items.deleted_at')
            ->whereColumn('sales_order_items.quantity_delivered', '<', 'sales_order_items.quantity')
            ->when($request->validated('product_id'), static fn ($query, $productId) => $query->where('sales_order_items.product_id', $productId))
            ->orderBy('sales_orders.date')
            ->orderBy('sales_orders.order_number')
            ->orderBy('sales_order_items.id')
            ->select(['sales_order_items.*', 'sales_orders.order_number', 'sales_orders.date as order_date', 'sales_orders.status as order_status', 'sales_orders.contact_id'])
            ->paginate((int) ($request->validated('per_page') ?? 25));

        $lines = collect($page->items())->map(static function (SalesOrderItem $item): array {
            $quantity = DocumentTotalsCalculator::decimal($item->quantity);
            $open = Decimal::sub($quantity, DocumentTotalsCalculator::decimal($item->quantity_delivered));

            return [
                'sales_order_item_id' => (string) $item->id,
                'sales_order_id' => (string) $item->sales_order_id,
                'order_number' => (string) $item->getAttribute('order_number'),
                'order_date' => substr((string) $item->getAttribute('order_date'), 0, 10),
                'order_status' => (string) $item->getAttribute('order_status'),
                'contact_id' => (string) $item->getAttribute('contact_id'),
                'product_id' => $item->product_id,
                'description' => (string) $item->description,
                'quantity_ordered' => bcadd($quantity, '0', 4),
                'quantity_delivered' => bcadd(DocumentTotalsCalculator::decimal($item->quantity_delivered), '0', 4),
                'quantity_open' => bcadd($open, '0', 4),
                'quantity_reserved' => bcadd(DocumentTotalsCalculator::decimal($item->getAttribute('quantity_reserved')), '0', 4),
                'open_net_value' => Decimal::isPositive($quantity) ? Decimal::round2(Decimal::div(Decimal::mul(DocumentTotalsCalculator::decimal($item->line_total), $open), $quantity)) : '0.00',
            ];
        })->all();

        return $this->successResponse($lines, __('sales.messages.backorders_list'), meta: [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }
}
