<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\StoreOfflineAuditBatchRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Audit\OfflineAuditIngestService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * Receives the offline audit queue of the desktop and mobile clients.
 */
final class OfflineAuditBatchController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Post(
        path: '/api/v1/audit/offline-batches',
        operationId: 'storeOfflineAuditBatch',
        description: 'Uploads entries of a device local hash-chained audit queue. Idempotent per device and sequence number; a gap, a broken previous_hash link or a different hash for a known sequence is rejected with 422. Every accepted entry is also appended to the server audit chain.',
        summary: 'Upload an offline audit batch',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['device_id', 'origin', 'entries'], properties: [
            new OA\Property(property: 'device_id', type: 'string'),
            new OA\Property(property: 'batch_id', type: 'string', nullable: true),
            new OA\Property(property: 'origin', type: 'string', example: 'desktop_offline'),
            new OA\Property(property: 'entries', type: 'array', items: new OA\Items(type: 'object')),
        ])),
        tags: ['Audit Trail'],
        responses: [
            new OA\Response(response: 200, description: 'Acknowledgement: data.batch_id, data.received_at and data.entries[] with sequence_number, id and server_received_at', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Validation error, chain gap, broken link or hash conflict'),
        ]
    )]
    public function store(StoreOfflineAuditBatchRequest $request, OfflineAuditIngestService $ingest): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        /** @var list<array<string, mixed>> $entries */
        $entries = $request->validated('entries');
        $batchId = $request->validated('batch_id') ?? $request->header('Idempotency-Key');
        $acks = $ingest->ingest((string) $tenantId, (string) $request->validated('device_id'), (string) $request->validated('origin'), is_string($batchId) ? $batchId : null, $entries);

        return $this->successResponse([
            'batch_id' => is_string($batchId) ? $batchId : null,
            'received_at' => Carbon::now('UTC')->toIso8601String(),
            'entries' => $acks,
        ]);
    }
}
