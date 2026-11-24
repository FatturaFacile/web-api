<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\BusinessIntelligence\Http\Controllers\Concerns\InteractsWithBi;
use Modules\BusinessIntelligence\Http\Requests\ShareRequest;
use Modules\BusinessIntelligence\Http\Resources\ShareResource;
use Modules\BusinessIntelligence\Models\BiShare;
use Modules\BusinessIntelligence\Services\SharingService;
use OpenApi\Attributes as OA;

/**
 * Sharing of dashboards and saved queries with members of the company.
 */
final class ShareController extends Controller
{
    use InteractsWithBi;

    public function __construct(private readonly SharingService $sharing) {}

    #[OA\Get(path: '/api/v1/business-intelligence/shares', operationId: 'listBiShares', description: 'The members a dashboard or a saved query is shared with. Only the owner of the resource, or an owner or administrator of the company, can see them.', summary: 'List the shares of a resource', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [
        new OA\Parameter(name: 'resource_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['dashboard', 'saved_query'])),
        new OA\Parameter(name: 'resource_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Shares', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiShare'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['resource_type' => ['required', Rule::in(BiShare::ResourceTypes)], 'resource_id' => ['required', 'uuid']]);
        $resource = $this->sharing->resource($filters['resource_type'], $filters['resource_id']);
        $this->authorize('share', $resource);

        return ShareResource::collection(BiShare::query()->with('user:id,name')->where('resource_type', $filters['resource_type'])->where('resource_id', $resource->id)->orderBy('created_at')->get());
    }

    #[OA\Get(path: '/api/v1/business-intelligence/members', operationId: 'listBiShareMembers', description: 'The active members of the company (identifier and name only) a resource can be shared with.', summary: 'List the members to share with', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 200, description: 'Members', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'name', type: 'string')], type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
    ])]
    public function members(): JsonResponse
    {
        $members = Tenant::query()->findOrFail($this->tenantId())->users()
            ->wherePivotNull('suspended_at')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(static fn (User $user): array => ['id' => (string) $user->id, 'name' => (string) $user->name])
            ->values()
            ->all();

        return response()->json(['data' => $members]);
    }

    #[OA\Post(path: '/api/v1/business-intelligence/shares', operationId: 'storeBiShare', description: 'Share a dashboard or a saved query with a member of the company for viewing or editing, or change an existing share. The recipient must be a member of the company and cannot be the owner. A private resource becomes shared.', summary: 'Share a resource', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiShareRequest')), tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 201, description: 'Share saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiShare')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function store(ShareRequest $request): JsonResponse
    {
        $data = $request->validated();
        $resource = $this->sharing->resource($data['resource_type'], $data['resource_id']);
        $this->authorize('share', $resource);
        $share = $this->sharing->share($resource, $this->currentUser(), $data['user_id'], $data['permission']);

        return (new ShareResource($share->load('user:id,name')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/business-intelligence/shares/{id}', operationId: 'deleteBiShare', description: 'Withdraw a share. When it was the last one, a shared resource becomes private again.', summary: 'Remove a share', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 204, description: 'Share removed'),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $share = BiShare::query()->findOrFail($id);
        $this->authorize('share', $this->sharing->resource($share->resource_type, $share->resource_id));
        $this->sharing->unshare($share);

        return response()->noContent();
    }
}
