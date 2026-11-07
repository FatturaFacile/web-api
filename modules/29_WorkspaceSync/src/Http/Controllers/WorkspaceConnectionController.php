<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\WorkspaceSync\Http\Requests\AuthorizeWorkspaceRequest;
use Modules\WorkspaceSync\Http\Requests\WorkspaceCallbackRequest;
use Modules\WorkspaceSync\Http\Resources\WorkspaceConnectionResource;
use Modules\WorkspaceSync\Http\Resources\WorkspaceItemResource;
use Modules\WorkspaceSync\Models\WorkspaceConnection;
use Modules\WorkspaceSync\Models\WorkspaceSyncedItem;
use Modules\WorkspaceSync\Services\WorkspaceSyncService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every call works on the connections of the caller: an owner of the company cannot read the mail of a colleague.
 */
final class WorkspaceConnectionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WorkspaceSyncService $sync) {}

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
        $connection = $this->sync->connect($provider, $request->validated('code'), $request->validated('state'), $request->user());

        return $this->successResponse(new WorkspaceConnectionResource($connection), 'Connected', Response::HTTP_CREATED);
    }

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
        return $this->successResponse(['items' => $this->sync->sync($this->ownConnection($request, $id))]);
    }

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

    #[OA\Get(
        path: '/api/v1/workspace/items',
        operationId: 'listWorkspaceItems',
        description: 'Synced calendar events and message metadata of the caller.',
        summary: 'List own items',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workspace Sync'],
        parameters: [new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['calendar_event', 'email']))],
        responses: [
            new OA\Response(response: 200, description: 'Items', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkspaceItemResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function items(Request $request): JsonResponse
    {
        $connections = WorkspaceConnection::query()->where('user_id', $request->user()->id)->pluck('id');

        return $this->successResponse(WorkspaceItemResource::collection(
            WorkspaceSyncedItem::query()
                ->whereIn('connection_id', $connections)
                ->when($request->query('kind'), static fn ($query, $kind) => $query->where('kind', $kind))
                ->orderByDesc('starts_at')
                ->limit(200)
                ->get()
        ));
    }

    private function ownConnection(Request $request, string $id): WorkspaceConnection
    {
        return WorkspaceConnection::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
