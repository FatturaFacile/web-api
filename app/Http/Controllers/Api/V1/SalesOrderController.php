<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConvertOrderToDeliveryNote;
use App\Actions\Sales\ConvertToInvoice;
use App\Actions\Sales\SaveSalesDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ConvertToDeliveryNoteRequest;
use App\Http\Requests\Sales\DepositInvoiceRequest;
use App\Http\Requests\Sales\StoreSalesOrderRequest;
use App\Http\Resources\DeliveryNoteResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SalesOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\SalesOrder;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Sales Orders', description: 'Customer sales orders, fulfillment via delivery notes, and invoicing')]
class SalesOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaveSalesDocument $saveDocument,
        private readonly ConvertOrderToDeliveryNote $toDeliveryNote,
        private readonly ConvertToInvoice $toInvoice,
    ) {}

    /**
     * List paginated sales orders with optional status filter.
     */
    #[OA\Get(
        path: '/api/v1/sales/orders',
        summary: 'List sales orders',
        description: 'Get paginated list of sales orders with optional status filter. An order with status on_hold carries the id of its active credit hold in credit_hold_id (and the holds list): pass it to POST /api/v1/invoicing/customer-credit/holds/{holdId}/release, allowed to the credit managers only.',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales orders list retrieved: every order with its contact summary, credit_hold_id and active holds (no lines)', content: new OA\JsonContent(ref: '#/components/schemas/SalesOrderListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = SalesOrder::with(['contact', 'holds' => static fn ($holds) => $holds->where('status', 'active')])->latest('date');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $orders = $query->paginate($perPage);

        return $this->successResponse(
            data: SalesOrderResource::collection($orders),
            message: __('sales.messages.orders_list'),
            meta: ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total()]
        );
    }

    #[OA\Post(
        path: '/api/v1/sales/orders',
        summary: 'Create sales order',
        description: 'Create a new customer sales order with line items. The number comes from the gapless order series. An order created with status confirmed goes through the credit check of the company first and can come back on credit hold (status on_hold).',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/SalesOrderWriteRequest',
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Sales order created successfully (with lines)', content: new OA\JsonContent(ref: '#/components/schemas/SalesOrderResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $order = $this->saveDocument->createOrder($request->validated());

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.order_created'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales/orders/{id}',
        summary: 'Get sales order',
        description: 'Retrieve details and lines of a sales order',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales order with contact summary, lines, credit_hold_id and active holds', content: new OA\JsonContent(ref: '#/components/schemas/SalesOrderResponse')),
            new OA\Response(response: 404, description: 'Sales order not found (or of another company)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $order = SalesOrder::with(['contact', 'items', 'holds' => static fn ($holds) => $holds->where('status', 'active')])->findOrFail($id);

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.order_retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/sales/orders/{id}',
        summary: 'Update sales order',
        description: 'Update sales order details and lines',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/SalesOrderWriteRequest',
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sales order updated successfully', content: new OA\JsonContent(ref: '#/components/schemas/SalesOrderResponse')),
            new OA\Response(response: 404, description: 'Sales order not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(StoreSalesOrderRequest $request, string $id): JsonResponse
    {
        $order = $this->saveDocument->update(SalesOrder::findOrFail($id), $request->validated());

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.order_updated'));
    }

    #[OA\Delete(
        path: '/api/v1/sales/orders/{id}',
        summary: 'Delete sales order',
        description: 'Delete a sales order (soft delete). An order with delivered or invoiced quantities cannot be deleted: cancel it instead.',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales order deleted successfully', content: new OA\JsonContent(ref: '#/components/schemas/SalesOrderDeleteResponse')),
            new OA\Response(response: 404, description: 'Sales order not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The order has delivered or invoiced quantities', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $order = SalesOrder::with('items')->findOrFail($id);

        if ($order->items->contains(static fn ($item): bool => Decimal::isPositive(DocumentTotalsCalculator::decimal($item->quantity_delivered)) || Decimal::isPositive(DocumentTotalsCalculator::decimal($item->getAttribute('quantity_invoiced'))))) {
            throw ValidationException::withMessages(['order' => __('sales.order_with_movements_not_deletable')]);
        }

        $order->delete();

        return $this->successResponse(null, __('sales.messages.order_deleted'));
    }

    /**
     * Create a (possibly partial) delivery note from the order.
     */
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/delivery-notes',
        summary: 'Create delivery note from sales order',
        description: 'Ships an order: creates and issues a delivery note (DDT) for the lines and quantities given (order line id => quantity) or for every open quantity. Each quantity must stay within the open quantity of its line (ordered less delivered); what stays open is the backorder. The stock of the products with inventory tracking leaves the warehouse (after the reservations of the shipped lines are released) and the order goes in fulfilment, or shipped once every line is delivered. Only confirmed or approved orders (not drafts, not on hold) can be shipped.',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: '#/components/schemas/SalesCycleDeliveryNoteRequest'
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Delivery note created successfully'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function createDeliveryNote(ConvertToDeliveryNoteRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $lines = $validated['lines'] ?? [];
        unset($validated['lines']);

        $note = $this->toDeliveryNote->execute(SalesOrder::findOrFail($id), $lines, $validated, $request->user()?->id !== null ? (string) $request->user()->id : null);

        return $this->successResponse(new DeliveryNoteResource($note), __('sales.messages.order_delivery_note'), Response::HTTP_CREATED);
    }

    /**
     * Invoice the whole order.
     */
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/invoice',
        summary: 'Create invoice from sales order',
        description: 'Generate an invoice directly from a sales order',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Invoice created successfully'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function createInvoice(string $id): JsonResponse
    {
        $invoice = $this->toInvoice->fromOrder(SalesOrder::with('items')->findOrFail($id));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.order_invoiced'), Response::HTTP_CREATED);
    }

    /**
     * Issue a deposit invoice for a percentage of the order.
     */
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/deposit-invoice',
        summary: 'Create deposit invoice from sales order',
        description: 'Issue a deposit/advance invoice for a percentage of the sales order',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['percentage'],
                properties: [
                    new OA\Property(property: 'percentage', type: 'number'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Deposit invoice created successfully'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function createDepositInvoice(DepositInvoiceRequest $request, string $id): JsonResponse
    {
        $invoice = $this->toInvoice->depositFromOrder(SalesOrder::with('items')->findOrFail($id), DocumentTotalsCalculator::decimal($request->validated('percentage')));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.deposit_invoiced'), Response::HTTP_CREATED);
    }
}
