<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Analytics\Models\ExportRun;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a stored scheduled export through the signed, expiring link sent by e-mail. The link is the credential:
 * there is no session, so every download is audit logged with the address it came from.
 */
final class ExportDownloadController extends Controller
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly AuditLedgerInterface $auditLedger) {}

    /**
     * Download a stored export.
     */
    #[OA\Get(
        path: '/api/v1/report-downloads/analytics/{run}',
        operationId: 'downloadAnalyticsExport',
        description: 'Public endpoint protected by a signed URL (the `signature` and `expires` query parameters) generated for the scheduled export; no bearer token. Expired or tampered links answer 403, files already removed 404.',
        summary: 'Download a scheduled export',
        tags: ['Analytics BI'],
        parameters: [
            new OA\Parameter(name: 'run', description: 'Identifier of the export run', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'expires', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'signature', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 403, description: 'Invalid or expired link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'The file is no longer available', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $run): StreamedResponse
    {
        $export = ExportRun::query()->withoutGlobalScopes()
            ->whereKey($run)
            ->whereNotNull('path')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $disk = Storage::disk((string) $export->disk);
        abort_unless($disk->exists((string) $export->path), 404);

        $this->auditLedger->recordPayload([
            'action' => 'ANALYTICS_EXPORT_DOWNLOADED',
            'model_class' => ExportRun::class,
            'model_id' => (string) $export->id,
            'tenant_id' => (string) $export->tenant_id,
            'company_id' => (string) $export->tenant_id,
            'payload_before' => null,
            'payload_after' => ['dataset' => $export->dataset, 'schedule_id' => $export->schedule_id, 'via' => 'signed_link', 'sha256' => $export->sha256],
            'module_code' => 'MODULE_20_ANALYTICS',
            'actor_user_id' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $disk->download((string) $export->path, (string) $export->file_name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
