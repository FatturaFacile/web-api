<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\DigitalPreservation\Application\Handlers\GetPreservationAuditLogHandler;
use Modules\DigitalPreservation\Application\Queries\GetPreservationAuditLogQuery;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;
use Modules\DigitalPreservation\Presentation\Http\Resources\PreservationAuditLogResource;
use OpenApi\Attributes as OA;

final class PreservationAuditLogController extends PreservationApiController
{
    #[OA\Get(path: '/api/v1/preservation/audit-logs', operationId: 'listPreservationAuditLogs', description: 'Paginated immutable audit trail with the verification of its hash chain in `meta.chain_valid`.', summary: 'List the preservation audit trail', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], parameters: [
        new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'batch_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [new OA\Response(response: 200, description: 'The entries', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function index(Request $request, GetPreservationAuditLogHandler $handler, PreservationAuditLogger $audit): JsonResponse
    {
        $page = $handler->handle(new GetPreservationAuditLogQuery(
            $request->query('action') ? (string) $request->query('action') : null,
            $request->query('batch_id') ? (string) $request->query('batch_id') : null,
            (int) $request->query('per_page', 50),
        ));

        return $this->page($page, PreservationAuditLogResource::class, ['chain_valid' => $audit->verifyChain()['ok']]);
    }
}
