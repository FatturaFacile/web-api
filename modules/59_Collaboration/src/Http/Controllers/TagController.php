<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Collaboration\Http\Controllers\Concerns\InteractsWithCollaboration;
use Modules\Collaboration\Http\Requests\AttachTagRequest;
use Modules\Collaboration\Http\Requests\TagRequest;
use Modules\Collaboration\Http\Resources\TagResource;
use Modules\Collaboration\Models\CollabTag;
use Modules\Collaboration\Models\CollabTaggable;
use Modules\Collaboration\Services\CollaborationRegistry;
use Modules\Collaboration\Services\TagService;
use OpenApi\Attributes as OA;

/**
 * The shared tags of the company and their attachment to records.
 */
final class TagController extends Controller
{
    use InteractsWithCollaboration;

    public function __construct(
        private readonly TagService $tags,
        private readonly CollaborationRegistry $registry,
    ) {}

    #[OA\Get(path: '/api/v1/collaboration/tags', operationId: 'listCollaborationTags', description: 'Tags of the company with how many records carry each. Filter: q (part of the name).', summary: 'List tags', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], responses: [
        new OA\Response(response: 200, description: 'List tags', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabTag'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CollabTag::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:60']]);
        $needle = mb_strtolower((string) ($filters['q'] ?? ''));

        return TagResource::collection(
            CollabTag::query()
                ->where('tenant_id', $this->tenantId())
                ->withCount('taggables')
                ->when($needle !== '', static fn ($query) => $query->whereRaw("lower(name) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%']))
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/collaboration/tags', operationId: 'storeCollaborationTag', description: 'Create a tag shared by the company.', summary: 'Create a tag', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CollabTagRequest')), tags: ['Collaboration'], responses: [
        new OA\Response(response: 201, description: 'Create a tag', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CollabTag')])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function store(TagRequest $request): JsonResponse
    {
        $this->authorize('create', CollabTag::class);
        $tag = CollabTag::query()->create(['tenant_id' => $this->tenantId(), ...$request->validated()]);

        return (new TagResource($tag))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/collaboration/tags/{id}', operationId: 'updateCollaborationTag', description: 'Rename or recolour a tag.', summary: 'Update a tag', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CollabTagRequest')), tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabId')], responses: [
        new OA\Response(response: 200, description: 'Update a tag', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CollabTag')])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function update(TagRequest $request, string $id): TagResource
    {
        $tag = CollabTag::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $tag);
        $tag->update($request->validated());

        return new TagResource($tag);
    }

    #[OA\Delete(path: '/api/v1/collaboration/tags/{id}', operationId: 'deleteCollaborationTag', description: 'Delete a tag and remove it from every record.', summary: 'Delete a tag', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabId')], responses: [
        new OA\Response(response: 204, description: 'Tag deleted'),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $tag = CollabTag::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $tag);
        $this->tags->delete($tag);

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/collaboration/tags/{id}/records', operationId: 'listCollaborationTagRecords', description: 'Records that carry the tag, limited to the record types the member may read. Filter: type.', summary: 'List the records of a tag', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabId')], responses: [
        new OA\Response(response: 200, description: 'List the records of a tag', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabSearchResult'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function records(Request $request, string $id): JsonResponse
    {
        $tag = CollabTag::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $tag);
        $filters = $request->validate(['type' => ['nullable', 'string']]);

        $readable = $this->registry->readableBy($request->user(), $this->tenantId());
        $links = CollabTaggable::query()
            ->where('tag_id', $tag->id)
            ->when(isset($filters['type']), static fn ($query) => $query->where('taggable_type', $filters['type']))
            ->whereIn('taggable_type', array_keys($readable))
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $results = [];

        foreach ($links->groupBy('taggable_type') as $typeKey => $group) {
            $type = $readable[$typeKey];
            $records = $type->modelClass::query()
                ->where((new $type->modelClass)->qualifyColumn('tenant_id'), $this->tenantId())
                ->whereKey($group->pluck('taggable_id')->all())
                ->get();

            foreach ($records as $record) {
                $results[] = [
                    'type' => $type->key,
                    'type_label' => __($type->labelKey),
                    'id' => (string) $record->getKey(),
                    'title' => $type->titleOf($record),
                    'subtitle' => $type->subtitleOf($record),
                    'path' => $type->pathOf($record),
                ];
            }
        }

        return response()->json(['data' => $results]);
    }

    #[OA\Get(path: '/api/v1/collaboration/records/{type}/{recordId}/tags', operationId: 'listCollaborationRecordTags', description: 'Tags attached to a record.', summary: 'List the tags of a record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabRecordType'), new OA\Parameter(ref: '#/components/parameters/CollabRecordId')], responses: [
        new OA\Response(response: 200, description: 'List the tags of a record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabTag'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
    ])]
    public function forRecord(string $type, string $recordId): AnonymousResourceCollection
    {
        $recordType = $this->recordType($type);
        $record = $this->record($recordType, $recordId);
        $this->authorize('viewAny', CollabTag::class);

        return TagResource::collection($this->tags->tagsOf($recordType, $record));
    }

    #[OA\Post(path: '/api/v1/collaboration/records/{type}/{recordId}/tags', operationId: 'attachCollaborationTag', description: 'Attach a tag to a record, by tag_id or by name (created when missing). Attaching twice keeps one link.', summary: 'Attach a tag to a record', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CollabAttachTagRequest')), tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabRecordType'), new OA\Parameter(ref: '#/components/parameters/CollabRecordId')], responses: [
        new OA\Response(response: 200, description: 'Attach a tag to a record: the tags of the record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabTag'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function attach(AttachTagRequest $request, string $type, string $recordId): AnonymousResourceCollection
    {
        $recordType = $this->recordType($type);
        $record = $this->record($recordType, $recordId);
        $this->authorize('create', CollabTag::class);

        $tag = $request->validated('tag_id') !== null
            ? CollabTag::query()->where('tenant_id', $this->tenantId())->findOrFail($request->validated('tag_id'))
            : $this->tagByName((string) $request->validated('name'));
        $this->tags->attach($recordType, $record, $tag);

        return TagResource::collection($this->tags->tagsOf($recordType, $record));
    }

    #[OA\Delete(path: '/api/v1/collaboration/records/{type}/{recordId}/tags/{tagId}', operationId: 'detachCollaborationTag', description: 'Remove a tag from a record.', summary: 'Detach a tag from a record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabRecordType'), new OA\Parameter(ref: '#/components/parameters/CollabRecordId'), new OA\Parameter(ref: '#/components/parameters/CollabTagId')], responses: [
        new OA\Response(response: 204, description: 'Tag detached'),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
    ])]
    public function detach(string $type, string $recordId, string $tagId): Response
    {
        $recordType = $this->recordType($type);
        $record = $this->record($recordType, $recordId);
        $tag = CollabTag::query()->where('tenant_id', $this->tenantId())->findOrFail($tagId);
        $this->authorize('delete', $tag);
        $this->tags->detach($recordType, $record, $tag);

        return response()->noContent();
    }

    /**
     * The tag of the company with that name, whatever the case, created when missing.
     */
    private function tagByName(string $name): CollabTag
    {
        $name = trim($name);

        return CollabTag::query()
            ->where('tenant_id', $this->tenantId())
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first() ?? CollabTag::query()->create(['tenant_id' => $this->tenantId(), 'name' => $name]);
    }
}
