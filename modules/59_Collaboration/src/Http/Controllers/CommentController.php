<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Collaboration\Http\Controllers\Concerns\InteractsWithCollaboration;
use Modules\Collaboration\Http\Requests\CommentRequest;
use Modules\Collaboration\Http\Resources\CommentResource;
use Modules\Collaboration\Models\CollabComment;
use Modules\Collaboration\Services\CommentService;
use OpenApi\Attributes as OA;

/**
 * Comments, with mentions, on any record of the collaboration registry.
 */
final class CommentController extends Controller
{
    use InteractsWithCollaboration;

    public function __construct(private readonly CommentService $comments) {}

    #[OA\Get(path: '/api/v1/collaboration/records/{type}/{recordId}/comments', operationId: 'listCollaborationComments', description: 'Comments of a record, newest first.', summary: 'List comments of a record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabRecordType'), new OA\Parameter(ref: '#/components/parameters/CollabRecordId')], responses: [
        new OA\Response(response: 200, description: 'List comments of a record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollabComment'))])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
    ])]
    public function index(string $type, string $recordId): AnonymousResourceCollection
    {
        $recordType = $this->recordType($type);
        $record = $this->record($recordType, $recordId);
        $this->authorize('viewAny', CollabComment::class);

        return CommentResource::collection(
            CollabComment::query()
                ->where('tenant_id', $this->tenantId())
                ->where('commentable_type', $recordType->key)
                ->where('commentable_id', (string) $record->getKey())
                ->with('author:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/collaboration/records/{type}/{recordId}/comments', operationId: 'storeCollaborationComment', description: 'Comment on a record. The members listed in mentions are notified, except the author.', summary: 'Comment on a record', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CollabCommentRequest')), tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabRecordType'), new OA\Parameter(ref: '#/components/parameters/CollabRecordId')], responses: [
        new OA\Response(response: 201, description: 'Comment on a record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CollabComment')])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function store(CommentRequest $request, string $type, string $recordId): JsonResponse
    {
        $recordType = $this->recordType($type);
        $record = $this->record($recordType, $recordId);
        $this->authorize('create', CollabComment::class);

        /** @var User $author */
        $author = $request->user();
        $comment = $this->comments->create($recordType, $record, $author, (string) $request->validated('body'), array_values($request->validated('mentions') ?? []));

        return (new CommentResource($comment->load('author:id,name')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/collaboration/comments/{id}', operationId: 'updateCollaborationComment', description: 'Edit a comment of your own. Members added to the mentions are notified.', summary: 'Edit a comment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CollabCommentRequest')), tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabId')], responses: [
        new OA\Response(response: 200, description: 'Edit a comment', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CollabComment')])),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CollabUnprocessable', response: 422),
    ])]
    public function update(CommentRequest $request, string $id): CommentResource
    {
        $comment = CollabComment::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $comment);
        $recordType = $this->recordType($comment->commentable_type);
        $record = $this->record($recordType, $comment->commentable_id);

        /** @var User $author */
        $author = $request->user();
        $this->comments->update($comment, $recordType, $record, $author, (string) $request->validated('body'), array_values($request->validated('mentions') ?? []));

        return new CommentResource($comment->load('author:id,name'));
    }

    #[OA\Delete(path: '/api/v1/collaboration/comments/{id}', operationId: 'deleteCollaborationComment', description: 'Delete a comment of your own (owners and administrators may delete any comment).', summary: 'Delete a comment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Collaboration'], parameters: [new OA\Parameter(ref: '#/components/parameters/CollabId')], responses: [
        new OA\Response(response: 204, description: 'Comment deleted'),
        new OA\Response(ref: '#/components/responses/CollabUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CollabForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CollabNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $comment = CollabComment::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('delete', $comment);
        $comment->delete();

        return response()->noContent();
    }
}
