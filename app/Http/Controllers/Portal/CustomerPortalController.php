<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\ApproveQuoteAction;
use App\Actions\Portal\RejectQuoteAction;
use App\Actions\Portal\ReplyToTicketAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ApprovePortalQuoteRequest;
use App\Http\Requests\Portal\RejectPortalQuoteRequest;
use App\Http\Requests\Portal\ReplyPortalTicketRequest;
use App\Http\Resources\Portal\PortalHelpdeskTicketResource;
use App\Http\Resources\Portal\PortalInvoiceResource;
use App\Http\Resources\Portal\PortalQuoteResource;
use App\Http\Resources\Portal\PortalServiceReportResource;
use App\Http\Resources\Portal\PortalTicketResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\ServiceReport;
use App\Models\Ticket;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Portal\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Modules\ServiceManagement\Models\Ticket as HelpdeskTicket;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskThreadService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class CustomerPortalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PortalTokenService $tokenService,
        private readonly ApproveQuoteAction $approveQuoteAction,
        private readonly RejectQuoteAction $rejectQuoteAction,
        private readonly ReplyToTicketAction $replyToTicketAction,
        private readonly DocumentPdfService $pdfService,
        private readonly HelpdeskThreadService $helpdeskThread,
    ) {}

    /**
     * Authenticate magic link token and retrieve customer dashboard summary.
     */
    #[OA\Get(
        path: '/api/v1/portal/access/{token}',
        operationId: 'getAccessPortal',
        description: 'Authenticates the magic link token and returns the customer dashboard summary. Public endpoint: the token is the credential.',
        summary: 'Access customer portal',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'customer', type: 'object', properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'email', type: 'string')]), new OA\Property(property: 'token_type'), new OA\Property(property: 'scope_type'), new OA\Property(property: 'scope_id', type: 'string'), new OA\Property(property: 'expires_at'), new OA\Property(property: 'summary', type: 'object', properties: [new OA\Property(property: 'pending_quotes', type: 'integer'), new OA\Property(property: 'unpaid_invoices', type: 'integer'), new OA\Property(property: 'open_tickets', type: 'integer'), new OA\Property(property: 'upcoming_appointments', type: 'integer')])])])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function access(string $token): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);
        $contact = $accessToken->contact;

        $this->tokenService->logActivity($accessToken, 'portal_login');

        $pendingQuotesCount = Quote::where('contact_id', $contact->id)
            ->whereIn('status', ['draft', 'sent'])
            ->count();

        $unpaidInvoicesCount = Invoice::where('contact_id', $contact->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->count();

        $openTicketsCount = Ticket::where('client_id', $contact->id)
            ->whereIn('status', ['open', 'in_progress', 'pending'])
            ->count() + HelpdeskTicket::query()
            ->where('tenant_id', $contact->tenant_id)
            ->where('customer_id', $contact->id)
            ->whereNotIn('status', ['resolved', 'closed', 'cancelled', 'merged'])
            ->count();

        $upcomingAppointmentsCount = Appointment::where('contact_id', $contact->id)
            ->where('starts_at', '>=', now())
            ->whereNull('cancelled_at')
            ->where('is_portal_visible', true)
            ->count();

        return $this->successResponse(
            data: [
                'customer' => [
                    'id' => $contact->id,
                    'name' => $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')),
                    'email' => $contact->email,
                ],
                'token_type' => $accessToken->token_type,
                'scope_type' => $accessToken->scope_type,
                'scope_id' => $accessToken->scope_id,
                'expires_at' => $accessToken->expires_at->toISOString(),
                'summary' => [
                    'pending_quotes' => $pendingQuotesCount,
                    'unpaid_invoices' => $unpaidInvoicesCount,
                    'open_tickets' => $openTicketsCount,
                    'upcoming_appointments' => $upcomingAppointmentsCount,
                ],
            ],
            message: 'Portal session initialized successfully'
        );
    }

    /**
     * Retrieve quote details for approval/review.
     */
    #[OA\Get(
        path: '/api/v1/portal/quotes/{token}',
        operationId: 'getShowQuotePortalQuotes',
        description: 'Returns the quote details for review and approval. Public endpoint: the token is the credential.',
        summary: 'Get portal quote',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PortalQuoteResource')])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function showQuote(Request $request, string $token): JsonResponse|HttpResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $query = Quote::with(['items', 'contact'])
            ->where('contact_id', $accessToken->contact_id);

        if ($accessToken->scope_type === Quote::class && $accessToken->scope_id) {
            $quote = $query->findOrFail($accessToken->scope_id);
        } else {
            $quote = $query->latest('date')->firstOrFail();
        }

        $this->tokenService->logActivity(
            token: $accessToken,
            action: 'quote_viewed',
            subjectType: 'quote',
            subjectId: $quote->id
        );

        if ($request->wantsJson()) {
            return $this->successResponse(
                data: new PortalQuoteResource($quote),
                message: 'Quote details retrieved'
            );
        }

        return response()->view('portal.quote', [
            'quote' => $quote,
            'token' => $token,
        ]);
    }

    /**
     * Customer approves quote.
     */
    #[OA\Post(
        path: '/api/v1/portal/quotes/{token}/approve',
        operationId: 'approveQuotePortalQuote',
        description: 'Lets the customer approve the quote reached with the token.',
        summary: 'Approve portal quote',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ApprovePortalQuoteRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PortalQuoteResource')])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function approveQuote(ApprovePortalQuoteRequest $request, string $token): JsonResponse|HttpResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $quote = Quote::where('contact_id', $accessToken->contact_id)
            ->when($accessToken->scope_type === Quote::class && $accessToken->scope_id, fn ($q) => $q->where('id', $accessToken->scope_id))
            ->firstOrFail();

        $approved = $this->approveQuoteAction->execute(
            quote: $quote,
            token: $accessToken,
            signerName: $request->validated('signer_name'),
            signatureData: $request->validated('signature_data'),
            notes: $request->validated('notes')
        );

        if ($request->wantsJson()) {
            return $this->successResponse(
                data: new PortalQuoteResource($approved),
                message: 'Quote accepted successfully'
            );
        }

        return response()->view('portal.quote', [
            'quote' => $approved,
            'token' => $token,
            'message' => 'Preventivo accettato con successo!',
        ]);
    }

    /**
     * Customer rejects quote.
     */
    #[OA\Post(
        path: '/api/v1/portal/quotes/{token}/reject',
        operationId: 'rejectQuotePortalQuote',
        description: 'Lets the customer reject the quote reached with the token.',
        summary: 'Reject portal quote',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RejectPortalQuoteRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PortalQuoteResource')])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function rejectQuote(RejectPortalQuoteRequest $request, string $token): JsonResponse|HttpResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $quote = Quote::where('contact_id', $accessToken->contact_id)
            ->when($accessToken->scope_type === Quote::class && $accessToken->scope_id, fn ($q) => $q->where('id', $accessToken->scope_id))
            ->firstOrFail();

        $rejected = $this->rejectQuoteAction->execute(
            quote: $quote,
            token: $accessToken,
            reason: $request->validated('reason')
        );

        if ($request->wantsJson()) {
            return $this->successResponse(
                data: new PortalQuoteResource($rejected),
                message: 'Quote rejected successfully'
            );
        }

        return response()->view('portal.quote', [
            'quote' => $rejected,
            'token' => $token,
            'message' => 'Preventivo rifiutato.',
        ]);
    }

    /**
     * Download Quote PDF via portal link.
     */
    #[OA\Get(
        path: '/api/v1/portal/quotes/{token}/pdf',
        operationId: 'getDownloadQuotePdfPortalQuote',
        description: 'Downloads the PDF of the quote reached with the token.',
        summary: 'Download portal quote PDF',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'inline', in: 'query', required: false, description: 'Filter by inline', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function downloadQuotePdf(Request $request, string $token): HttpResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $quote = Quote::where('contact_id', $accessToken->contact_id)
            ->when($accessToken->scope_type === Quote::class && $accessToken->scope_id, fn ($q) => $q->where('id', $accessToken->scope_id))
            ->firstOrFail();

        $this->tokenService->logActivity(
            token: $accessToken,
            action: 'quote_pdf_downloaded',
            subjectType: 'quote',
            subjectId: $quote->id
        );

        $rendered = $this->pdfService->render($quote);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($rendered->content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$rendered->filename}\"",
        ]);
    }

    /**
     * List customer invoices with payment status and totals.
     */
    #[OA\Get(
        path: '/api/v1/portal/invoices/{token}',
        operationId: 'listInvoicesPortalInvoices',
        description: 'Returns the customer invoices with payment status and totals.',
        summary: 'List portal invoices',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PortalInvoiceResource'))])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function listInvoices(string $token): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $invoices = Invoice::with('items')
            ->where('contact_id', $accessToken->contact_id)
            ->latest('date')
            ->paginate(20);

        return $this->successResponse(
            data: PortalInvoiceResource::collection($invoices),
            message: 'Invoices retrieved successfully'
        );
    }

    /**
     * Download Invoice PDF via portal link.
     */
    #[OA\Get(
        path: '/api/v1/portal/invoices/{token}/{id}/pdf',
        operationId: 'getDownloadInvoicePdfPortalInvoice',
        description: 'Downloads the PDF of a customer invoice.',
        summary: 'Download portal invoice PDF',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the {token}', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'inline', in: 'query', required: false, description: 'Filter by inline', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function downloadInvoicePdf(Request $request, string $token, string $invoiceId): HttpResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $invoice = Invoice::where('contact_id', $accessToken->contact_id)->findOrFail($invoiceId);

        $this->tokenService->logActivity(
            token: $accessToken,
            action: 'invoice_downloaded',
            subjectType: 'invoice',
            subjectId: $invoice->id
        );

        $rendered = $this->pdfService->render($invoice);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($rendered->content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$rendered->filename}\"",
        ]);
    }

    /**
     * List customer signed service reports.
     */
    #[OA\Get(
        path: '/api/v1/portal/service-reports/{token}',
        operationId: 'listServiceReportsPortalServiceReports',
        description: 'Returns the signed service reports of the customer.',
        summary: 'List portal service reports',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PortalServiceReportResource'))])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function listServiceReports(string $token): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $reports = ServiceReport::with(['lines', 'attachments'])
            ->where('contact_id', $accessToken->contact_id)
            ->portalVisible()
            ->latest('report_date')
            ->paginate(20);

        return $this->successResponse(
            data: PortalServiceReportResource::collection($reports),
            message: 'Service reports retrieved successfully'
        );
    }

    /**
     * Download Service Report PDF via portal link.
     */
    #[OA\Get(
        path: '/api/v1/portal/service-reports/{token}/{id}/pdf',
        operationId: 'getDownloadServiceReportPdfPortalServiceReport',
        description: 'Downloads the PDF of a customer service report.',
        summary: 'Download portal service report PDF',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the {token}', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'inline', in: 'query', required: false, description: 'Filter by inline', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function downloadServiceReportPdf(Request $request, string $token, string $reportId): HttpResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $report = ServiceReport::where('contact_id', $accessToken->contact_id)
            ->portalVisible()
            ->findOrFail($reportId);

        $this->tokenService->logActivity(
            token: $accessToken,
            action: 'service_report_downloaded',
            subjectType: 'service_report',
            subjectId: $report->id
        );

        $rendered = $this->pdfService->render($report);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($rendered->content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$rendered->filename}\"",
        ]);
    }

    /**
     * List customer support tickets with customer-facing messages.
     */
    #[OA\Get(
        path: '/api/v1/portal/tickets/{token}',
        operationId: 'listTicketsPortalTickets',
        description: 'Returns the tickets of the customer, newest first, 20 per page (query page; meta carries current_page, per_page and total): the helpdesk tickets of the service module, with their public messages, and any ticket of the former tickets table. Every item has the PortalTicketResource shape; helpdesk items add ticket_number and have an empty attachments list (their files stay in the helpdesk).',
        summary: 'List portal tickets',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PortalTicketResource'))])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function listTickets(string $token): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        // Tickets are written by the helpdesk of the service module (esm_tickets); tickets of the former tickets table
        // still appear. Both kinds accept replies on /portal/tickets/{token}/reply/{ticketId}.
        $helpdesk = HelpdeskTicket::query()
            ->where('tenant_id', $accessToken->tenant_id)
            ->where('customer_id', $accessToken->contact_id)
            ->with(['messages' => fn ($query) => $query->public()->orderBy('created_at')])
            ->get()
            ->map(fn (HelpdeskTicket $ticket): array => (new PortalHelpdeskTicketResource($ticket))->resolve());

        $legacy = Ticket::with(['messages' => fn ($q) => $q->customerFacing(), 'attachments'])
            ->where('client_id', $accessToken->contact_id)
            ->get()
            ->map(fn (Ticket $ticket): array => (new PortalTicketResource($ticket))->resolve());

        $perPage = 20;
        $page = max(1, request()->integer('page', 1));
        $tickets = $helpdesk->concat($legacy)->sortByDesc('created_at')->values();

        return $this->successResponse(
            data: $tickets->forPage($page, $perPage)->values()->all(),
            meta: ['current_page' => $page, 'per_page' => $perPage, 'total' => $tickets->count()],
            message: 'Support tickets retrieved successfully'
        );
    }

    /**
     * Customer replies to a ticket.
     */
    #[OA\Post(
        path: '/api/v1/portal/tickets/{token}/reply/{ticketId}',
        operationId: 'replyTicketPortalTicketsReply',
        description: 'Adds a customer reply to a support ticket.',
        summary: 'Reply to portal ticket',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'ticketId', in: 'path', required: true, description: 'Identifier of the ticket', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/ReplyPortalTicketRequest'))),
        responses: [
            new OA\Response(response: 201, description: 'Reply created', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'author_name'), new OA\Property(property: 'message'), new OA\Property(property: 'created_at')])])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function replyTicket(ReplyPortalTicketRequest $request, string $token, string $ticketId): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $ticket = Ticket::where('client_id', $accessToken->contact_id)->find($ticketId);

        if ($ticket === null) {
            // A helpdesk ticket of the service module: the reply joins its conversation.
            $helpdeskTicket = HelpdeskTicket::query()
                ->where('tenant_id', $accessToken->tenant_id)
                ->where('customer_id', $accessToken->contact_id)
                ->findOrFail($ticketId);

            $helpdeskMessage = $this->helpdeskThread->addCustomerMessage(
                ticket: $helpdeskTicket,
                body: $request->validated('message'),
                contact: $accessToken->contact,
                authorName: $request->validated('author_name'),
                channel: 'portal',
                attachments: array_values($request->file('attachments', [])),
            );

            $this->tokenService->logActivity($accessToken, 'ticket_replied', 'esm_ticket', $helpdeskTicket->id);

            return $this->successResponse(
                data: [
                    'id' => $helpdeskMessage->id,
                    'author_name' => $helpdeskMessage->author_name,
                    'message' => $helpdeskMessage->body,
                    'created_at' => $helpdeskMessage->created_at?->toISOString(),
                ],
                message: 'Reply posted successfully',
                statusCode: Response::HTTP_CREATED
            );
        }

        $message = $this->replyToTicketAction->execute(
            ticket: $ticket,
            token: $accessToken,
            message: $request->validated('message'),
            authorName: $request->validated('author_name'),
            attachments: $request->file('attachments', [])
        );

        return $this->successResponse(
            data: [
                'id' => $message->id,
                'author_name' => $message->author_name,
                'message' => $message->message,
                'created_at' => $message->created_at?->toISOString(),
            ],
            message: 'Reply posted successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    /**
     * List customer appointments visible on the portal.
     */
    #[OA\Get(
        path: '/api/v1/portal/appointments/{token}',
        operationId: 'listAppointmentsPortalAppointments',
        description: 'Returns the customer appointments visible on the portal.',
        summary: 'List portal appointments',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'appointment_number'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'starts_at'), new OA\Property(property: 'ends_at'), new OA\Property(property: 'all_day', type: 'boolean', nullable: true), new OA\Property(property: 'address'), new OA\Property(property: 'customer_notes'), new OA\Property(property: 'status', type: 'string'), new OA\Property(property: 'technicians', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'role')])), new OA\Property(property: 'is_cancelled', type: 'boolean')]))])])),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function listAppointments(string $token): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $this->tokenService->logActivity(
            token: $accessToken,
            action: 'appointments_viewed',
            subjectType: 'contact',
            subjectId: $accessToken->contact_id
        );

        $appointments = Appointment::with(['status', 'attendees.user'])
            ->where('tenant_id', $accessToken->tenant_id)
            ->where('contact_id', $accessToken->contact_id)
            ->portalVisible()
            ->orderBy('starts_at', 'asc')
            ->get()
            ->map(fn (Appointment $apt) => [
                'id' => $apt->id,
                'appointment_number' => $apt->appointment_number,
                'title' => $apt->title,
                'description' => $apt->description,
                'starts_at' => $apt->starts_at?->toISOString(),
                'ends_at' => $apt->ends_at?->toISOString(),
                'all_day' => $apt->all_day,
                'address' => $apt->address,
                'customer_notes' => $apt->customer_notes,
                'status' => $apt->status?->code,
                'technicians' => $apt->attendees->map(fn ($a) => [
                    'name' => $a->user?->name,
                    'role' => $a->role,
                ]),
                'is_cancelled' => $apt->isCancelled(),
            ]);

        return $this->successResponse(
            data: $appointments,
            message: 'Appointments retrieved successfully'
        );
    }

    /**
     * Securely download or view an attachment via portal link.
     */
    #[OA\Get(
        path: '/api/v1/portal/attachments/{token}/{attachment}',
        operationId: 'getDownloadAttachmentPortalAttachments',
        description: 'Securely downloads or displays an attachment, after verifying that the customer owns it through its parent record.',
        summary: 'Download portal attachment',
        security: [],
        tags: ['Customer Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, description: 'Customer portal magic-link token', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'attachment', in: 'path', required: true, description: 'Identifier of the attachment', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function downloadAttachment(string $token, string $attachmentId): HttpResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        /** @var Attachment $attachment */
        $attachment = Attachment::where('tenant_id', $accessToken->tenant_id)->findOrFail($attachmentId);

        // Verify attachment ownership through its attachable model
        $attachable = $attachment->attachable;
        $authorized = match (true) {
            $attachable instanceof Quote => $attachable->contact_id === $accessToken->contact_id,
            $attachable instanceof Invoice => $attachable->contact_id === $accessToken->contact_id,
            $attachable instanceof ServiceReport => $attachable->contact_id === $accessToken->contact_id,
            $attachable instanceof Ticket => $attachable->client_id === $accessToken->contact_id,
            $attachable instanceof Appointment => $attachable->contact_id === $accessToken->contact_id,
            default => false,
        };

        if (! $authorized) {
            abort(Response::HTTP_FORBIDDEN, 'Unauthorized attachment access.');
        }

        $this->tokenService->logActivity(
            token: $accessToken,
            action: 'attachment_downloaded',
            subjectType: 'attachment',
            subjectId: $attachment->id
        );

        $disk = config('filesystems.private_disk', config('filesystems.default', 'local'));

        if (! Storage::disk($disk)->exists($attachment->path)) {
            abort(Response::HTTP_NOT_FOUND, 'File not found on storage.');
        }

        return response(Storage::disk($disk)->get($attachment->path), Response::HTTP_OK, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => "inline; filename=\"{$attachment->original_name}\"",
        ]);
    }
}
