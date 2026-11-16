<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Collaboration\Http\Controllers\Concerns\InteractsWithCollaboration;
use Modules\Collaboration\Http\Requests\SearchRequest;
use Modules\Collaboration\Services\CollaborationRegistry;
use Modules\Collaboration\Services\GlobalSearchService;
use OpenApi\Attributes as OA;

/**
 * Global search across the key entities, and the members that can be mentioned.
 */
final class SearchController extends Controller
{
    use InteractsWithCollaboration;

    public function __construct(
        private readonly GlobalSearchService $search,
        private readonly CollaborationRegistry $registry,
    ) {}

    #[OA\Get(path: '/api/v1/collaboration/search', operationId: 'collaborationSearch', description: 'Search the key entities (contacts, invoices, quotes, products, projects, suppliers, employees) of the active company for a text. Only the record types the member may read are searched. Filters: types[] (record type keys), limit (hits per type, 1 to 10).', summary: 'Global search', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [
        new OA\Parameter(name: 'q', description: 'Text to find, at least 2 characters', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 100)),
        new OA\Parameter(name: 'types[]', description: 'Restrict the search to these record types', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
        new OA\Parameter(name: 'limit', description: 'Hits per record type', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 10)),
    ], responses: [
        new OA\Response(response: 200, description: 'Global search', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabSearchResult'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function index(SearchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        return response()->json(['data' => $this->search->search(
            $user,
            $this->tenantId(),
            (string) $validated['q'],
            isset($validated['types']) ? array_values($validated['types']) : null,
            (int) ($validated['limit'] ?? 5),
        )]);
    }

    #[OA\Get(path: '/api/v1/collaboration/record-types', operationId: 'collaborationRecordTypes', description: 'The record types the member may search, comment on and tag, with their translated names.', summary: 'List record types', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], responses: [
        new OA\Response(response: 200, description: 'List record types', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabRecordType'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
    ])]
    public function recordTypes(): JsonResponse
    {
        $types = $this->registry->readableBy(request()->user(), $this->tenantId());

        return response()->json(['data' => array_values(array_map(
            static fn ($type): array => ['key' => $type->key, 'label' => __($type->labelKey)],
            $types,
        ))]);
    }

    #[OA\Get(path: '/api/v1/collaboration/mentionable-users', operationId: 'collaborationMentionableUsers', description: 'Members of the active company that a comment can mention, filtered by name or email (q), at most 20.', summary: 'List mentionable users', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [
        new OA\Parameter(name: 'q', description: 'Part of the name or email', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'List mentionable users', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabMentionableUser'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function mentionableUsers(): JsonResponse
    {
        $filters = request()->validate(['q' => ['nullable', 'string', 'max:100']]);
        $needle = mb_strtolower((string) ($filters['q'] ?? ''));

        $users = User::query()
            ->whereHas('tenants', fn ($tenants) => $tenants->where('tenants.id', $this->tenantId()))
            ->when($needle !== '', static function ($query) use ($needle): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';
                $query->where(static function ($group) use ($pattern): void {
                    $group->whereRaw("lower(name) like ? escape '!'", [$pattern])
                        ->orWhereRaw("lower(email) like ? escape '!'", [$pattern]);
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $users->map(static fn (User $user): array => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email])->all()]);
    }
}
