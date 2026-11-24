<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditLogsRequest;
use App\Http\Requests\Audit\SealWormBatchRequest;
use App\Http\Requests\Audit\VerifyAuditIntegrityRequest;
use App\Http\Resources\Audit\AuditLogDetailResource;
use App\Http\Resources\Audit\AuditLogResource;
use App\Http\Resources\Audit\AuditWormBatchResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditWormBatch;
use App\Queries\Audit\VerifyAuditChainHandler;
use App\Queries\Audit\VerifyAuditChainQuery;
use App\Services\Audit\AuditForensicExporter;
use App\Services\Audit\AuditLogReader;
use App\Services\Audit\AuditTrailRecorder;
use App\Services\Audit\AuditWormArchiver;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

/**
 * The immutable audit trail: read, verify, export and WORM archive. There is deliberately no endpoint that edits or
 * deletes a record. Access is gated by the permissions audit.view, audit.verify, audit.export and audit.worm.manage.
 */
class AuditLogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogReader $reader,
    ) {}

    #[OA\Get(
        path: '/api/v1/audit-logs',
        operationId: 'listAuditLogs',
        description: 'Paginated, filterable list of the audit trail of the tenant (newest first). Returns a summary only: which fields changed, never the values. meta.last_verification holds the outcome of the last integrity check.',
        summary: 'List audit trail records',
        security: [['bearerAuth' => []]],
        tags: ['Audit Trail'],
        responses: [
            new OA\Response(response: 200, description: 'A page of audit records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 403, description: 'The permission audit.view is missing'),
        ]
    )]
    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $page = $this->reader->paginate($tenantId, $request->filters(), (int) $request->integer('per_page', 50));

        return $this->successResponse(
            AuditLogResource::collection($page->getCollection())->resolve($request),
            'Audit trail',
            Response::HTTP_OK,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'last_verification' => Cache::get($this->verificationKey($tenantId)),
            ],
        );
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/entity/{type}/{id}',
        operationId: 'getAuditLogEntityTimeline',
        description: 'The complete chronological history of one business record (every created, updated, deleted, restored, viewed and exported event).',
        summary: 'Audit timeline of one record',
        security: [['bearerAuth' => []]],
        tags: ['Audit Trail'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The timeline', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 403, description: 'The permission audit.view is missing'),
        ]
    )]
    public function entity(Request $request, string $type, string $id): JsonResponse
    {
        $timeline = $this->reader->timeline($this->tenantId($request), $type, $id);

        return $this->successResponse(AuditLogResource::collection($timeline)->resolve($request), 'Audit timeline');
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/{id}',
        operationId: 'getAuditLog',
        description: 'One audit record with the decrypted before/after values and its cryptographic proof (sequence number, previous and current hash, HMAC signature, WORM batch). Opening a record is itself written to the trail as AUDIT_LOG_INSPECTED.',
        summary: 'Inspect one audit record',
        security: [['bearerAuth' => []]],
        tags: ['Audit Trail'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The record with its proof', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, description: 'The permission audit.view is missing'),
            new OA\Response(response: 404, description: 'Unknown record'),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $log = $this->reader->find($this->tenantId($request), $id);

        if ($log === null) {
            return $this->problemDetailsResponse('Not Found', 'The audit record does not exist.', Response::HTTP_NOT_FOUND);
        }

        $batch = $this->reader->wormBatchOf($log);
        $values = $this->reader->inspect($log);

        return $this->successResponse((new AuditLogDetailResource($log, $values, $batch))->resolve($request), 'Audit record');
    }

    #[OA\Post(
        path: '/api/v1/audit-logs/verify-integrity',
        operationId: 'verifyAuditIntegrity',
        description: 'Runs the cryptographic verification of the tenant ledger (or of a sequence range): sequence continuity, hash linkage, hash recomputation and HMAC signatures. Returns the signed forensic verification certificate.',
        summary: 'Verify the audit chain',
        security: [['bearerAuth' => []]],
        tags: ['Audit Trail'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'from_sequence', type: 'integer', nullable: true),
            new OA\Property(property: 'to_sequence', type: 'integer', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The verification certificate', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 403, description: 'The permission audit.verify is missing'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function verify(VerifyAuditIntegrityRequest $request, VerifyAuditChainHandler $handler): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $certificate = $handler->handle(new VerifyAuditChainQuery(
            $tenantId,
            $request->filled('from_sequence') ? $request->integer('from_sequence') : null,
            $request->filled('to_sequence') ? $request->integer('to_sequence') : null,
        ))->toArray();

        Cache::forever($this->verificationKey($tenantId), $certificate);

        return $this->successResponse($certificate, $certificate['isValid'] ? 'The audit chain is intact' : 'The audit chain is broken');
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/worm-batches',
        operationId: 'listAuditWormBatches',
        description: 'The WORM archive batches sealed so far: sequence range, Merkle root, storage location and retention lock date.',
        summary: 'List WORM archive batches',
        security: [['bearerAuth' => []]],
        tags: ['Audit Trail'],
        responses: [
            new OA\Response(response: 200, description: 'The batches', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 403, description: 'The permission audit.view is missing'),
        ]
    )]
    public function wormBatches(Request $request): JsonResponse
    {
        $batches = AuditWormBatch::query()->where('tenant_id', $this->tenantId($request))->orderByDesc('from_sequence')->get();

        return $this->successResponse(AuditWormBatchResource::collection($batches)->resolve($request), 'WORM batches');
    }

    #[OA\Post(
        path: '/api/v1/audit-logs/worm-batches/seal',
        operationId: 'sealAuditWormBatches',
        description: 'Seals every audit record not yet archived into signed JSONL batches with a SHA-256 manifest on the WORM storage (Object Lock). Sealing again with nothing new to archive returns no batch.',
        summary: 'Seal pending audit records into the WORM archive',
        security: [['bearerAuth' => []]],
        tags: ['Audit Trail'],
        responses: [
            new OA\Response(response: 200, description: 'The batches sealed by this call', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 403, description: 'The permission audit.worm.manage is missing'),
        ]
    )]
    public function seal(SealWormBatchRequest $request, AuditWormArchiver $archiver): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $sealed = [];

        while (($batch = $archiver->seal($tenantId)) !== null) {
            $sealed[] = $batch;
        }

        return $this->successResponse(
            AuditWormBatchResource::collection(collect($sealed))->resolve($request),
            $sealed === [] ? 'Nothing to seal' : 'Audit records sealed',
        );
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/export',
        operationId: 'exportAuditLogs',
        description: 'Tamper-evident forensic bundle of the filtered audit records: JSONL (payloads stay encrypted) closed by a manifest line with the record count, the SHA-256 of the records and an HMAC signature. The same digest and signature are in the X-Audit-Bundle-SHA256 and X-Audit-Bundle-Signature headers. The export is itself written to the trail.',
        summary: 'Export a forensic audit bundle',
        security: [['bearerAuth' => []]],
        tags: ['Audit Trail'],
        responses: [
            new OA\Response(response: 200, description: 'The JSONL bundle', content: new OA\MediaType(mediaType: 'application/x-ndjson', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 403, description: 'The permission audit.export is missing'),
        ]
    )]
    public function export(ListAuditLogsRequest $request, AuditForensicExporter $exporter, AuditTrailRecorder $recorder): Response
    {
        $tenantId = $this->tenantId($request);
        $bundle = $exporter->bundle($this->reader->filtered($tenantId, $request->filters()));

        $recorder->record(
            event: AuditEventType::Exported,
            entityType: 'AuditLog',
            entityId: null,
            tenantId: $tenantId,
            context: ['format' => 'jsonl', 'records' => $bundle['count'], 'records_sha256' => $bundle['sha256'], 'filters' => $request->filters()],
        );

        return response($bundle['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/x-ndjson',
            'Content-Disposition' => 'attachment; filename="'.$bundle['filename'].'"',
            'X-Audit-Bundle-SHA256' => $bundle['sha256'],
            'X-Audit-Bundle-Signature' => $bundle['signature'],
            'Access-Control-Expose-Headers' => 'X-Audit-Bundle-SHA256, X-Audit-Bundle-Signature, Content-Disposition',
        ]);
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }

    private function verificationKey(string $tenantId): string
    {
        return 'audit:last_verification:'.$tenantId;
    }
}
