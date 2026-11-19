<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\ApproveQuoteAction;
use App\Actions\Portal\RejectQuoteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ApprovePortalQuoteRequest;
use App\Http\Requests\Portal\RejectPortalQuoteRequest;
use App\Http\Resources\Portal\PortalDeliveryNoteResource;
use App\Http\Resources\Portal\PortalInvoiceResource;
use App\Http\Resources\Portal\PortalOrderResource;
use App\Http\Resources\Portal\PortalQuoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PortalAccessToken;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Portal\PortalTokenService;
use App\Support\Http\RequestedPerPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Document hub of the portal session. Every query starts from the contact of the session token, and the tenant scope is
 * bound by the session middleware, so a customer only ever reaches their own documents: someone else's id is a 404.
 */
class PortalDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PortalTokenService $tokens,
        private readonly ApproveQuoteAction $approveQuote,
        private readonly RejectQuoteAction $rejectQuote,
        private readonly DocumentPdfService $pdf,
    ) {}

    #[OA\Get(path: '/api/v1/portal/quotes', operationId: 'listPortalSessionQuotes', summary: 'List my quotes', description: 'Quotes of the signed-in customer, drafts excluded.', security: [['portalBearer' => []]], tags: ['Customer Portal'], responses: [new OA\Response(response: 200, description: 'Quotes', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function quotes(Request $request): JsonResponse
    {
        $quotes = Quote::with('items')
            ->where('contact_id', $this->session($request)->contact_id)
            ->where('status', '!=', 'draft')
            ->latest('date')
            ->gridPaginate(RequestedPerPage::resolve(20));

        return $this->paginated(PortalQuoteResource::collection($quotes), $quotes, 'Quotes retrieved successfully');
    }

    #[OA\Get(path: '/api/v1/portal/quotes/{id}', operationId: 'showPortalSessionQuote', summary: 'Get my quote', description: 'One quote of the signed-in customer.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Quote', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function showQuote(Request $request, string $id): JsonResponse
    {
        $quote = $this->ownQuote($request, $id)->load('items');

        return $this->successResponse(data: new PortalQuoteResource($quote), message: 'Quote retrieved successfully');
    }

    #[OA\Post(path: '/api/v1/portal/quotes/{id}/approve', operationId: 'approvePortalSessionQuote', summary: 'Approve my quote', description: 'Accepts the quote. The audit trail keeps the signer, IP address, user agent and time.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['signer_name'], properties: [new OA\Property(property: 'signer_name', type: 'string'), new OA\Property(property: 'signature_data', type: 'string', nullable: true), new OA\Property(property: 'notes', type: 'string', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Quote accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'The quote cannot be accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function approveQuote(ApprovePortalQuoteRequest $request, string $id): JsonResponse
    {
        $approved = $this->approveQuote->execute(
            quote: $this->ownQuote($request, $id),
            token: $this->session($request),
            signerName: (string) $request->validated('signer_name'),
            signatureData: $request->validated('signature_data'),
            notes: $request->validated('notes'),
        );

        return $this->successResponse(data: new PortalQuoteResource($approved), message: 'Quote accepted successfully');
    }

    #[OA\Post(path: '/api/v1/portal/quotes/{id}/reject', operationId: 'rejectPortalSessionQuote', summary: 'Reject my quote', description: 'Declines the quote with an optional reason.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Quote rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'The quote cannot be rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function rejectQuote(RejectPortalQuoteRequest $request, string $id): JsonResponse
    {
        $rejected = $this->rejectQuote->execute(
            quote: $this->ownQuote($request, $id),
            token: $this->session($request),
            reason: $request->validated('reason'),
        );

        return $this->successResponse(data: new PortalQuoteResource($rejected), message: 'Quote rejected successfully');
    }

    #[OA\Get(path: '/api/v1/portal/quotes/{id}/pdf', operationId: 'downloadPortalSessionQuotePdf', summary: 'Download my quote PDF', description: 'PDF of one quote of the signed-in customer.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function quotePdf(Request $request, string $id): HttpResponse
    {
        return $this->download($request, $this->ownQuote($request, $id), 'quote_pdf_downloaded', 'quote');
    }

    #[OA\Get(path: '/api/v1/portal/orders', operationId: 'listPortalSessionOrders', summary: 'List my sales orders', description: 'Sales orders of the signed-in customer, with their shipments and carrier tracking links.', security: [['portalBearer' => []]], tags: ['Customer Portal'], responses: [new OA\Response(response: 200, description: 'Orders', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function orders(Request $request): JsonResponse
    {
        $orders = SalesOrder::with(['items', 'deliveryNotes' => fn ($query) => $query->whereNull('cancelled_at')])
            ->where('contact_id', $this->session($request)->contact_id)
            ->where('status', '!=', 'draft')
            ->latest('date')
            ->gridPaginate(RequestedPerPage::resolve(20));

        return $this->paginated(PortalOrderResource::collection($orders), $orders, 'Orders retrieved successfully');
    }

    #[OA\Get(path: '/api/v1/portal/orders/{id}', operationId: 'showPortalSessionOrder', summary: 'Get my sales order', description: 'One sales order of the signed-in customer, with shipment tracking.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Order', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function showOrder(Request $request, string $id): JsonResponse
    {
        $order = SalesOrder::with(['items', 'deliveryNotes' => fn ($query) => $query->whereNull('cancelled_at')])
            ->where('contact_id', $this->session($request)->contact_id)
            ->where('status', '!=', 'draft')
            ->findOrFail($id);

        return $this->successResponse(data: new PortalOrderResource($order), message: 'Order retrieved successfully');
    }

    #[OA\Get(path: '/api/v1/portal/delivery-notes', operationId: 'listPortalSessionDeliveryNotes', summary: 'List my delivery notes', description: 'Delivery notes (DDT) of the signed-in customer, with carrier tracking.', security: [['portalBearer' => []]], tags: ['Customer Portal'], responses: [new OA\Response(response: 200, description: 'Delivery notes', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function deliveryNotes(Request $request): JsonResponse
    {
        $notes = DeliveryNote::query()
            ->where('contact_id', $this->session($request)->contact_id)
            ->whereNull('cancelled_at')
            ->latest('date')
            ->gridPaginate(RequestedPerPage::resolve(20));

        return $this->paginated(PortalDeliveryNoteResource::collection($notes), $notes, 'Delivery notes retrieved successfully');
    }

    #[OA\Get(path: '/api/v1/portal/delivery-notes/{id}/pdf', operationId: 'downloadPortalSessionDeliveryNotePdf', summary: 'Download my delivery note PDF', description: 'PDF of one delivery note of the signed-in customer.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function deliveryNotePdf(Request $request, string $id): HttpResponse
    {
        $note = DeliveryNote::query()
            ->where('contact_id', $this->session($request)->contact_id)
            ->whereNull('cancelled_at')
            ->findOrFail($id);

        return $this->download($request, $note, 'delivery_note_downloaded', 'delivery_note');
    }

    #[OA\Get(path: '/api/v1/portal/invoices', operationId: 'listPortalSessionInvoices', summary: 'List my invoices', description: 'Invoices of the signed-in customer with balance, due date and payment status. Filter with `payment_status`.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'payment_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unpaid', 'partially_paid', 'paid']))], responses: [new OA\Response(response: 200, description: 'Invoices', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::with('items')
            ->where('contact_id', $this->session($request)->contact_id)
            ->where('invoice_type', '!=', 'proforma')
            ->when($request->query('payment_status'), fn ($query, $status) => $query->where('payment_status', (string) $status))
            ->latest('date')
            ->gridPaginate(RequestedPerPage::resolve(20));

        return $this->paginated(PortalInvoiceResource::collection($invoices), $invoices, 'Invoices retrieved successfully');
    }

    #[OA\Get(path: '/api/v1/portal/invoices/{id}', operationId: 'showPortalSessionInvoice', summary: 'Get my invoice', description: 'One invoice of the signed-in customer.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Invoice', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function showInvoice(Request $request, string $id): JsonResponse
    {
        $invoice = $this->ownInvoice($request, $id)->load('items');

        return $this->successResponse(data: new PortalInvoiceResource($invoice), message: 'Invoice retrieved successfully');
    }

    #[OA\Get(path: '/api/v1/portal/invoices/{id}/pdf', operationId: 'downloadPortalSessionInvoicePdf', summary: 'Download my invoice PDF', description: 'PDF of one invoice of the signed-in customer, with the Swiss QR-bill slip when it applies.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function invoicePdf(Request $request, string $id): HttpResponse
    {
        return $this->download($request, $this->ownInvoice($request, $id), 'invoice_downloaded', 'invoice');
    }

    #[OA\Get(path: '/api/v1/portal/invoices/{id}/xml', operationId: 'downloadPortalSessionInvoiceXml', summary: 'Download my invoice XML', description: 'The FatturaPA XML sent through SDI, when the invoice has one.', security: [['portalBearer' => []]], tags: ['Customer Portal'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/xml', schema: new OA\Schema(type: 'string'))), new OA\Response(response: 404, description: 'Not found or no XML', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function invoiceXml(Request $request, string $id): HttpResponse
    {
        $invoice = $this->ownInvoice($request, $id);
        $metadata = $invoice->sdiMetadata;

        abort_if($metadata === null || blank($metadata->xml_content), Response::HTTP_NOT_FOUND, __('portal.errors.xml_not_available'));

        $this->tokens->logActivity($this->session($request), 'invoice_xml_downloaded', 'invoice', $invoice->id);

        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($metadata->file_name ?: 'invoice-'.$invoice->invoice_number.'.xml'));

        return response((string) $metadata->xml_content, Response::HTTP_OK, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function session(Request $request): PortalAccessToken
    {
        /** @var PortalAccessToken */
        return $request->attributes->get('portal_token');
    }

    private function ownQuote(Request $request, string $id): Quote
    {
        return Quote::where('contact_id', $this->session($request)->contact_id)->where('status', '!=', 'draft')->findOrFail($id);
    }

    private function ownInvoice(Request $request, string $id): Invoice
    {
        return Invoice::where('contact_id', $this->session($request)->contact_id)->findOrFail($id);
    }

    private function download(Request $request, Model $document, string $action, string $subject): HttpResponse
    {
        $this->tokens->logActivity($this->session($request), $action, $subject, (string) $document->getKey());

        $rendered = $this->pdf->render($document);

        return response($rendered->content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$rendered->filename}\"",
        ]);
    }

    /**
     * @param  AnonymousResourceCollection  $collection
     * @param  LengthAwarePaginator<int, Model>  $page
     */
    private function paginated(mixed $collection, mixed $page, string $message): JsonResponse
    {
        return $this->successResponse(
            data: $collection,
            message: $message,
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }
}
