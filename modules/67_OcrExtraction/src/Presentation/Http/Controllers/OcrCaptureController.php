<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\SourceChannel;
use Modules\OcrExtraction\Infrastructure\Services\InboxIntakeService;
use Modules\OcrExtraction\Presentation\Http\Requests\CaptureDocumentRequest;
use Modules\OcrExtraction\Presentation\Http\Resources\ExtractionJobResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint of the Flutter app (Mod 075): receipt and delivery note photos, already deskewed on the device, with the
 * metadata of the capture. Retries of the offline queue are idempotent through `client_reference`.
 */
final class OcrCaptureController extends OcrApiController
{
    #[OA\Post(path: '/api/v1/ocr-inbox/capture', operationId: 'captureOcrInboxDocument', description: 'Accepts one file or the ordered pages of a receipt or delivery note, stores them in the AI Inbox folder of the DMS and queues the extraction. Repeating a client_reference returns the first job.', summary: 'Mobile camera capture', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['OCR Inbox'], responses: [new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function capture(CaptureDocumentRequest $request, InboxIntakeService $intake): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $category = isset($data['document_category']) ? DocumentCategory::from($data['document_category']) : null;

        return $this->guarded(function () use ($request, $intake, $data, $user, $category): JsonResponse {
            /** @var list<UploadedFile>|null $pages */
            $pages = $request->file('pages');
            $file = $pages !== null
                ? $intake->pdfFromPages($pages, 'capture-'.now()->format('Ymd-His').'.pdf')
                : $request->file('file');

            $job = $intake->intake($this->tenantId(), $file, SourceChannel::MobileCamera, $category, $user instanceof User ? $user : null, $data['client_reference'] ?? null);

            return $this->successResponse(new ExtractionJobResource($job), 'Document captured', Response::HTTP_ACCEPTED);
        });
    }
}
