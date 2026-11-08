<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\CreateSalesOrderAction;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Http\Requests\CreateSalesOrderRequest;
use Modules\Invoicing\Http\Resources\SalesOrderResource;
use Modules\Invoicing\Models\SalesOrder;
use OpenApi\Attributes as OA;

final class SalesOrderController extends Controller
{
    /**
     * Returns the paginated list of orders of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/orders',
        operationId: 'listInvoicingOrders',
        description: 'Returns the paginated list of orders of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Orders'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'credit_status', in: 'query', required: false, description: 'Filter by credit status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Filter by contact', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sales_rep_id', in: 'query', required: false, description: 'Filter by sales agent', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of orders', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingSalesOrderResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesOrder::with(['items', 'holds'])->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->has('credit_status')) {
            $query->where('credit_status', (string) $request->query('credit_status'));
        }
        if ($request->has('contact_id')) {
            $query->where('contact_id', (string) $request->query('contact_id'));
        }
        if ($request->has('sales_rep_id')) {
            $query->where('sales_rep_id', (string) $request->query('sales_rep_id'));
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate((int) $request->query('per_page', 25));

        return response()->json(SalesOrderResource::collection($orders)->response()->getData(true));
    }

    /**
     * Creates a new order in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/orders',
        operationId: 'createInvoicingOrder',
        description: 'Creates a new order in the active tenant.',
        summary: 'Create order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCreateSalesOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Order created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesOrderResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(
        CreateSalesOrderRequest $request,
        CreateSalesOrderAction $action,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        $autoSubmit = (bool) ($validated['auto_submit'] ?? true);
        unset($validated['items'], $validated['auto_submit']);

        $userId = (string) ($request->user()?->id ?? 'system');

        $data = array_merge($validated, [
            'tenant_id' => $tenantContext->getTenantId(),
            'created_by' => $userId,
        ]);

        $order = $action->execute($data, $items, $autoSubmit, $userId);

        return response()->json(new SalesOrderResource($order), 201);
    }

    /**
     * Returns a single order of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/orders/{id}',
        operationId: 'getInvoicingOrder',
        description: 'Returns a single order of the active tenant.',
        summary: 'Get order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesOrderResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $order = SalesOrder::with(['items', 'holds'])->findOrFail($id);

        return response()->json(new SalesOrderResource($order));
    }

    /**
     * Approves a sales order.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/orders/{id}/approve',
        operationId: 'approveInvoicingOrder',
        description: 'Internal approval of a sales order (sales managers only), through the order state machine.',
        summary: 'Approve sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesOrderResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only the sales managers of the company (owner, admin, sales_manager) approve orders', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The order cannot be approved from its status', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function approve(string $id, SalesOrderServiceInterface $orderService, Request $request): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);
        $this->authorize('approve', $order);
        $userId = (string) ($request->user()?->id ?? 'system');

        $approved = $orderService->approveOrder($order, $userId);

        return response()->json(new SalesOrderResource($approved));
    }

    /**
     * Reserves the stock needed by a sales order.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/orders/{id}/reserve',
        operationId: 'reserveInvoicingOrder',
        description: 'Reserves in the inventory module the open quantity of every line of a product with inventory tracking, in the primary warehouse, as far as the stock allows; inventory_status becomes reserved, partially_reserved, not_reserved or not_required.',
        summary: 'Reserve sales order stock',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesOrderResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reserve(string $id, SalesOrderServiceInterface $orderService): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);
        $reserved = $orderService->reserveInventory($order);

        return response()->json(new SalesOrderResource($reserved));
    }

    /**
     * Cancels a sales order.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/orders/{id}/cancel',
        operationId: 'cancelInvoicingOrder',
        description: 'Cancels an open sales order (not yet shipped) and releases the stock reserved for it.',
        summary: 'Cancel sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', default: 'Cancelled by user')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesOrderResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cancel(string $id, Request $request, SalesOrderServiceInterface $orderService): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);
        $reason = (string) $request->input('reason', 'Cancelled by user');
        $userId = (string) ($request->user()?->id ?? 'system');

        $cancelled = $orderService->cancelOrder($order, $reason, $userId);

        return response()->json(new SalesOrderResource($cancelled));
    }
}
