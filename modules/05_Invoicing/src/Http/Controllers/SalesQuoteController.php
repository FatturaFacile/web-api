<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\AcceptSalesQuoteAction;
use Modules\Invoicing\Actions\ConvertQuoteToOrderAction;
use Modules\Invoicing\Actions\CreateSalesQuoteAction;
use Modules\Invoicing\Http\Requests\CreateSalesQuoteRequest;
use Modules\Invoicing\Http\Resources\SalesOrderResource;
use Modules\Invoicing\Http\Resources\SalesQuoteResource;
use Modules\Invoicing\Models\SalesQuote;
use OpenApi\Attributes as OA;

final class SalesQuoteController extends Controller
{
    /**
     * Returns the paginated list of quotes of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/quotes',
        operationId: 'listInvoicingQuotes',
        description: 'Returns the paginated list of quotes of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List quotes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Quotes'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Filter by contact', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of quotes', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingSalesQuoteResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesQuote::with('items')->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->has('contact_id')) {
            $query->where('contact_id', (string) $request->query('contact_id'));
        }

        $quotes = $query->orderBy('created_at', 'desc')->paginate((int) $request->query('per_page', 25));

        return response()->json(SalesQuoteResource::collection($quotes)->response()->getData(true));
    }

    /**
     * Creates a new quote in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/quotes',
        operationId: 'createInvoicingQuote',
        description: 'Creates a new quote in the active tenant.',
        summary: 'Create quote',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Quotes'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCreateSalesQuoteRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Quote created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesQuoteResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(
        CreateSalesQuoteRequest $request,
        CreateSalesQuoteAction $action,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);

        $data = array_merge($validated, [
            'tenant_id' => $tenantContext->getTenantId(),
            'created_by' => $request->user()?->id,
        ]);

        $quote = $action->execute($data, $items);

        return response()->json(new SalesQuoteResource($quote), 201);
    }

    /**
     * Returns a single quote of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/quotes/{id}',
        operationId: 'getInvoicingQuote',
        description: 'Returns a single quote of the active tenant.',
        summary: 'Get quote',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Quotes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the quote', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesQuoteResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $quote = SalesQuote::with('items')->findOrFail($id);

        return response()->json(new SalesQuoteResource($quote));
    }

    /**
     * Marks a sales quote as accepted.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/quotes/{id}/accept',
        operationId: 'acceptInvoicingQuote',
        description: 'Marks a sales quote as accepted.',
        summary: 'Accept sales quote',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Quotes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the quote', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesQuoteResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function accept(string $id, AcceptSalesQuoteAction $action): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);
        $accepted = $action->execute($quote);

        return response()->json(new SalesQuoteResource($accepted));
    }

    /**
     * Creates a sales order from a sales quote.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/quotes/{id}/convert',
        operationId: 'convertInvoicingQuote',
        description: 'Creates a sales order from a sales quote.',
        summary: 'Convert quote to sales order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Quotes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the quote', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Quote created', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingSalesOrderResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function convert(string $id, ConvertQuoteToOrderAction $action, Request $request): JsonResponse
    {
        $quote = SalesQuote::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $order = $action->execute($quote, $userId);

        return response()->json(new SalesOrderResource($order), 201);
    }
}
