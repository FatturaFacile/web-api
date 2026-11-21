<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the source file of a job to the workbench viewer through a temporary signed URL (a PDF.js canvas or an image tag
 * cannot send the bearer token). The signature expires and names one job; it grants nothing else.
 */
final class OcrSourceController extends Controller
{
    #[OA\Get(path: '/api/v1/ocr-inbox-downloads/{job}', operationId: 'downloadOcrInboxSource', description: 'The original PDF or image of a job, through a temporary signed URL taken from the workbench.', summary: 'Source file of a job', tags: ['OCR Inbox'], parameters: [new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 403, description: 'Expired or invalid signature')])]
    public function __invoke(string $job): StreamedResponse
    {
        $extraction = DocumentExtractionJob::withoutGlobalScopes()->find($job);

        if ($extraction === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $version = DmsDocumentVersion::withoutGlobalScopes()
            ->where('tenant_id', $extraction->tenant_id)
            ->where('document_id', $extraction->dms_document_id)
            ->orderByDesc('version_number')
            ->first();

        if ($version === null || ! Storage::disk($version->disk)->exists($version->path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return Storage::disk($version->disk)->response($version->path, $version->original_name, [
            'Content-Type' => $version->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
