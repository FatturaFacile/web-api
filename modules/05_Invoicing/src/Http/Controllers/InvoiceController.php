<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\CreateInvoiceFromOrderAction;
use Modules\Invoicing\Actions\PostInvoiceAction;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Http\Requests\CreateInvoiceFromSourceRequest;
use Modules\Invoicing\Http\Requests\CreateInvoiceRequest;
use Modules\Invoicing\Http\Resources\SalesInvoiceResource;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;
use OpenApi\Attributes as OA;

final class InvoiceController extends Controller
{
    /**
     * Returns the paginated list of invoices of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/invoices',
        operationId: 'listInvoicingInvoices',
        description: 'Returns the paginated list of invoices of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List invoices',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Invoices'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'payment_status', in: 'query', required: false, description: 'Filter by payment status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Filter by contact', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'invoice_type', in: 'query', required: false, description: 'Filter by invoice type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of invoices', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingSalesInvoiceResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesInvoice::with('items')->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->has('payment_status')) {
            $query->where('payment_status', (string) $request->query('payment_status'));
        }
        if ($request->has('contact_id')) {
            $query->where('contact_id', (string) $request->query('contact_id'));
        }
        if ($request->has('invoice_type')) {
            $query->where('invoice_type', (string) $request->query('invoice_type'));
        }

        $invoices = $query->orderBy('created_at', 'desc')->gridPaginate((int) $request->query('per_page', 25));

        return response()->json(SalesInvoiceResource::collection($invoices)->response()->getData(true));
    }

    /**
     * Creates a new invoice in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/invoices',
        operationId: 'createInvoicingInvoice',
        description: 'Creates a new invoice in the active tenant.',
        summary: 'Create invoice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Invoices'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCreateInvoiceRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Invoice created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(
        CreateInvoiceRequest $request,
        InvoicingServiceInterface $service,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);

        $data = array_merge($validated, [
            'tenant_id' => $tenantContext->getTenantId(),
            'created_by' => $request->user()?->id,
        ]);

        $invoice = $service->createInvoice($data, $items);

        return response()->json(new SalesInvoiceResource($invoice), 201);
    }

    /**
     * Creates a sales invoice from a sales order.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/invoices/from-order/{orderId}',
        operationId: 'fromOrderInvoicingInvoice',
        description: 'Creates a sales invoice from a sales order.',
        summary: 'Create invoice from sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Invoices'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Identifier of the order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCreateInvoiceFromSourceRequest')),
        responses: [
            new OA\Response(response: 201, description: 'From order created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Order cancelled, already invoiced or with nothing left to invoice', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function fromOrder(
        string $orderId,
        CreateInvoiceFromOrderAction $action,
        CreateInvoiceFromSourceRequest $request
    ): JsonResponse {
        $order = SalesOrder::findOrFail($orderId);
        $invoice = $action->execute($order, [...$request->validated(), 'created_by' => $request->user()?->id]);

        return response()->json(new SalesInvoiceResource($invoice), 201);
    }

    /**
     * Creates a sales invoice from a delivery note.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/invoices/from-delivery/{deliveryNoteId}',
        operationId: 'fromDeliveryNoteInvoicingInvoicesFromDelivery',
        description: 'Creates a sales invoice from a delivery note.',
        summary: 'Create invoice from delivery note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Invoices'],
        parameters: [
            new OA\Parameter(name: 'deliveryNoteId', in: 'path', required: true, description: 'Identifier of the delivery note', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCreateInvoiceFromSourceRequest')),
        responses: [
            new OA\Response(response: 201, description: 'From delivery created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Delivery note already invoiced or without lines', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function fromDeliveryNote(
        string $deliveryNoteId,
        InvoicingServiceInterface $service,
        CreateInvoiceFromSourceRequest $request
    ): JsonResponse {
        $deliveryNote = SalesDeliveryNote::findOrFail($deliveryNoteId);
        $invoice = $service->createInvoiceFromDeliveryNote($deliveryNote, [...$request->validated(), 'created_by' => $request->user()?->id]);

        return response()->json(new SalesInvoiceResource($invoice), 201);
    }

    /**
     * Returns a single invoice of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/invoices/{id}',
        operationId: 'getInvoicingInvoice',
        description: 'Returns a single invoice of the active tenant.',
        summary: 'Get invoice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Invoices'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the invoice', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $invoice = SalesInvoice::with('items')->findOrFail($id);

        return response()->json(new SalesInvoiceResource($invoice));
    }

    /**
     * Approves a sales invoice on behalf of the authenticated user.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/invoices/{id}/approve',
        operationId: 'approveInvoicingInvoice',
        description: 'Approves a sales invoice on behalf of the authenticated user.',
        summary: 'Approve sales invoice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Invoices'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the invoice', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(string $id, InvoicingServiceInterface $service, Request $request): JsonResponse
    {
        $invoice = SalesInvoice::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $approved = $service->approveInvoice($invoice, $userId);

        return response()->json(new SalesInvoiceResource($approved));
    }

    /**
     * Posts a sales invoice.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/invoices/{id}/post',
        operationId: 'postInvoicingInvoice',
        description: 'Posts a sales invoice.',
        summary: 'Post sales invoice',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Invoices'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the invoice', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function post(string $id, PostInvoiceAction $action, Request $request): JsonResponse
    {
        $invoice = SalesInvoice::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $posted = $action->execute($invoice, $userId);

        return response()->json(new SalesInvoiceResource($posted));
    }
}
