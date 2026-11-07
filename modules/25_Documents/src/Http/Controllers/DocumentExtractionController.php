<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Documents\Http\Requests\ConfirmExtractionRequest;
use Modules\Documents\Http\Resources\ExtractionResource;
use Modules\Documents\Models\DmsExtraction;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentExtractionService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI extraction only proposes. A person confirms or rejects, and nothing is posted automatically.
 */
final class DocumentExtractionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentExtractionService $extraction,
    ) {}

    #[OA\Get(
        path: '/api/v1/dms/documents/{id}/extractions',
        operationId: 'listDmsExtractions',
        description: 'Extraction proposals of a document.',
        summary: 'List extractions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Extractions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsExtractionResource'))])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        return $this->successResponse(ExtractionResource::collection(DmsExtraction::query()->where('document_id', $document->id)->latest()->get()));
    }

    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/extractions',
        operationId: 'createDmsExtraction',
        description: 'Propose fields from the current version. A format that needs OCR is recorded as failed. An external driver answers 403 until the tenant enables external providers.',
        summary: 'Propose extraction',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Proposal stored', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsExtractionResource')])),
            new OA\Response(response: 403, description: 'External provider not enabled', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(Request $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());

        try {
            $extraction = $this->extraction->propose($document, $request->user());
        } catch (ExternalAiNotAllowedException $exception) {
            return $this->problemDetailsResponse('External AI provider not enabled', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(new ExtractionResource($extraction), 'Extraction stored', Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/dms/extractions/{id}/confirm',
        operationId: 'confirmDmsExtraction',
        description: 'Store the values reviewed by a person in the metadata of the document. Nothing else is created.',
        summary: 'Confirm extraction',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsConfirmExtractionRequest')),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Confirmed', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsExtractionResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already decided or legal hold', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function confirm(ConfirmExtractionRequest $request, string $id): JsonResponse
    {
        $extraction = $this->visibleExtraction($request, $id);

        return $this->successResponse(new ExtractionResource($this->extraction->confirm($extraction, $request->validated('fields'), $request->user())), 'Extraction confirmed');
    }

    #[OA\Post(
        path: '/api/v1/dms/extractions/{id}/reject',
        operationId: 'rejectDmsExtraction',
        description: 'Discard a proposal.',
        summary: 'Reject extraction',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsExtractionResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already decided', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new ExtractionResource($this->extraction->reject($this->visibleExtraction($request, $id), $request->user())), 'Extraction rejected');
    }

    private function visibleExtraction(Request $request, string $id): DmsExtraction
    {
        $extraction = DmsExtraction::query()->findOrFail($id);
        $this->access->findVisible((string) $extraction->document_id, $request->user());

        return $extraction;
    }
}
