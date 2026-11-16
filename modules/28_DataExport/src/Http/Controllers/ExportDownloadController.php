<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\DataExport\Models\ExportJob;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a stored export through its signed, expiring link: the signature is the credential, so there is no token.
 */
final class ExportDownloadController extends Controller
{
    /**
     * Download a stored export.
     */
    #[OA\Get(
        path: '/api/v1/export-downloads/{job}',
        operationId: 'downloadExportJob',
        description: 'Public endpoint protected by a signed URL (the `signature` and `expires` query parameters) from the link endpoint; no bearer token. Expired or tampered links answer 403, removed files 404.',
        summary: 'Download a stored export',
        tags: ['Data Export'],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'expires', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 403, description: 'Invalid or expired link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'The file is no longer available', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function __invoke(string $job): StreamedResponse
    {
        $export = ExportJob::query()->withoutGlobalScopes()->whereKey($job)->whereNull('deleted_at')->firstOrFail();

        abort_unless($export->isDownloadable() && Storage::disk((string) $export->disk)->exists((string) $export->path), 404);

        return Storage::disk((string) $export->disk)->download((string) $export->path, (string) $export->file_name, ['Content-Type' => (string) $export->mime_type]);
    }
}
