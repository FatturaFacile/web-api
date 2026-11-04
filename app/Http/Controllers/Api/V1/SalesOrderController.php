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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        description: 'Get paginated list of sales orders with optional status filter',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales orders list retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = SalesOrder::with('contact')->latest('date');

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
        description: 'Create a new customer sales order with line items',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['contact_id', 'date'],
                properties: [
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Sales order created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $order = $this->saveDocument->create(SalesOrder::class, 'sales_order', 'order_number', $request->validated());

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
            new OA\Response(response: 200, description: 'Sales order retrieved successfully'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $order = SalesOrder::with(['contact', 'items'])->findOrFail($id);

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
                properties: [
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sales order updated successfully'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
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
        description: 'Delete a sales order',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sales order deleted successfully'),
            new OA\Response(response: 404, description: 'Sales order not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        SalesOrder::findOrFail($id)->delete();

        return $this->successResponse(null, __('sales.messages.order_deleted'));
    }

    /**
     * Create a (possibly partial) delivery note from the order.
     */
    #[OA\Post(
        path: '/api/v1/sales/orders/{id}/delivery-notes',
        summary: 'Create delivery note from sales order',
        description: 'Create a delivery note from an order (full or partial line fulfillment)',
        tags: ['Sales Orders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'transport_reason', type: 'string'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ]
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

        $note = $this->toDeliveryNote->execute(SalesOrder::findOrFail($id), $lines, $validated);

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
        $invoice = $this->toInvoice->depositFromOrder(SalesOrder::with('items')->findOrFail($id), (float) $request->validated('percentage'));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.deposit_invoiced'), Response::HTTP_CREATED);
    }
}
