<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Documents\Http\Resources\OcrJobResource;
use Modules\Documents\Models\DmsOcrJob;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentOcrPipeline;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * OCR pipeline: queue the reading of a document version by the installed engine and follow its extraction status.
 */
final class DocumentOcrController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly DocumentAccess $access, private readonly DocumentOcrPipeline $pipeline) {}

    /**
     * Queue OCR for a document.
     */
    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/ocr',
        operationId: 'queueDmsOcr',
        description: 'Queue OCR of the current version. The engine is picked by format: the built-in engine reads text formats, scans and images need an engine installed for the company. A run already queued or processing for the version is returned instead of a new one.',
        summary: 'Queue OCR',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document OCR'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 202, description: 'OCR queued', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsOcrJobResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(Request $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        return $this->successResponse(new OcrJobResource($this->pipeline->queue($document, $request->user())->refresh()), 'OCR queued', Response::HTTP_ACCEPTED);
    }

    /**
     * List OCR runs of a document.
     */
    #[OA\Get(
        path: '/api/v1/dms/documents/{id}/ocr',
        operationId: 'listDmsOcrJobs',
        description: 'OCR runs of a document, newest first. Add `include_text=1` to get the recognised text.',
        summary: 'List OCR runs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document OCR'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'include_text', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OCR runs', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsOcrJobResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        return $this->successResponse(OcrJobResource::collection(DmsOcrJob::query()->where('document_id', $document->id)->latest()->get()));
    }

    /**
     * Show an OCR run.
     */
    #[OA\Get(
        path: '/api/v1/dms/ocr-jobs/{id}',
        operationId: 'showDmsOcrJob',
        description: 'One OCR run with its extraction status. Add `include_text=1` to get the recognised text.',
        summary: 'Show an OCR run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document OCR'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'include_text', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OCR run', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsOcrJobResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new OcrJobResource($this->visibleJob($request, $id)));
    }

    /**
     * Retry a failed OCR run.
     */
    #[OA\Post(
        path: '/api/v1/dms/ocr-jobs/{id}/retry',
        operationId: 'retryDmsOcrJob',
        description: 'Queue a failed run again, for instance after an engine for the format was installed.',
        summary: 'Retry an OCR run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document OCR'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 202, description: 'OCR queued again', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsOcrJobResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The run did not fail', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function retry(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new OcrJobResource($this->pipeline->retry($this->visibleJob($request, $id))->refresh()), 'OCR queued', Response::HTTP_ACCEPTED);
    }

    private function visibleJob(Request $request, string $id): DmsOcrJob
    {
        $job = DmsOcrJob::query()->findOrFail($id);
        $this->access->findVisible((string) $job->document_id, $request->user());

        return $job;
    }
}
