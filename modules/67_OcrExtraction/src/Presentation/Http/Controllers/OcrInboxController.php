<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\OcrExtraction\Application\Commands\ConfirmAndConvertExtractedDocumentCommand;
use Modules\OcrExtraction\Application\Commands\EnqueueDocumentOcrExtractionCommand;
use Modules\OcrExtraction\Application\Handlers\ConfirmAndConvertExtractedDocumentHandler;
use Modules\OcrExtraction\Application\Handlers\EnqueueDocumentOcrExtractionHandler;
use Modules\OcrExtraction\Application\Handlers\GetDocumentExtractionReviewWorkbenchHandler;
use Modules\OcrExtraction\Application\Handlers\ManageExtractionJobHandler;
use Modules\OcrExtraction\Application\Queries\GetDocumentExtractionReviewWorkbenchQuery;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Enums\SourceChannel;
use Modules\OcrExtraction\Presentation\Http\Requests\EnqueueExtractionRequest;
use Modules\OcrExtraction\Presentation\Http\Requests\InboxQueryRequest;
use Modules\OcrExtraction\Presentation\Http\Requests\RejectExtractionRequest;
use Modules\OcrExtraction\Presentation\Http\Requests\RerunExtractionRequest;
use Modules\OcrExtraction\Presentation\Http\Requests\ReviewedDocumentRequest;
use Modules\OcrExtraction\Presentation\Http\Resources\ExtractionJobResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class OcrInboxController extends OcrApiController
{
    #[OA\Get(path: '/api/v1/ocr-inbox', operationId: 'listOcrInbox', description: 'The AI Inbox queue (queued, processing, ready for review, failed) with counters per status, and with job_id the full review workbench of that job: signed source URL, payload, bounding boxes, vendor match, coding lookups and three-way match candidates.', summary: 'AI Inbox queue and review workbench', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], responses: [new OA\Response(response: 200, description: 'The queue', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(InboxQueryRequest $request, GetDocumentExtractionReviewWorkbenchHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetDocumentExtractionReviewWorkbenchQuery(
            $this->tenantId(),
            $data['job_id'] ?? null,
            isset($data['status']) ? ExtractionJobStatus::from($data['status']) : null,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 25),
        ))));
    }

    #[OA\Get(path: '/api/v1/ocr-inbox/{id}', operationId: 'showOcrInboxJob', description: 'The review workbench of one extraction job.', summary: 'Review workbench of a job', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The workbench', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Unknown job')])]
    public function show(string $id, GetDocumentExtractionReviewWorkbenchHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($handler->handle(new GetDocumentExtractionReviewWorkbenchQuery($this->tenantId(), $id))['selected']));
    }

    #[OA\Post(path: '/api/v1/ocr-inbox/documents', operationId: 'enqueueOcrInboxDocument', description: 'Queues a stored DMS document for extraction on the ai-ocr queue.', summary: 'Queue a DMS document for extraction', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], responses: [new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function enqueue(EnqueueExtractionRequest $request, EnqueueDocumentOcrExtractionHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            new ExtractionJobResource($handler->handle(new EnqueueDocumentOcrExtractionCommand(
                $this->tenantId(),
                $data['dms_document_id'],
                SourceChannel::DmsUpload,
                isset($data['document_category']) ? DocumentCategory::from($data['document_category']) : null,
                $this->userId(),
            ))),
            'Document queued for extraction',
            Response::HTTP_ACCEPTED,
        ));
    }

    #[OA\Put(path: '/api/v1/ocr-inbox/{id}/draft', operationId: 'saveOcrInboxDraft', description: 'Stores the accountant edits of a document without converting it.', summary: 'Save a review draft', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Draft saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function saveDraft(string $id, ReviewedDocumentRequest $request, ManageExtractionJobHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new ExtractionJobResource($handler->saveDraft($this->tenantId(), $id, $request->validated())), 'Draft saved'));
    }

    #[OA\Post(path: '/api/v1/ocr-inbox/{id}/confirm', operationId: 'confirmOcrInboxJob', description: 'Validates the reviewed document (fiscal period, VAT jurisdiction, totals, three-way match tolerance), converts it into the vendor invoice, expense, goods receipt or vendor quote, teaches the vendor coding model and archives the source file, atomically.', summary: 'Confirm and convert a reviewed document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Converted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 422, description: 'A business rule failed')])]
    public function confirm(string $id, ReviewedDocumentRequest $request, ConfirmAndConvertExtractedDocumentHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(
            new ExtractionJobResource($handler->handle(new ConfirmAndConvertExtractedDocumentCommand($id, $this->tenantId(), $this->userId(), $request->validated()))),
            'Document confirmed',
        ));
    }

    #[OA\Post(path: '/api/v1/ocr-inbox/{id}/reject', operationId: 'rejectOcrInboxJob', description: 'Marks the document invalid; nothing is created.', summary: 'Reject a document as invalid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function reject(string $id, RejectExtractionRequest $request, ManageExtractionJobHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new ExtractionJobResource($handler->reject($this->tenantId(), $id, $this->userId(), (string) $request->validated('reason'))), 'Document rejected'));
    }

    #[OA\Post(path: '/api/v1/ocr-inbox/{id}/rerun', operationId: 'rerunOcrInboxJob', description: 'Queues the extraction again with the current OCR engine of the tenant.', summary: 'Re-run the extraction', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function rerun(string $id, RerunExtractionRequest $request, ManageExtractionJobHandler $handler): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new ExtractionJobResource($handler->rerun($this->tenantId(), $id, $this->userId())), 'Extraction queued again', Response::HTTP_ACCEPTED));
    }
}
