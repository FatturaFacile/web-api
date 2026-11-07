<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Documents\Exceptions\DuplicateDocumentException;
use Modules\Documents\Http\Requests\ImportAttachmentRequest;
use Modules\Documents\Http\Requests\LinkDocumentRequest;
use Modules\Documents\Http\Requests\PlaceLegalHoldRequest;
use Modules\Documents\Http\Requests\StoreDocumentRequest;
use Modules\Documents\Http\Requests\UpdateDocumentRequest;
use Modules\Documents\Http\Resources\DocumentResource;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentLibrary;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents of the company. The file API of attachments stays as it is: attachments come in here by reference.
 */
final class DocumentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentLibrary $library,
    ) {}

    #[OA\Get(
        path: '/api/v1/dms/documents',
        operationId: 'listDmsDocuments',
        description: 'Documents the role of the caller can see. `q` searches title, description, type, tags, file names and metadata.',
        summary: 'Search documents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'folder_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'document_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'archived'])),
            new OA\Parameter(name: 'tag', in: 'query', required: false, description: 'Tag name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'linkable_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'linkable_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsDocumentResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $documents = $this->access->restrict(DmsDocument::query(), $request->user())
            ->with('tags')
            ->when($request->filled('q'), fn ($query) => $query->whereRaw("dms_documents.search_vector @@ websearch_to_tsquery('simple', ?)", [(string) $request->query('q')]))
            ->when($request->filled('folder_id'), fn ($query) => $query->where('folder_id', $request->query('folder_id')))
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->query('document_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('tag'), fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->whereRaw('lower(name) = lower(?)', [(string) $request->query('tag')])))
            ->when($request->filled('linkable_id'), fn ($query) => $query->whereHas('links', fn ($links) => $links->where('linkable_id', $request->query('linkable_id'))->where('linkable_type', $request->query('linkable_type'))))
            ->latest()
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return $this->successResponse(
            DocumentResource::collection($documents->getCollection()),
            'Documents',
            meta: ['current_page' => $documents->currentPage(), 'last_page' => $documents->lastPage(), 'per_page' => $documents->perPage(), 'total' => $documents->total()]
        );
    }

    #[OA\Post(
        path: '/api/v1/dms/documents',
        operationId: 'createDmsDocument',
        description: 'Store a file on the private disk as the first version of a new document. A file with the same hash answers 409 unless `allow_duplicate` is set.',
        summary: 'Create document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/DmsStoreDocumentRequest'))),
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 201, description: 'Document created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Duplicate file', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $attributes = collect($request->validated())->except(['file', 'comment', 'allow_duplicate', 'tag_ids'])->all();

        try {
            $document = $this->library->create(
                $attributes,
                $request->file('file'),
                $request->validated('comment'),
                $request->boolean('allow_duplicate'),
                $request->validated('tag_ids', []),
                $request->user()
            );
        } catch (DuplicateDocumentException $exception) {
            return $this->duplicate($exception);
        }

        return $this->successResponse(new DocumentResource($document->load(['tags', 'versions'])), 'Document created', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/dms/documents/duplicates',
        operationId: 'listDmsDuplicates',
        description: 'Groups of visible documents that hold the same file, by hash.',
        summary: 'List duplicates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 200, description: 'Duplicate groups', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'sha256', type: 'string'), new OA\Property(property: 'document_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function duplicates(Request $request): JsonResponse
    {
        $visible = $this->access->restrict(DmsDocument::query(), $request->user())->pluck('id');

        return $this->successResponse($this->library->duplicateGroups($visible));
    }

    #[OA\Post(
        path: '/api/v1/dms/documents/import-attachment',
        operationId: 'importDmsAttachment',
        description: 'Bring an existing attachment in by reference: its file is hashed where it is, never copied, and the document links to the record of the attachment. Importing again returns the same document.',
        summary: 'Import attachment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsImportAttachmentRequest')),
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 201, description: 'Document created or found', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 404, description: 'Attachment not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function importAttachment(ImportAttachmentRequest $request): JsonResponse
    {
        $attachment = Attachment::query()->findOrFail($request->validated('attachment_id'));
        $document = $this->library->importAttachment($attachment, $request->validated('folder_id'), $request->user());

        return $this->successResponse(new DocumentResource($document->load(['links', 'versions'])), 'Attachment imported', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/dms/documents/{id}',
        operationId: 'getDmsDocument',
        description: 'A document with its versions, tags and links.',
        summary: 'Show document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Document', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        return $this->successResponse(new DocumentResource($document->load(['tags', 'links', 'versions'])));
    }

    #[OA\Put(
        path: '/api/v1/dms/documents/{id}',
        operationId: 'updateDmsDocument',
        description: 'Change the metadata of a document. A document under legal hold answers 409.',
        summary: 'Update document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsUpdateDocumentRequest')),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Document updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Under legal hold', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateDocumentRequest $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());
        $attributes = collect($request->validated())->except('tag_ids')->all();
        $document = $this->library->update($document, $attributes, $request->validated('tag_ids'));

        return $this->successResponse(new DocumentResource($document->load('tags')), 'Document updated');
    }

    #[OA\Delete(
        path: '/api/v1/dms/documents/{id}',
        operationId: 'deleteDmsDocument',
        description: 'Delete a document. A legal hold or an open retention period answers 409.',
        summary: 'Delete document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Document deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Protected by a legal hold or retention', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->library->delete($this->access->findVisible($id, $request->user()));

        return $this->successResponse(null, 'Document deleted');
    }

    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/links',
        operationId: 'linkDmsDocument',
        description: 'Link a document to a business record of the tenant.',
        summary: 'Link document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsLinkDocumentRequest')),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Link created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown record', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function link(LinkDocumentRequest $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());
        $this->library->link($document, $request->validated('linkable_type'), $request->validated('linkable_id'), $request->user());

        return $this->successResponse(new DocumentResource($document->load('links')), 'Link created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/dms/documents/{id}/links/{linkId}',
        operationId: 'unlinkDmsDocument',
        description: 'Remove a link to a business record.',
        summary: 'Unlink document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'linkId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Link removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function unlink(Request $request, string $id, string $linkId): JsonResponse
    {
        $this->access->findVisible($id, $request->user())->links()->findOrFail($linkId)->delete();

        return $this->successResponse(null, 'Link removed');
    }

    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/legal-hold',
        operationId: 'placeDmsLegalHold',
        description: 'Freeze a document: no change, no new version, no deletion and no retention until released. Owners and administrators only.',
        summary: 'Place legal hold',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsPlaceLegalHoldRequest')),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Legal hold placed', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function placeLegalHold(PlaceLegalHoldRequest $request, string $id): JsonResponse
    {
        $this->access->assertManager($request->user());
        $document = $this->library->placeLegalHold($this->access->findVisible($id, $request->user()), $request->validated('reason'), $request->user());

        return $this->successResponse(new DocumentResource($document), 'Legal hold placed');
    }

    #[OA\Delete(
        path: '/api/v1/dms/documents/{id}/legal-hold',
        operationId: 'releaseDmsLegalHold',
        description: 'Release a legal hold. Owners and administrators only.',
        summary: 'Release legal hold',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Legal hold released', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function releaseLegalHold(Request $request, string $id): JsonResponse
    {
        $this->access->assertManager($request->user());
        $document = $this->library->releaseLegalHold($this->access->findVisible($id, $request->user()));

        return $this->successResponse(new DocumentResource($document), 'Legal hold released');
    }

    private function duplicate(DuplicateDocumentException $exception): JsonResponse
    {
        return $this->problemDetailsResponse(
            title: 'Duplicate document',
            detail: $exception->getMessage(),
            status: Response::HTTP_CONFLICT,
            invalidParams: ['existing_document_id' => $exception->existingDocumentId, 'sha256' => $exception->hash],
        );
    }
}
