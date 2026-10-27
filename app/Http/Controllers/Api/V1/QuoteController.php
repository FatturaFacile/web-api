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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QuoteController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaveSalesDocument $saveDocument,
        private readonly ConvertQuoteToOrder $quoteToOrder,
    ) {}

    /**
     * List paginated quotes with optional status filter.
     */
    public function index(Request $request): JsonResponse
    {
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

    public function store(StoreQuoteRequest $request): JsonResponse
    {
        $quote = $this->saveDocument->create(Quote::class, 'quote', 'quote_number', $request->validated());

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_created'), Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $quote = Quote::with(['contact', 'items'])->findOrFail($id);

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_retrieved'));
    }

    public function update(StoreQuoteRequest $request, string $id): JsonResponse
    {
        $quote = $this->saveDocument->update(Quote::findOrFail($id), $request->validated());

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_updated'));
    }

    public function destroy(string $id): JsonResponse
    {
        Quote::findOrFail($id)->delete();

        return $this->successResponse(null, __('sales.messages.quote_deleted'));
    }

    /**
     * Convert an accepted/open quote into a confirmed sales order.
     */
    public function convertToOrder(string $id): JsonResponse
    {
        $order = $this->quoteToOrder->execute(Quote::with('items')->findOrFail($id));

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.quote_to_order'), Response::HTTP_CREATED);
    }

    /**
     * Invoice a quote directly.
     */
    public function createInvoice(ConvertToInvoice $toInvoice, string $id): JsonResponse
    {
        $invoice = $toInvoice->fromQuote(Quote::with('items')->findOrFail($id));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.quote_invoiced'), Response::HTTP_CREATED);
    }

    /**
     * Create a delivery note directly from a quote.
     */
    public function createDeliveryNote(ConvertQuoteToDeliveryNote $toDeliveryNote, string $id): JsonResponse
    {
        $note = $toDeliveryNote->execute(Quote::with('items')->findOrFail($id));

        return $this->successResponse(new DeliveryNoteResource($note), __('sales.messages.quote_delivery_note'), Response::HTTP_CREATED);
    }

    /**
     * Store the customer's signature and accept the quote.
     */
    public function sign(SignQuoteRequest $request, SignQuote $signQuote, string $id): JsonResponse
    {
        $quote = $signQuote->execute(Quote::findOrFail($id), $request->validated('signed_by'), $request->validated('signature_base64'));

        return $this->successResponse(new QuoteResource($quote), __('sales.messages.quote_signed'));
    }
}
