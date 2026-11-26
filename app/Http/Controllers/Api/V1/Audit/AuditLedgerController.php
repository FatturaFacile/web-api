<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListArchivedAuditEntriesRequest;
use App\Http\Requests\Audit\SearchAuditEntriesRequest;
use App\Http\Requests\Audit\VerifyAuditIntegrityRunRequest;
use App\Http\Resources\Audit\AuditCheckpointResource;
use App\Http\Resources\Audit\AuditEntryResource;
use App\Http\Resources\Audit\AuditIntegrityRunResource;
use App\Http\Resources\Audit\ResourceHistoryItemResource;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityCommand;
use Enterprise\AuditLedger\Application\Commands\VerifyChainIntegrityHandler;
use Enterprise\AuditLedger\Application\Exceptions\VerificationAlreadyRunning;
use Enterprise\AuditLedger\Application\Queries\GetIntegrityStatusQuery;
use Enterprise\AuditLedger\Application\Queries\GetResourceHistoryQuery;
use Enterprise\AuditLedger\Application\Queries\ListArchivedEntriesQuery;
use Enterprise\AuditLedger\Application\Queries\ListCheckpointsQuery;
use Enterprise\AuditLedger\Application\Queries\SearchAuditEntriesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The probative audit ledger (Mod 005): search, record history, integrity status, on-demand verification and the archive of
 * the restores. The ledger is append only: there is no endpoint that edits or deletes an entry, and any attempt is
 * answered with 405 (see rejectMutation()).
 */
class AuditLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/settings/audit-logs',
        operationId: 'searchAuditLedger',
        description: 'Cursor paginated search of the audit ledger of the active tenant, newest entry first. Restricted to owners, administrators, external auditors and the DPO. The window of from/to is at most 366 days.',
        summary: 'Search the audit ledger',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'actor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resource_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'company_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['web', 'desktop', 'mobile', 'api'])),
            new OA\Parameter(name: 'ip', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of entries: data[] and meta.next_cursor', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The role is not allowed to read the audit ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filter or a window longer than 366 days', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(SearchAuditEntriesRequest $request, SearchAuditEntriesQuery $search): JsonResponse
    {
        $limit = (int) $request->integer('limit', SearchAuditEntriesQuery::DEFAULT_LIMIT);
        $result = $search->handle($this->tenantId(), $request->filters(), $request->input('cursor'), $limit);

        return $this->successResponse(
            data: AuditEntryResource::collection($result['entries'])->resolve($request),
            message: 'Audit entries retrieved',
            meta: ['next_cursor' => $result['next_cursor'], 'limit' => $limit],
        );
    }

    #[OA\Get(
        path: '/api/v1/audit/history/{resourceType}/{resourceId}',
        operationId: 'getAuditResourceHistory',
        description: 'The history of one record (invoice, contact, payment, user): every change recorded for it, newest first. Visible to members who may read that record\'s module.',
        summary: 'History of a record',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        parameters: [
            new OA\Parameter(name: 'resourceType', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resourceId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The history of the record', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The member may not view this record', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function history(Request $request, string $resourceType, string $resourceId, GetResourceHistoryQuery $history): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('audit-ledger.history', [$resourceType])) {
            return $this->problemDetailsResponse('Forbidden', 'You are not allowed to view the history of this record.', Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(
            data: ResourceHistoryItemResource::collection($history->handle($this->tenantId(), $resourceType, $resourceId))->resolve($request),
            message: 'Record history retrieved',
        );
    }

    #[OA\Get(
        path: '/api/v1/settings/audit-logs/integrity',
        operationId: 'getAuditLedgerIntegrity',
        description: 'Latest verification of the audit chain (valid, broken_at, missing_range, never_verified, running), the tail of the chain and the latest checkpoints.',
        summary: 'Integrity status of the audit ledger',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        responses: [
            new OA\Response(response: 200, description: 'Integrity status', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The role is not allowed to read the audit ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function integrity(Request $request, GetIntegrityStatusQuery $status, ListCheckpointsQuery $checkpoints): JsonResponse
    {
        Gate::forUser($request->user())->authorize('audit-ledger.search');

        $tenantId = $this->tenantId();
        $data = $status->handle($tenantId);
        $data['checkpoints'] = AuditCheckpointResource::collection($checkpoints->handle($tenantId)->take(10))->resolve($request);

        return $this->successResponse($data, 'Integrity status retrieved');
    }

    #[OA\Post(
        path: '/api/v1/settings/audit-logs/integrity/verify',
        operationId: 'verifyAuditLedgerIntegrity',
        description: 'Queues the verification of the whole audit chain of the tenant. Answers 409 when a verification is already running.',
        summary: 'Verify the audit chain now',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        responses: [
            new OA\Response(response: 202, description: 'The verification was queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The role is not allowed to verify the audit ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'A verification is already running', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verify(VerifyAuditIntegrityRunRequest $request, VerifyChainIntegrityHandler $handler): JsonResponse
    {
        try {
            $run = $handler->queue(new VerifyChainIntegrityCommand($this->tenantId(), VerifyChainIntegrityCommand::TRIGGER_MANUAL, (string) $request->user()?->getKey()));
        } catch (VerificationAlreadyRunning $e) {
            return $this->problemDetailsResponse('Verification already running', $e->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->successResponse(AuditIntegrityRunResource::make($run->refresh())->resolve($request), 'Integrity verification queued', Response::HTTP_ACCEPTED);
    }

    #[OA\Get(
        path: '/api/v1/settings/audit-logs/archive',
        operationId: 'listAuditLedgerArchive',
        description: 'The audit entries carried by restored backups (the active ledger is never replaced by a restore). Without restore_id it lists the restores; with it, the archived entries and, with verify=1, the verification of their chain.',
        summary: 'Archived audit entries of a restore',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        parameters: [
            new OA\Parameter(name: 'restore_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 500)),
            new OA\Parameter(name: 'verify', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Archived entries or the list of restores', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The role is not allowed to read the audit ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function archive(ListArchivedAuditEntriesRequest $request, ListArchivedEntriesQuery $archive): JsonResponse
    {
        $tenantId = $this->tenantId();
        $restoreId = $request->input('restore_id');

        if (! is_string($restoreId) || $restoreId === '') {
            return $this->successResponse($archive->restores($tenantId)->values()->all(), 'Audit archive restores retrieved');
        }

        $result = $archive->entries($tenantId, $restoreId, $request->input('cursor'), (int) $request->integer('limit', ListArchivedEntriesQuery::DEFAULT_LIMIT), $request->boolean('verify'));

        return $this->successResponse(
            data: AuditEntryResource::collection($result['entries'])->resolve($request),
            message: 'Archived audit entries retrieved',
            meta: ['next_cursor' => $result['next_cursor'], 'restore_id' => $restoreId, 'verification' => $result['verification']],
        );
    }

    /**
     * Every mutation or deletion of a ledger entry is refused: the ledger is append only.
     */
    public function rejectMutation(): JsonResponse
    {
        return $this->problemDetailsResponse('Method Not Allowed', 'Audit ledger entries are immutable and can never be edited or deleted.', Response::HTTP_METHOD_NOT_ALLOWED)
            ->header('Allow', 'GET');
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
