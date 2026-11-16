<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Communication\Http\Controllers\Concerns\InteractsWithCommunication;
use Modules\Communication\Http\Requests\AssignConversationRequest;
use Modules\Communication\Http\Requests\ChangeConversationStatusRequest;
use Modules\Communication\Http\Requests\InboundMessageRequest;
use Modules\Communication\Http\Requests\ReplyConversationRequest;
use Modules\Communication\Http\Resources\ConversationMessageResource;
use Modules\Communication\Http\Resources\ConversationResource;
use Modules\Communication\Models\CommunicationConversation;
use Modules\Communication\Models\ContactChannelPreference;
use Modules\Communication\Services\InboxService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unified inbox: conversations that aggregate the messages of every channel, with assignment and status.
 */
final class ConversationController extends Controller
{
    use InteractsWithCommunication;

    public function __construct(private readonly InboxService $inbox) {}

    #[OA\Get(path: '/api/v1/communication/conversations', operationId: 'listCommunicationConversations', description: 'Inbox conversations, latest activity first. Filters: status, channel, assigned_to (user id, or "none" for unassigned), unread (true).', summary: 'List conversations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Inbox'], responses: [
        new OA\Response(response: 200, description: 'Conversations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationConversation'))])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CommunicationConversation::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(CommunicationConversation::Statuses)],
            'channel' => ['nullable', Rule::in(ContactChannelPreference::Channels)],
            'assigned_to' => ['nullable', 'string'],
            'unread' => ['nullable', 'boolean'],
        ]);

        return ConversationResource::collection(
            CommunicationConversation::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['channel']), static fn ($query) => $query->where('channel', $filters['channel']))
                ->when(($filters['assigned_to'] ?? null) === 'none', static fn ($query) => $query->whereNull('assigned_to'))
                ->when(isset($filters['assigned_to']) && $filters['assigned_to'] !== 'none', static fn ($query) => $query->where('assigned_to', $filters['assigned_to']))
                ->when($request->boolean('unread'), static fn ($query) => $query->where('unread_count', '>', 0))
                ->orderByDesc('last_message_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/communication/conversations/inbound', operationId: 'receiveCommunicationInboundMessage', description: 'Records a message received on a channel (from a webhook, a mailbox poller or by hand). It joins the open conversation of the counterparty on that channel or opens a new one, and reopens a resolved or pending thread.', summary: 'Record an inbound message', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationInboundMessageRequest')), tags: ['Communication Inbox'], responses: [
        new OA\Response(response: 201, description: 'Message recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationConversationMessage')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function receive(InboundMessageRequest $request): JsonResponse
    {
        $this->authorize('create', CommunicationConversation::class);

        return (new ConversationMessageResource($this->inbox->receive($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/communication/conversations/{id}', operationId: 'showCommunicationConversation', description: 'A conversation with all its messages in chronological order.', summary: 'Show a conversation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Inbox'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Conversation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationConversation')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
    ])]
    public function show(string $id): ConversationResource
    {
        $conversation = CommunicationConversation::query()->with(['messages' => static fn ($query) => $query->orderBy('occurred_at')])->findOrFail($id);
        $this->authorize('view', $conversation);

        return new ConversationResource($conversation);
    }

    #[OA\Post(path: '/api/v1/communication/conversations/{id}/reply', operationId: 'replyCommunicationConversation', description: 'Sends a reply on the channel of the conversation through the delivery driver of the channel and logs the attempt. An open conversation becomes pending (waiting for the counterparty). A closed conversation cannot be answered.', summary: 'Reply to a conversation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationReplyRequest')), tags: ['Communication Inbox'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 201, description: 'Reply sent or logged as failed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationConversationMessage')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function reply(ReplyConversationRequest $request, string $id): JsonResponse
    {
        $conversation = CommunicationConversation::query()->findOrFail($id);
        $this->authorize('update', $conversation);

        return (new ConversationMessageResource($this->inbox->reply($conversation, $request->validated('body'), $request->user()?->id)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/communication/assignees', operationId: 'listCommunicationAssignees', description: 'Members of the active company a conversation can be assigned to.', summary: 'List conversation assignees', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Inbox'], responses: [
        new OA\Response(response: 200, description: 'Members', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')], type: 'object'))])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
    ])]
    public function assignees(): JsonResponse
    {
        $this->authorize('viewAny', CommunicationConversation::class);
        $members = User::query()
            ->whereIn('id', DB::table('tenant_user')->where('tenant_id', $this->tenantId())->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $members->map(static fn (User $user): array => ['id' => (string) $user->id, 'name' => (string) $user->name])->values()]);
    }

    #[OA\Post(path: '/api/v1/communication/conversations/{id}/assign', operationId: 'assignCommunicationConversation', description: 'Assigns the conversation to a member of the company; a null user_id unassigns it.', summary: 'Assign a conversation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationAssignRequest')), tags: ['Communication Inbox'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Conversation assigned', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationConversation')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function assign(AssignConversationRequest $request, string $id): ConversationResource
    {
        $conversation = CommunicationConversation::query()->findOrFail($id);
        $this->authorize('update', $conversation);

        return new ConversationResource($this->inbox->assign($conversation, $request->validated('user_id')));
    }

    #[OA\Post(path: '/api/v1/communication/conversations/{id}/status', operationId: 'changeCommunicationConversationStatus', description: 'Moves the conversation to open, pending, resolved or closed. Resolving or closing stamps resolved_at.', summary: 'Change the status of a conversation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CommunicationConversationStatusRequest')), tags: ['Communication Inbox'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationConversation')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnprocessable', response: 422),
    ])]
    public function changeStatus(ChangeConversationStatusRequest $request, string $id): ConversationResource
    {
        $conversation = CommunicationConversation::query()->findOrFail($id);
        $this->authorize('update', $conversation);

        return new ConversationResource($this->inbox->changeStatus($conversation, $request->validated('status')));
    }

    #[OA\Post(path: '/api/v1/communication/conversations/{id}/read', operationId: 'markCommunicationConversationRead', description: 'Resets the unread counter of the conversation.', summary: 'Mark a conversation as read', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Communication Inbox'], parameters: [new OA\Parameter(ref: '#/components/parameters/CommunicationInboxId')], responses: [
        new OA\Response(response: 200, description: 'Conversation marked as read', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CommunicationConversation')])),
        new OA\Response(ref: '#/components/responses/CommunicationInboxUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CommunicationInboxForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CommunicationInboxNotFound', response: 404),
    ])]
    public function read(string $id): ConversationResource
    {
        $conversation = CommunicationConversation::query()->findOrFail($id);
        $this->authorize('update', $conversation);

        return new ConversationResource($this->inbox->markRead($conversation));
    }
}
