<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ExportAuditRequest;
use App\Http\Resources\Audit\AuditExportResource;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Application\Commands\AppendAuditEntryCommand;
use Enterprise\AuditLedger\Application\Commands\ExportAuditCommand;
use Enterprise\AuditLedger\Application\Commands\ExportAuditHandler;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Domain\Enums\AuditCategory;
use Enterprise\AuditLedger\Domain\ValueObjects\AuditAction;
use Enterprise\AuditLedger\Models\AuditExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asynchronous, signed export packages of the audit ledger (CSV + JSON + PDF summary + manifest).
 */
class AuditLedgerExportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Post(
        path: '/api/v1/settings/audit-logs/exports',
        operationId: 'createAuditLedgerExport',
        description: 'Queues a signed export package of the audit ledger for a window of at most 366 days. The Idempotency-Key header makes a retried request return the first export.',
        summary: 'Request an audit ledger export',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['from', 'to'], properties: [
            new OA\Property(property: 'from', type: 'string', format: 'date'),
            new OA\Property(property: 'to', type: 'string', format: 'date'),
            new OA\Property(property: 'actor', type: 'string'),
            new OA\Property(property: 'action', type: 'string'),
            new OA\Property(property: 'resource_type', type: 'string'),
            new OA\Property(property: 'company_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'channel', type: 'string'),
            new OA\Property(property: 'category', type: 'string'),
            new OA\Property(property: 'ip', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 202, description: 'The export was queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The role is not allowed to export the audit ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid window (more than 366 days) or filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(ExportAuditRequest $request, ExportAuditHandler $handler): JsonResponse
    {
        $result = $handler->handle(new ExportAuditCommand(
            tenantId: $this->tenantId(),
            requestedBy: (string) $request->user()?->getKey(),
            from: (string) $request->validated('from'),
            to: (string) $request->validated('to'),
            filters: $request->filters(),
            idempotencyKey: $request->idempotencyKey(),
        ));

        return $this->successResponse(
            data: AuditExportResource::make($result['export']->refresh())->resolve($request),
            message: 'Audit export queued',
            statusCode: Response::HTTP_ACCEPTED,
            meta: ['idempotent_replay' => ! $result['created']],
        );
    }

    #[OA\Get(
        path: '/api/v1/settings/audit-logs/exports/{id}',
        operationId: 'getAuditLedgerExport',
        description: 'The status of an export package: queued, running, completed (with hashes, signature and download link) or failed.',
        summary: 'Status of an audit ledger export',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The export', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'The role is not allowed to export the audit ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No such export for this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        Gate::forUser($request->user())->authorize('audit-ledger.export');

        $export = $this->find($id);

        if (! $export instanceof AuditExport) {
            return $this->problemDetailsResponse('Not Found', 'The export does not exist.', Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(AuditExportResource::make($export)->resolve($request), 'Audit export retrieved');
    }

    #[OA\Get(
        path: '/api/v1/settings/audit-logs/exports/{id}/download',
        operationId: 'downloadAuditLedgerExport',
        description: 'Downloads the zip package of a completed export. The SHA-256 and the signature of the package are returned in the X-Audit-Package-SHA256 and X-Audit-Package-Signature headers.',
        summary: 'Download an audit ledger export',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Audit Ledger'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The zip package'),
            new OA\Response(response: 403, description: 'The role is not allowed to export the audit ledger', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No completed export with this id', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function download(Request $request, string $id, AuditLedgerInterface $ledger): StreamedResponse|JsonResponse
    {
        Gate::forUser($request->user())->authorize('audit-ledger.export');

        $export = $this->find($id);

        if (! $export instanceof AuditExport || $export->status !== AuditExport::STATUS_COMPLETED || $export->path === null) {
            return $this->problemDetailsResponse('Not Found', 'The export is not available.', Response::HTTP_NOT_FOUND);
        }

        $disk = Storage::disk((string) $export->disk);

        if (! $disk->exists($export->path)) {
            return $this->problemDetailsResponse('Not Found', 'The export package is no longer stored.', Response::HTTP_NOT_FOUND);
        }

        $ledger->append(new AppendAuditEntryCommand(
            tenantId: $this->tenantId(),
            companyId: $this->tenantId(),
            moduleCode: 'AUDIT',
            action: AuditAction::make('AUDIT_EXPORT_DOWNLOADED'),
            resourceType: 'AuditExport',
            resourceId: (string) $export->id,
            after: ['sha256' => $export->sha256],
            actorUserId: (string) $request->user()?->getKey(),
            category: AuditCategory::Access,
        ));

        return $disk->download($export->path, 'audit-ledger-'.$export->from_date->toDateString().'-'.$export->to_date->toDateString().'.zip', [
            'X-Audit-Package-SHA256' => (string) $export->sha256,
            'X-Audit-Package-Signature' => (string) $export->signature,
        ]);
    }

    private function find(string $id): ?AuditExport
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return null;
        }

        return AuditExport::query()->where('tenant_id', $this->tenantId())->where('id', $id)->first();
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
