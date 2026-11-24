<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\StoredFileHeaders;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use App\Support\Http\RequestedPerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Modules\ServiceManagement\Contracts\TicketServiceInterface;
use Modules\ServiceManagement\DTOs\CreateTicketDTO;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Http\Requests\PortalHelpdeskReplyRequest;
use Modules\ServiceManagement\Http\Requests\PortalHelpdeskStoreTicketRequest;
use Modules\ServiceManagement\Http\Resources\PortalHelpdeskMessageResource;
use Modules\ServiceManagement\Http\Resources\PortalHelpdeskTicketResource;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketAttachment;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskThreadService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The helpdesk tickets of a customer on the portal. The tenant and the customer come from the magic-link token, never
 * from the request; internal notes are never shown.
 */
final class PortalHelpdeskController extends Controller
{
    public function __construct(
        private readonly PortalTokenService $tokenService,
        private readonly HelpdeskThreadService $thread,
        private readonly TicketServiceInterface $ticketService,
    ) {}

    /**
     * Lists the helpdesk tickets of the customer.
     */
    #[OA\Get(path: '/api/v1/portal/helpdesk/{token}/tickets', operationId: 'listPortalHelpdeskTickets', description: 'Lists the helpdesk tickets of the customer the link belongs to, newest first.', summary: 'List portal helpdesk tickets', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken'), new OA\Parameter(ref: '#/components/parameters/HelpdeskPage')], responses: [
        new OA\Response(response: 200, description: 'Tickets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskPortalTicketResource')), new OA\Property(property: 'meta', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')], type: 'object')])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(string $token): JsonResponse
    {
        $access = $this->tokenService->resolveToken($token);
        $tickets = $this->customerTickets($access)->whereNull('merged_into_ticket_id')->orderByDesc('created_at')->gridPaginate(RequestedPerPage::resolve(20));

        return response()->json([
            'data' => PortalHelpdeskTicketResource::collection($tickets),
            'meta' => ['current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), 'per_page' => $tickets->perPage(), 'total' => $tickets->total()],
        ]);
    }

    /**
     * Opens a new ticket for the customer.
     */
    #[OA\Post(path: '/api/v1/portal/helpdesk/{token}/tickets', operationId: 'storePortalHelpdeskTicket', description: 'Opens a new helpdesk ticket for the customer the link belongs to.', summary: 'Open portal helpdesk ticket', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskPortalStoreTicketRequest')), responses: [
        new OA\Response(response: 201, description: 'Ticket opened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskPortalTicketResource')])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(PortalHelpdeskStoreTicketRequest $request, string $token): JsonResponse
    {
        $access = $this->tokenService->resolveToken($token);
        $contact = $access->contact;

        $ticket = $this->ticketService->createTicket(new CreateTicketDTO(
            tenantId: $access->tenant_id,
            companyId: $access->tenant_id,
            title: $request->validated('subject'),
            description: $request->validated('description'),
            customerId: $access->contact_id,
            contactEmail: $contact?->email,
            channel: 'portal',
            status: 'new',
        ));

        $this->tokenService->logActivity($access, 'helpdesk_ticket_opened', 'esm_ticket', $ticket->id);

        return response()->json(['data' => new PortalHelpdeskTicketResource($ticket)], 201);
    }

    /**
     * Shows a ticket of the customer with its public conversation.
     */
    #[OA\Get(path: '/api/v1/portal/helpdesk/{token}/tickets/{ticket}', operationId: 'showPortalHelpdeskTicket', description: 'Returns a ticket of the customer with its public replies, including those of tickets merged into it. Internal notes are never returned.', summary: 'Show portal helpdesk ticket', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken'), new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [
        new OA\Response(response: 200, description: 'Ticket and thread', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'ticket', ref: '#/components/schemas/HelpdeskPortalTicketResource'), new OA\Property(property: 'messages', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskPortalMessageResource'))], type: 'object')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $token, string $ticket): JsonResponse
    {
        $access = $this->tokenService->resolveToken($token);
        $model = $this->customerTickets($access)->findOrFail($ticket);
        $model->forceFill(['customer_last_read_at' => now()])->save();

        return response()->json(['data' => [
            'ticket' => new PortalHelpdeskTicketResource($model),
            'messages' => PortalHelpdeskMessageResource::collection($this->thread->thread($model, false)),
        ]]);
    }

    /**
     * Adds a reply of the customer to the conversation.
     */
    #[OA\Post(path: '/api/v1/portal/helpdesk/{token}/tickets/{ticket}/reply', operationId: 'replyPortalHelpdeskTicket', description: 'Adds a customer reply to the ticket thread. A ticket waiting for the customer goes back to open; a resolved or closed one reopens within the reopen window.', summary: 'Reply to portal helpdesk ticket', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken'), new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/HelpdeskPortalReplyRequest'))), responses: [
        new OA\Response(response: 201, description: 'Reply added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskPortalMessageResource')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or ticket closed for good', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function reply(PortalHelpdeskReplyRequest $request, string $token, string $ticket): JsonResponse
    {
        $access = $this->tokenService->resolveToken($token);
        $model = $this->customerTickets($access)->findOrFail($ticket);

        $message = $this->thread->addCustomerMessage(
            ticket: $model,
            body: $request->validated('message'),
            contact: $access->contact,
            authorName: $request->validated('author_name'),
            channel: 'portal',
            attachments: array_values($request->file('attachments', [])),
        );

        $this->tokenService->logActivity($access, 'helpdesk_ticket_replied', 'esm_ticket', $model->id);

        return response()->json(['data' => new PortalHelpdeskMessageResource($message->load('attachments'))], 201);
    }

    /**
     * Downloads an attachment of a public message of a customer ticket.
     */
    #[OA\Get(path: '/api/v1/portal/helpdesk/{token}/tickets/{ticket}/attachments/{attachment}', operationId: 'downloadPortalHelpdeskAttachment', description: 'Downloads an attachment of a public message of a ticket of the customer. Attachments of internal notes are never served.', summary: 'Download portal helpdesk attachment', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskPortalToken'), new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath'), new OA\Parameter(ref: '#/components/parameters/HelpdeskAttachmentPath')], responses: [
        new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function downloadAttachment(string $token, string $ticket, string $attachment): StreamedResponse
    {
        $access = $this->tokenService->resolveToken($token);
        $model = $this->customerTickets($access)->findOrFail($ticket);
        $ticketIds = [$model->id, ...Ticket::query()->where('merged_into_ticket_id', $model->id)->pluck('id')->all()];

        $file = TicketAttachment::query()
            ->whereIn('ticket_id', $ticketIds)
            ->whereHas('message', fn ($query) => $query->where('visibility', MessageVisibility::Public->value))
            ->findOrFail($attachment);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => StoredFileHeaders::for($file->mime_type, (string) $file->original_name, false)['Content-Type'], 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * @return Builder<Ticket>
     */
    private function customerTickets(PortalAccessToken $access): Builder
    {
        return Ticket::query()->where('tenant_id', $access->tenant_id)->where('customer_id', $access->contact_id);
    }
}
