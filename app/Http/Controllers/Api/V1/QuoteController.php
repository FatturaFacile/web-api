<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConvertQuoteToDeliveryNote;
use App\Actions\Sales\ConvertQuoteToOrder;
use App\Actions\Sales\ConvertToInvoice;
use App\Actions\Sales\SaveSalesDocument;
use App\Actions\Sales\SignQuote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SignQuoteRequest;
use App\Http\Requests\Sales\StoreQuoteRequest;
use App\Http\Resources\DeliveryNoteResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\QuoteResource;
use App\Http\Resources\SalesOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Quote;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Quotes', description: 'Sales quotes, customer signing, conversion to orders, delivery notes, and invoices')]
class QuoteController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(
        private readonly SaveSalesDocument $saveDocument,
        private readonly ConvertQuoteToOrder $quoteToOrder,
    ) {}

    /**
     * List paginated quotes with optional status filter.
     */
    #[OA\Get(
        path: '/api/v1/sales/quotes',
        summary: 'List sales quotes',
        description: 'Get paginated list of sales quotes with optional status filter',
        tags: ['Quotes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Quotes list retrieved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);

        $query = Quote::with('contact')->latest('date');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $quotes = $query->paginate($perPage);

        return $this->successResponse(
            data: QuoteResource::collection($quotes),
            message: __('sales.messages.quotes_list'),
            meta: ['current_page' => $quotes->currentPage(), 'last_page' => $quotes->lastPage(), 'per_page' => $quotes->perPage(), 'total' => $quotes->total()]
        );
    }

    #[OA\Post(
        path: '/api/v1/sales/quotes',
        summary: 'Create quote',
        description: 'Create a new sales quote with line items',
        tags: ['Quotes'],
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
            new OA\Response(response: 201, description: 'Quote created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(StoreQuoteRequest $request): JsonResponse
    {
        $this->authorize('create', Quote::class);

        $quote = $this->saveDocument->create(Quote::class, 'quote', 'quote_number', $request->validated());

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_created'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}',
        summary: 'Get quote',
        description: 'Retrieve details and lines of a quote',
        tags: ['Quotes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Quote details retrieved'),
            new OA\Response(response: 404, description: 'Quote not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $quote = Quote::with(['contact', 'items'])->findOrFail($id);
        $this->authorize('view', $quote);

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/sales/quotes/{id}',
        summary: 'Update quote',
        description: 'Update quote details and lines',
        tags: ['Quotes'],
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
            new OA\Response(response: 200, description: 'Quote updated successfully'),
            new OA\Response(response: 404, description: 'Quote not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function update(StoreQuoteRequest $request, string $id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        $this->authorize('update', $quote);

        $quote = $this->saveDocument->update($quote, $request->validated());

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_updated'));
    }

    #[OA\Delete(
        path: '/api/v1/sales/quotes/{id}',
        summary: 'Delete quote',
        description: 'Delete a sales quote',
        tags: ['Quotes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Quote deleted successfully'),
            new OA\Response(response: 404, description: 'Quote not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        $this->authorize('delete', $quote);

        $quote->delete();

        return $this->successResponse(null, __('sales.messages.quote_deleted'));
    }

    /**
     * Convert an accepted/open quote into a confirmed sales order.
     */
    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/convert-to-order',
        summary: 'Convert quote to order',
        description: 'Convert an accepted quote into a confirmed sales order',
        tags: ['Quotes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Sales order created from quote'),
            new OA\Response(response: 404, description: 'Quote not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function convertToOrder(string $id): JsonResponse
    {
        $quote = Quote::with('items')->findOrFail($id);
        $this->authorize('convert', $quote);

        $order = $this->quoteToOrder->execute($quote);

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.quote_to_order'), Response::HTTP_CREATED);
    }

    /**
     * Invoice a quote directly.
     */
    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/invoice',
        summary: 'Create invoice from quote',
        description: 'Generate an invoice directly from a sales quote',
        tags: ['Quotes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Invoice created from quote'),
            new OA\Response(response: 404, description: 'Quote not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function createInvoice(ConvertToInvoice $toInvoice, string $id): JsonResponse
    {
        $quote = Quote::with('items')->findOrFail($id);
        $this->authorize('convert', $quote);

        $invoice = $toInvoice->fromQuote($quote);

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.quote_invoiced'), Response::HTTP_CREATED);
    }

    /**
     * Create a delivery note directly from a quote.
     */
    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/delivery-note',
        summary: 'Create delivery note from quote',
        description: 'Create a delivery note directly from a sales quote',
        tags: ['Quotes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Delivery note created from quote'),
            new OA\Response(response: 404, description: 'Quote not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function createDeliveryNote(ConvertQuoteToDeliveryNote $toDeliveryNote, string $id): JsonResponse
    {
        $quote = Quote::with('items')->findOrFail($id);
        $this->authorize('convert', $quote);

        $note = $toDeliveryNote->execute($quote);

        return $this->successResponse(new DeliveryNoteResource($note), __('sales.messages.quote_delivery_note'), Response::HTTP_CREATED);
    }

    /**
     * Store the customer's signature and accept the quote.
     */
    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/signature',
        summary: 'Sign quote',
        description: 'Store customer digital signature and accept the quote',
        tags: ['Quotes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['signed_by', 'signature_base64'],
                properties: [
                    new OA\Property(property: 'signed_by', type: 'string'),
                    new OA\Property(property: 'signature_base64', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Quote signed successfully'),
            new OA\Response(response: 404, description: 'Quote not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function sign(SignQuoteRequest $request, SignQuote $signQuote, string $id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        $this->authorize('sign', $quote);

        $quote = $signQuote->execute($quote, $request->validated('signed_by'), $request->validated('signature_base64'));

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_signed'));
    }
}
