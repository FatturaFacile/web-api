<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Documents\Http\Requests\StoreFolderRequest;
use Modules\Documents\Http\Resources\FolderResource;
use Modules\Documents\Models\DmsFolder;
use Modules\Documents\Services\DocumentAccess;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Folder tree of the company. A folder with roles stays hidden from every other role, together with its content.
 */
final class DocumentFolderController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly DocumentAccess $access) {}

    /**
     * List folders.
     */
    #[OA\Get(
        path: '/api/v1/dms/folders',
        operationId: 'listDmsFolders',
        description: 'Folders visible to the role of the caller.',
        summary: 'List folders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 200, description: 'Folders', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsFolderResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $denied = $this->access->isManager($request->user()) ? [] : $this->access->deniedFolderIds($this->access->roleOf($request->user()));

        return $this->successResponse(FolderResource::collection(DmsFolder::query()->whereNotIn('id', $denied)->orderBy('name')->get()));
    }

    /**
     * Create folder.
     */
    #[OA\Post(
        path: '/api/v1/dms/folders',
        operationId: 'createDmsFolder',
        description: 'Create a folder, optionally nested and restricted to roles.',
        summary: 'Create folder',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsStoreFolderRequest')),
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 201, description: 'Folder created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsFolderResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreFolderRequest $request): JsonResponse
    {
        $this->assertNameFree($request->validated('name'), $request->validated('parent_id'));

        try {
            $folder = DmsFolder::create([...$request->validated(), 'access_roles' => $request->validated('access_roles', []), 'created_by' => $request->user()->id]);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('A folder with this name already exists here.');
        }

        return $this->successResponse(new FolderResource($folder), 'Folder created', Response::HTTP_CREATED);
    }

    /**
     * Update folder.
     */
    #[OA\Put(
        path: '/api/v1/dms/folders/{id}',
        operationId: 'updateDmsFolder',
        description: 'Rename, move or restrict a folder.',
        summary: 'Update folder',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsStoreFolderRequest')),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Folder updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsFolderResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreFolderRequest $request, string $id): JsonResponse
    {
        $folder = $this->visibleFolder($request, $id);
        $this->assertNoCycle($folder, $request->validated('parent_id'));
        $this->assertNameFree($request->validated('name'), $request->validated('parent_id'), (string) $folder->id);

        try {
            $folder->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('A folder with this name already exists here.');
        }

        return $this->successResponse(new FolderResource($folder), 'Folder updated');
    }

    /**
     * Delete folder.
     */
    #[OA\Delete(
        path: '/api/v1/dms/folders/{id}',
        operationId: 'deleteDmsFolder',
        description: 'Delete an empty folder. A folder with documents or subfolders is refused.',
        summary: 'Delete folder',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Folder deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The folder is not empty', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $folder = $this->visibleFolder($request, $id);

        if ($folder->children()->exists() || $folder->documents()->exists()) {
            throw new ConflictHttpException('This folder is not empty.');
        }

        $folder->delete();

        return $this->successResponse(null, 'Folder deleted');
    }

    /**
     * Folder names are unique per parent, ignoring case. Checked up front for a clear answer; the unique index still
     * guards the race between two requests.
     *
     * @throws ConflictHttpException
     */
    private function assertNameFree(string $name, ?string $parentId, ?string $ignoreId = null): void
    {
        $taken = DmsFolder::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($parentId === null, static fn ($query) => $query->whereNull('parent_id'), static fn ($query) => $query->where('parent_id', $parentId))
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw new ConflictHttpException('A folder with this name already exists here.');
        }
    }

    /**
     * A folder cannot move into itself or into one of its own subfolders: the tree would lose its root.
     *
     * @throws ConflictHttpException
     */
    private function assertNoCycle(DmsFolder $folder, ?string $parentId): void
    {
        $cursor = $parentId;
        $seen = [];

        while ($cursor !== null && ! in_array($cursor, $seen, true)) {
            if ($cursor === (string) $folder->id) {
                throw new ConflictHttpException('A folder cannot be moved into itself or into one of its subfolders.');
            }

            $seen[] = $cursor;
            $cursor = DmsFolder::query()->whereKey($cursor)->value('parent_id');
            $cursor = $cursor === null ? null : (string) $cursor;
        }
    }

    private function visibleFolder(Request $request, string $id): DmsFolder
    {
        $denied = $this->access->isManager($request->user()) ? [] : $this->access->deniedFolderIds($this->access->roleOf($request->user()));

        return DmsFolder::query()->whereNotIn('id', $denied)->findOrFail($id);
    }
}
