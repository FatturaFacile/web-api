<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\WorkspaceSync\Http\Requests\AuthorizeWorkspaceRequest;
use Modules\WorkspaceSync\Http\Requests\WorkspaceCallbackRequest;
use Modules\WorkspaceSync\Http\Resources\WorkspaceConnectionResource;
use Modules\WorkspaceSync\Http\Resources\WorkspaceItemResource;
use Modules\WorkspaceSync\Models\WorkspaceConnection;
use Modules\WorkspaceSync\Models\WorkspaceSyncedItem;
use Modules\WorkspaceSync\Services\WorkspaceSyncService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Every call works on the connections of the caller: an owner of the company cannot read the mail of a colleague.
 */
final class WorkspaceConnectionController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly WorkspaceSyncService $sync) {}

    /**
     * List own connections.
     */
    #[OA\Get(
        path: '/api/v1/workspace/connections',
        operationId: 'listWorkspaceConnections',
        description: 'The connections of the caller.',
        summary: 'List own connections',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workspace Sync'],
        responses: [
            new OA\Response(response: 200, description: 'Connections', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkspaceConnectionResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(WorkspaceConnectionResource::collection(WorkspaceConnection::query()->where('user_id', $request->user()->id)->get()));
    }

    /**
     * Start connection.
     */
    #[OA\Post(
        path: '/api/v1/workspace/connections/{provider}/authorize',
        operationId: 'authorizeWorkspaceConnection',
        description: 'Give consent and get the address where the provider asks for it too. Requires `consent`.',
        summary: 'Start connection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AuthorizeWorkspaceRequest')),
        tags: ['Workspace Sync'],
        parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['google', 'microsoft']))],
        responses: [
            new OA\Response(response: 200, description: 'Authorization address', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'authorization_url', type: 'string', format: 'uri')])])),
            new OA\Response(response: 422, description: 'No consent', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 503, description: 'Provider not configured', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function authorizeConnection(AuthorizeWorkspaceRequest $request, string $provider): JsonResponse
    {
        return $this->successResponse(['authorization_url' => $this->sync->authorizationUrl($provider, $request->validated('scopes'), $request->user())]);
    }

    /**
     * Complete connection.
     */
    #[OA\Post(
        path: '/api/v1/workspace/connections/{provider}/callback',
        operationId: 'completeWorkspaceConnection',
        description: 'Finish the connection with the code and state from the provider. The state works once, for the user who started.',
        summary: 'Complete connection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkspaceCallbackRequest')),
        tags: ['Workspace Sync'],
        parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['google', 'microsoft']))],
        responses: [
            new OA\Response(response: 201, description: 'Connected', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkspaceConnectionResource')])),
            new OA\Response(response: 422, description: 'Invalid state', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function callback(WorkspaceCallbackRequest $request, string $provider): JsonResponse
    {
        $connection = $this->viaProvider(fn (): WorkspaceConnection => $this->sync->connect($provider, $request->validated('code'), $request->validated('state'), $request->user()));

        return $this->successResponse(new WorkspaceConnectionResource($connection), 'Connected', Response::HTTP_CREATED);
    }

    /**
     * Sync connection.
     */
    #[OA\Post(
        path: '/api/v1/workspace/connections/{id}/sync',
        operationId: 'syncWorkspaceConnection',
        description: 'Fetch new calendar events and message metadata now.',
        summary: 'Sync connection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workspace Sync'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Synced', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'items', type: 'integer')])])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function sync(Request $request, string $id): JsonResponse
    {
        $connection = $this->ownConnection($request, $id);

        return $this->successResponse(['items' => $this->viaProvider(fn (): int => $this->sync->sync($connection))]);
    }

    /**
     * Revoke connection.
     */
    #[OA\Delete(
        path: '/api/v1/workspace/connections/{id}',
        operationId: 'revokeWorkspaceConnection',
        description: 'Withdraw consent: the tokens and everything synced are erased.',
        summary: 'Revoke connection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workspace Sync'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function revoke(Request $request, string $id): JsonResponse
    {
        $this->sync->revoke($this->ownConnection($request, $id));

        return $this->successResponse(null, 'Connection revoked');
    }

    /**
     * List own items.
     */
    #[OA\Get(
        path: '/api/v1/workspace/items',
        operationId: 'listWorkspaceItems',
        description: 'Synced calendar events and message metadata of the caller.',
        summary: 'List own items',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workspace Sync'],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['calendar_event', 'email'])),
            new OA\Parameter(name: 'contact_id', description: 'Only the items exchanged with this CRM contact: the counterpart address contains the email of the contact. A contact of another tenant, or without an email, is refused.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 200, maximum: 200)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Items', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkspaceItemResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filter, or unknown contact', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function items(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['nullable', 'in:calendar_event,email'],
            'contact_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $email = null;

        if (isset($filters['contact_id'])) {
            $email = Contact::query()->whereKey($filters['contact_id'])->value('email');

            if ($email === null) {
                throw ValidationException::withMessages(['contact_id' => __('validation.exists', ['attribute' => 'contact id'])]);
            }
        }

        $connections = WorkspaceConnection::query()->where('user_id', $request->user()->id)->pluck('id');

        $page = WorkspaceSyncedItem::query()
            ->whereIn('connection_id', $connections)
            ->when($filters['kind'] ?? null, static fn ($query, string $kind) => $query->where('kind', $kind))
            // The counterpart is the address of the other party, as the provider wrote it ("Name <address>" included).
            ->when($email, static fn ($query, string $address) => $query->whereRaw('lower(counterpart) like ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($address)).'%']))
            ->orderByDesc('starts_at')
            ->gridPaginate((int) ($filters['per_page'] ?? 200));

        return $this->successResponse(
            WorkspaceItemResource::collection($page->getCollection()),
            'Items retrieved',
            meta: ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        );
    }

    /**
     * Run a call to the provider, turning a provider failure into a 502 instead of a server error.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function viaProvider(Closure $call): mixed
    {
        try {
            return $call();
        } catch (RequestException|ConnectionException) {
            throw new HttpException(Response::HTTP_BAD_GATEWAY, __('workspace_sync_errors.provider.unreachable'));
        }
    }

    private function ownConnection(Request $request, string $id): WorkspaceConnection
    {
        return WorkspaceConnection::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
