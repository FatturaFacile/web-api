<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Plm\Http\Controllers\Concerns\InteractsWithPlm;
use Modules\Plm\Http\Requests\RevisionRequest;
use Modules\Plm\Http\Resources\RevisionResource;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Services\ItemRevisionService;
use OpenApi\Attributes as OA;

/**
 * Revisions of the manufacturing items.
 */
final class RevisionController extends Controller
{
    use InteractsWithPlm;

    public function __construct(private readonly ItemRevisionService $revisions) {}

    #[OA\Get(path: '/api/v1/plm/revisions', operationId: 'listPlmItemRevisions', description: 'Item revisions, the latest first. Filters: mfg_item_id, status (draft, released, obsolete).', summary: 'List item revisions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], responses: [
        new OA\Response(response: 200, description: 'Item revisions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlmItemRevision'))])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PlmItemRevision::class);
        $filters = $request->validate([
            'mfg_item_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:'.implode(',', PlmItemRevision::Statuses)],
        ]);

        return RevisionResource::collection(
            PlmItemRevision::query()
                ->with('item:id,item_code,name')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['mfg_item_id']), static fn ($query) => $query->where('mfg_item_id', $filters['mfg_item_id']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/plm/revisions', operationId: 'storePlmItemRevision', description: 'Create a draft revision of a manufacturing item. The revision label is unique per item.', summary: 'Create an item revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlmItemRevisionRequest')), tags: ['PLM'], responses: [
        new OA\Response(response: 201, description: 'Revision created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmItemRevision')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function store(RevisionRequest $request): JsonResponse
    {
        $this->authorize('create', PlmItemRevision::class);

        return (new RevisionResource($this->revisions->create($this->tenantId(), $request->validated())->load('item:id,item_code,name')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/plm/revisions/{id}', operationId: 'showPlmItemRevision', description: 'Return an item revision.', summary: 'Show an item revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Item revision', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmItemRevision')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
    ])]
    public function show(string $id): RevisionResource
    {
        $revision = PlmItemRevision::query()->with('item:id,item_code,name')->findOrFail($id);
        $this->authorize('view', $revision);

        return new RevisionResource($revision);
    }

    #[OA\Put(path: '/api/v1/plm/revisions/{id}', operationId: 'updatePlmItemRevision', description: 'Change a draft revision; a released or obsolete one is final.', summary: 'Update an item revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PlmItemRevisionRequest')), tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Revision updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmItemRevision')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function update(RevisionRequest $request, string $id): RevisionResource
    {
        $revision = PlmItemRevision::query()->findOrFail($id);
        $this->authorize('update', $revision);

        return new RevisionResource($this->revisions->update($revision, $request->validated())->load('item:id,item_code,name'));
    }

    #[OA\Delete(path: '/api/v1/plm/revisions/{id}', operationId: 'deletePlmItemRevision', description: 'Delete a draft revision (soft delete).', summary: 'Delete an item revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 204, description: 'Revision deleted'),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $revision = PlmItemRevision::query()->findOrFail($id);
        $this->authorize('delete', $revision);
        $this->revisions->delete($revision);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/plm/revisions/{id}/release', operationId: 'releasePlmItemRevision', description: 'Release a draft revision. Allowed only while the item has no released revision (its first one); later revisions are released by implementing a change order.', summary: 'Release an item revision', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Revision released', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmItemRevision')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function release(string $id): RevisionResource
    {
        $revision = PlmItemRevision::query()->findOrFail($id);
        $this->authorize('update', $revision);

        return new RevisionResource($this->revisions->release($revision));
    }

    #[OA\Post(path: '/api/v1/plm/revisions/{id}/obsolete', operationId: 'obsoletePlmItemRevision', description: 'Retire a released revision without a successor.', summary: 'Make an item revision obsolete', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['PLM'], parameters: [new OA\Parameter(ref: '#/components/parameters/PlmId')], responses: [
        new OA\Response(response: 200, description: 'Revision obsolete', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PlmItemRevision')])),
        new OA\Response(ref: '#/components/responses/PlmUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PlmForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PlmNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PlmUnprocessable', response: 422),
    ])]
    public function obsolete(string $id): RevisionResource
    {
        $revision = PlmItemRevision::query()->findOrFail($id);
        $this->authorize('update', $revision);

        return new RevisionResource($this->revisions->obsolete($revision));
    }
}
