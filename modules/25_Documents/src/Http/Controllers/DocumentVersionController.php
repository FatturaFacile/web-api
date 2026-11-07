<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Exceptions\DuplicateDocumentException;
use Modules\Documents\Http\Requests\StoreDocumentVersionRequest;
use Modules\Documents\Http\Resources\DocumentVersionResource;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentLibrary;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentVersionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentLibrary $library,
    ) {}

    #[OA\Get(
        path: '/api/v1/dms/documents/{id}/versions',
        operationId: 'listDmsVersions',
        description: 'Every version of a document, newest first, with its hash.',
        summary: 'List versions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Versions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsDocumentVersionResource'))])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(DocumentVersionResource::collection($this->access->findVisible($id, $request->user())->versions()->get()));
    }

    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/versions',
        operationId: 'createDmsVersion',
        description: 'Add a new version. The earlier versions stay untouched. A file equal to the current version answers 409.',
        summary: 'Add version',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [new OA\Property(property: 'file', type: 'string', format: 'binary'), new OA\Property(property: 'comment', type: 'string', maxLength: 500, nullable: true)]))),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Version added', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsDocumentVersionResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Same file or legal hold', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreDocumentVersionRequest $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        try {
            $version = $this->library->addVersion($document, $request->file('file'), $request->validated('comment'), $request->user());
        } catch (DuplicateDocumentException $exception) {
            return $this->problemDetailsResponse('Duplicate version', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->successResponse(new DocumentVersionResource($version), 'Version added', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/dms/documents/{id}/versions/{versionId}/download',
        operationId: 'downloadDmsVersion',
        description: 'Download the file of a version from the private disk.',
        summary: 'Download version',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'versionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File', content: new OA\MediaType(mediaType: 'application/octet-stream')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function download(Request $request, string $id, string $versionId): StreamedResponse
    {
        $version = $this->version($request, $id, $versionId);

        return Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    #[OA\Get(
        path: '/api/v1/dms/documents/{id}/versions/{versionId}/verify',
        operationId: 'verifyDmsVersion',
        description: 'Hash the stored file again and compare it with the hash recorded at upload.',
        summary: 'Verify version integrity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'versionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Verification result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'recorded_sha256', type: 'string'), new OA\Property(property: 'current_sha256', type: 'string', nullable: true), new OA\Property(property: 'is_intact', type: 'boolean')])])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verify(Request $request, string $id, string $versionId): JsonResponse
    {
        $version = $this->version($request, $id, $versionId);
        $disk = Storage::disk($version->disk);
        $current = $disk->exists($version->path) ? hash('sha256', (string) $disk->get($version->path)) : null;

        return $this->successResponse(['recorded_sha256' => $version->sha256, 'current_sha256' => $current, 'is_intact' => $current === $version->sha256]);
    }

    private function version(Request $request, string $id, string $versionId): DmsDocumentVersion
    {
        return $this->access->findVisible($id, $request->user())->versions()->findOrFail($versionId);
    }
}
