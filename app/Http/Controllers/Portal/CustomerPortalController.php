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
    ) {}

    /**
     * Authenticate magic link token and retrieve customer dashboard summary.
     */
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
    public function listTickets(string $token): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $tickets = Ticket::with(['messages' => fn ($q) => $q->customerFacing(), 'attachments'])
            ->where('client_id', $accessToken->contact_id)
            ->latest()
            ->paginate(20);

        return $this->successResponse(
            data: PortalTicketResource::collection($tickets),
            message: 'Support tickets retrieved successfully'
        );
    }

    /**
     * Customer replies to a ticket.
     */
    public function replyTicket(ReplyPortalTicketRequest $request, string $token, string $ticketId): JsonResponse
    {
        $accessToken = $this->tokenService->resolveToken($token);

        $ticket = Ticket::where('client_id', $accessToken->contact_id)->findOrFail($ticketId);

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
