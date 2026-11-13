<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\CreateDeliveryNoteAction;
use Modules\Invoicing\Actions\DispatchGoodsAction;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Http\Requests\CreateDeliveryNoteRequest;
use Modules\Invoicing\Http\Resources\SalesDeliveryNoteResource;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;
use OpenApi\Attributes as OA;

final class DeliveryNoteController extends Controller
{
    /**
     * Returns the paginated list of delivery notes of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/delivery-notes',
        operationId: 'listInvoicingDeliveryNotes',
        description: 'Returns the paginated list of delivery notes of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List delivery notes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Delivery Notes'],
        parameters: [
            new OA\Parameter(name: 'shipping_status', in: 'query', required: false, description: 'Filter by shipping status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sales_order_id', in: 'query', required: false, description: 'Filter by sales order', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of delivery notes', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingSalesDeliveryNoteResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesDeliveryNote::with(['items', 'customer'])->where('tenant_id', $tenantId);

        if ($request->has('shipping_status')) {
            $query->where('shipping_status', (string) $request->query('shipping_status'));
        }
        if ($request->has('sales_order_id')) {
            $query->where('sales_order_id', (string) $request->query('sales_order_id'));
        }

        $notes = $query->orderBy('created_at', 'desc')->gridPaginate((int) $request->query('per_page', 25));

        return response()->json(SalesDeliveryNoteResource::collection($notes)->response()->getData(true));
    }

    /**
     * Creates a new delivery note in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/delivery-notes',
        operationId: 'createInvoicingDeliveryNote',
        description: 'Creates a new delivery note in the active tenant.',
        summary: 'Create delivery note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Delivery Notes'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCreateDeliveryNoteRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Delivery note created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesDeliveryNoteResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(
        CreateDeliveryNoteRequest $request,
        CreateDeliveryNoteAction $action
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        $orderId = $validated['sales_order_id'];
        unset($validated['items']);

        $order = SalesOrder::findOrFail($orderId);
        $deliveryNote = $action->execute($order, $validated, $items);

        return response()->json(new SalesDeliveryNoteResource($deliveryNote), 201);
    }

    /**
     * Returns a single delivery note of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/delivery-notes/{id}',
        operationId: 'getInvoicingDeliveryNote',
        description: 'Returns a single delivery note of the active tenant.',
        summary: 'Get delivery note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Delivery Notes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the delivery note', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesDeliveryNoteResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $deliveryNote = SalesDeliveryNote::with('items')->findOrFail($id);

        return response()->json(new SalesDeliveryNoteResource($deliveryNote));
    }

    /**
     * Marks a delivery note as dispatched.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/delivery-notes/{id}/dispatch',
        operationId: 'dispatchInvoicingDeliveryNote',
        description: 'Marks a delivery note as dispatched.',
        summary: 'Dispatch delivery note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Delivery Notes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the delivery note', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesDeliveryNoteResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function dispatch(string $id, DispatchGoodsAction $action, Request $request): JsonResponse
    {
        $deliveryNote = SalesDeliveryNote::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $dispatched = $action->execute($deliveryNote, $userId);

        return response()->json(new SalesDeliveryNoteResource($dispatched));
    }

    /**
     * Confirms a delivery note.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/delivery-notes/{id}/confirm',
        operationId: 'confirmInvoicingDeliveryNote',
        description: 'Confirms a delivery note.',
        summary: 'Confirm delivery note',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Delivery Notes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the delivery note', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesDeliveryNoteResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function confirm(string $id, DeliveryFulfillmentServiceInterface $service, Request $request): JsonResponse
    {
        $deliveryNote = SalesDeliveryNote::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $confirmed = $service->confirmDelivery($deliveryNote, $userId);

        return response()->json(new SalesDeliveryNoteResource($confirmed));
    }
}
