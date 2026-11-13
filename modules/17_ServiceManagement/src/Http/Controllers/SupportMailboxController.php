<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Modules\ServiceManagement\Http\Requests\SaveSupportMailboxRequest;
use Modules\ServiceManagement\Http\Resources\SupportMailboxMessageResource;
use Modules\ServiceManagement\Http\Resources\SupportMailboxResource;
use Modules\ServiceManagement\Jobs\PollSupportMailboxesJob;
use Modules\ServiceManagement\Models\SupportMailbox;
use Modules\ServiceManagement\Models\SupportMailboxMessage;
use OpenApi\Attributes as OA;

/**
 * Support mailboxes of the tenant, polled to turn e-mail into tickets. Managed by the service managers.
 */
final class SupportMailboxController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Lists the support mailboxes.
     */
    #[OA\Get(path: '/api/v1/esm/helpdesk/mailboxes', operationId: 'listEsmSupportMailboxes', description: 'Lists the support mailboxes of the tenant. Credentials are never returned.', summary: 'List support mailboxes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Email'], responses: [
        new OA\Response(response: 200, description: 'Mailboxes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskSupportMailboxResource'))])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SupportMailbox::class);

        return response()->json(['data' => SupportMailboxResource::collection(SupportMailbox::query()->where('tenant_id', $this->tenantContext->getTenantId())->orderBy('name')->get())]);
    }

    /**
     * Adds a support mailbox.
     */
    #[OA\Post(path: '/api/v1/esm/helpdesk/mailboxes', operationId: 'storeEsmSupportMailbox', description: 'Adds a support mailbox. The IMAP credentials are stored encrypted.', summary: 'Add support mailbox', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Email'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveSupportMailboxRequest')), responses: [
        new OA\Response(response: 201, description: 'Mailbox added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskSupportMailboxResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(SaveSupportMailboxRequest $request): JsonResponse
    {
        $this->authorize('create', SupportMailbox::class);

        $mailbox = SupportMailbox::query()->create([...$request->validated(), 'tenant_id' => $this->tenantContext->getTenantId()]);

        return response()->json(['data' => new SupportMailboxResource($mailbox)], 201);
    }

    /**
     * Changes a support mailbox.
     */
    #[OA\Patch(path: '/api/v1/esm/helpdesk/mailboxes/{id}', operationId: 'updateEsmSupportMailbox', description: 'Changes a support mailbox. A missing password keeps the stored one.', summary: 'Change support mailbox', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Email'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HelpdeskSaveSupportMailboxRequest')), responses: [
        new OA\Response(response: 200, description: 'Mailbox changed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HelpdeskSupportMailboxResource')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(SaveSupportMailboxRequest $request, string $id): JsonResponse
    {
        $mailbox = $this->find($id);
        $this->authorize('update', $mailbox);
        $mailbox->update($request->validated());

        return response()->json(['data' => new SupportMailboxResource($mailbox->refresh())]);
    }

    /**
     * Removes a support mailbox.
     */
    #[OA\Delete(path: '/api/v1/esm/helpdesk/mailboxes/{id}', operationId: 'deleteEsmSupportMailbox', description: 'Removes a support mailbox. Tickets already created stay.', summary: 'Remove support mailbox', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Email'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 204, description: 'Mailbox removed'),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $mailbox = $this->find($id);
        $this->authorize('delete', $mailbox);
        $mailbox->delete();

        return response()->json(null, 204);
    }

    /**
     * Polls a support mailbox now.
     */
    #[OA\Post(path: '/api/v1/esm/helpdesk/mailboxes/{id}/poll', operationId: 'pollEsmSupportMailbox', description: 'Queues an immediate poll of the mailbox, besides the scheduled one.', summary: 'Poll support mailbox', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Email'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath')], responses: [
        new OA\Response(response: 202, description: 'Poll queued', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function poll(string $id): JsonResponse
    {
        $mailbox = $this->find($id);
        $this->authorize('update', $mailbox);
        PollSupportMailboxesJob::dispatch($mailbox->tenant_id, $mailbox->id);

        return response()->json(['message' => 'Mailbox poll queued'], 202);
    }

    /**
     * Lists the messages read from a mailbox and what became of them.
     */
    #[OA\Get(path: '/api/v1/esm/helpdesk/mailboxes/{id}/messages', operationId: 'listEsmSupportMailboxMessages', description: 'Lists the latest messages read from the mailbox: ticket created, reply threaded, duplicate, or ignored as auto-reply or mail loop.', summary: 'Support mailbox log', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['ESM Helpdesk Email'], parameters: [new OA\Parameter(ref: '#/components/parameters/HelpdeskIdPath'), new OA\Parameter(ref: '#/components/parameters/HelpdeskPage')], responses: [
        new OA\Response(response: 200, description: 'Log', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskSupportMailboxMessageResource'))])),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function messages(string $id): JsonResponse
    {
        $mailbox = $this->find($id);
        $this->authorize('view', $mailbox);

        return response()->json(['data' => SupportMailboxMessageResource::collection(SupportMailboxMessage::query()->where('mailbox_id', $mailbox->id)->orderByDesc('created_at')->paginate(RequestedPerPage::resolve(50)))]);
    }

    private function find(string $id): SupportMailbox
    {
        return SupportMailbox::query()->where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }
}
