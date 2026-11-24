<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\StoredFileHeaders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Http\Requests\StoreTicketMessageRequest;
use Modules\ServiceManagement\Http\Resources\HelpdeskTicketMessageResource;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketAttachment;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskThreadService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The conversation of a ticket as staff see it: public replies and internal notes, attachments and read state.
 */
final class TicketConversationController extends Controller
{
    public function __construct(private readonly HelpdeskThreadService $thread) {}

    /**
     * Returns the thread of a ticket, including internal notes and the messages of tickets merged into it.
     */
    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/messages', operationId: 'listEsmTicketMessages', description: 'Returns the thread of the ticket, oldest first: public replies, internal notes and the messages of tickets merged into it, with the number of messages the caller had not read yet.', summary: 'Ticket thread', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Ticket Conversation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [
        new OA\Response(response: 200, description: 'Thread', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskTicketMessageResource')), new OA\Property(property: 'meta', properties: [new OA\Property(property: 'unread', type: 'integer')], type: 'object')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json([
            'data' => HelpdeskTicketMessageResource::collection($this->thread->thread($ticket, true)),
            'meta' => ['unread' => $this->thread->unreadCount($ticket, $request->user())],
        ]);
    }

    /**
     * Adds a public reply or an internal note, with attachments.
     */
    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/messages', operationId: 'storeEsmTicketMessage', description: 'Adds a public reply, sent to the requester, or an internal note, seen only by staff. The first public reply records the first response; status moves the ticket in the same step.', summary: 'Reply to ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Ticket Conversation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], requestBody: new OA\RequestBody(required: true, content: [new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/HelpdeskStoreTicketMessageRequest')), new OA\JsonContent(ref: '#/components/schemas/HelpdeskStoreTicketMessageRequest')]), responses: [
        new OA\Response(response: 201, description: 'Message added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskTicketMessageResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(StoreTicketMessageRequest $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('reply', $ticket);
        $status = $request->validated('status');

        $message = $this->thread->addStaffMessage(
            $ticket,
            $request->user(),
            $request->validated('body'),
            MessageVisibility::from($request->validated('visibility') ?? MessageVisibility::Public->value),
            array_values($request->file('attachments', [])),
            $status === null ? null : TicketStatus::from($status),
        );

        return response()->json(['data' => new HelpdeskTicketMessageResource($message->load('attachments'))], 201);
    }

    /**
     * Marks the thread of a ticket as read by the caller.
     */
    #[OA\Post(path: '/api/v1/esm/tickets/{ticket}/read', operationId: 'markEsmTicketRead', description: 'Marks every message of the ticket as read by the caller.', summary: 'Mark ticket read', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Ticket Conversation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath')], responses: [
        new OA\Response(response: 200, description: 'Read state', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'last_read_at', type: 'string', format: 'date-time'), new OA\Property(property: 'unread', type: 'integer')], type: 'object')])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function markRead(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);
        $read = $this->thread->markRead($ticket, $request->user());

        return response()->json(['data' => ['last_read_at' => $read->last_read_at?->toIso8601String(), 'unread' => 0]]);
    }

    /**
     * Downloads an attachment of the ticket from the private disk.
     */
    #[OA\Get(path: '/api/v1/esm/tickets/{ticket}/attachments/{attachment}', operationId: 'downloadEsmTicketAttachment', description: 'Downloads an attachment of the ticket, or of a ticket merged into it, after checking that the caller may see the ticket.', summary: 'Download ticket attachment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Ticket Conversation'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskTicketPath'), new OA\Parameter(ref: '#/components/parameters/HelpdeskAttachmentPath')], responses: [
        new OA\Response(response: 200, description: 'File content', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function downloadAttachment(Ticket $ticket, string $attachment): StreamedResponse
    {
        $this->authorize('view', $ticket);

        $ticketIds = [$ticket->id, ...Ticket::query()->where('merged_into_ticket_id', $ticket->id)->pluck('id')->all()];
        $file = TicketAttachment::query()->whereIn('ticket_id', $ticketIds)->findOrFail($attachment);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => StoredFileHeaders::for($file->mime_type, (string) $file->original_name, false)['Content-Type'], 'X-Content-Type-Options' => 'nosniff']);
    }
}
