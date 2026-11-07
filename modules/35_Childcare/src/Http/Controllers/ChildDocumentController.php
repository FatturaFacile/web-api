<?php

declare(strict_types=1);

namespace Modules\Childcare\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Modules\Childcare\Http\Requests\ChildRecordRequest;
use Modules\Childcare\Http\Resources\ChildcareResource;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ChildDocument;
use Modules\Childcare\Models\DocumentRequirement;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The virtual folder of each child, the forms every child must return, and the dashboard of what is missing.
 */
final class ChildDocumentController extends Controller
{
    #[OA\Get(path: '/api/v1/childcare/document-requirements', operationId: 'listChildcareDocumentRequirements', description: 'Return the forms every child must return.', summary: 'List required forms', security: [['bearerAuth' => []]], tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Required forms', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function requirements(): AnonymousResourceCollection
    {
        $this->authorize('manageRecords', Child::class);

        return ChildcareResource::collection(DocumentRequirement::query()->orderBy('name')->get());
    }

    #[OA\Post(path: '/api/v1/childcare/document-requirements', operationId: 'storeChildcareDocumentRequirement', description: 'Define a form every child must return, signed or not.', summary: 'Define required form', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRequirementRequest')), tags: ['Childcare'], responses: [
        new OA\Response(response: 201, description: 'Form defined', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function storeRequirement(ChildRecordRequest $request): JsonResponse
    {
        $this->authorize('manageRecords', Child::class);

        return (new ChildcareResource(DocumentRequirement::query()->create($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/childcare/children/{id}/documents', operationId: 'listChildcareChildDocuments', description: 'Return the virtual folder of a child.', summary: 'List child documents', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordCollection')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
    ])]
    public function index(string $id): AnonymousResourceCollection
    {
        $child = $this->child($id);

        return ChildcareResource::collection(ChildDocument::query()->where('child_id', $child->id)->latest()->get());
    }

    #[OA\Post(path: '/api/v1/childcare/children/{id}/documents', operationId: 'storeChildcareChildDocument', description: 'Put a document in the folder of a child: with a file it is returned, without one it is requested from the family.', summary: 'Add child document', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/ChildcareDocumentRequest'))), tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 201, description: 'Document added', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareRecordResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ChildcareUnprocessable', response: 422),
    ])]
    public function store(ChildRecordRequest $request, string $id): JsonResponse
    {
        $child = $this->child($id);
        $file = $request->file('file');
        $path = $file?->store("childcare/{$child->tenant_id}/{$child->id}", (string) config('childcare.documents_disk'));

        $document = ChildDocument::query()->create([
            'child_id' => $child->id,
            'requirement_id' => $request->validated('requirement_id'),
            'title' => $request->validated('title'),
            'status' => $file === null ? 'requested' : 'returned',
            'file_path' => $path === false ? null : $path,
            'mime_type' => $file?->getMimeType(),
            'file_hash' => $file === null ? null : hash_file('sha256', (string) $file->getRealPath()),
            'uploaded_by' => $request->user()?->getKey(),
        ]);

        return (new ChildcareResource($document))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/childcare/documents/{id}/download', operationId: 'downloadChildcareChildDocument', description: 'Download the file of a document.', summary: 'Download child document', security: [['bearerAuth' => []]], tags: ['Childcare'], parameters: [new OA\Parameter(ref: '#/components/parameters/ChildcareId')], responses: [
        new OA\Response(response: 200, description: 'File', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ChildcareNotFound', response: 404),
    ])]
    public function download(string $id): StreamedResponse
    {
        $document = ChildDocument::query()->findOrFail($id);
        $this->authorize('manageRecords', $document);
        abort_if($document->file_path === null, Response::HTTP_NOT_FOUND);

        return Storage::disk((string) config('childcare.documents_disk'))->download($document->file_path, preg_replace('/[^A-Za-z0-9._-]/', '_', $document->title).'.'.pathinfo($document->file_path, PATHINFO_EXTENSION));
    }

    #[OA\Get(path: '/api/v1/childcare/documents/outstanding', operationId: 'childcareOutstandingDocuments', description: 'Return, per active child, the mandatory forms not yet returned or signed.', summary: 'Outstanding forms', security: [['bearerAuth' => []]], tags: ['Childcare'], responses: [
        new OA\Response(response: 200, description: 'Outstanding forms', content: new OA\JsonContent(ref: '#/components/schemas/ChildcareDataResponse')),
        new OA\Response(ref: '#/components/responses/ChildcareUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ChildcareForbidden', response: 403),
    ])]
    public function outstanding(): JsonResponse
    {
        $this->authorize('manageRecords', Child::class);
        $requirements = DocumentRequirement::query()->where('is_mandatory', true)->get();
        $documents = ChildDocument::query()->whereNotNull('requirement_id')->get()->groupBy('child_id');
        $rows = [];

        foreach (Child::query()->where('status', 'active')->orderBy('reference_code')->get() as $child) {
            $missing = $requirements->filter(static function (DocumentRequirement $requirement) use ($documents, $child): bool {
                $done = $requirement->requires_signature ? ['signed'] : ['returned', 'signed'];

                return ! $documents->get($child->id, collect())->contains(static fn (ChildDocument $document): bool => $document->requirement_id === $requirement->id && in_array($document->status, $done, true));
            })->map(static fn (DocumentRequirement $requirement): array => ['requirement_id' => $requirement->id, 'name' => $requirement->name])->values()->all();

            if ($missing !== []) {
                $rows[] = ['child_id' => $child->id, 'reference_code' => $child->reference_code, 'name' => "{$child->last_name} {$child->first_name}", 'missing' => $missing];
            }
        }

        return response()->json(['data' => ['children' => $rows, 'total_missing' => array_sum(array_map(static fn (array $row): int => count($row['missing']), $rows))]]);
    }

    private function child(string $id): Child
    {
        $child = Child::query()->findOrFail($id);
        $this->authorize('manageRecords', $child);

        return $child;
    }
}
