<?php

declare(strict_types=1);

namespace Modules\Copilot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Copilot\Commands\IndexTenantKnowledgeRagCommand;
use Modules\Copilot\Http\Requests\IndexCopilotKnowledgeRequest;
use Modules\Copilot\Http\Requests\ListCopilotAuditLogRequest;
use Modules\Copilot\Http\Resources\CopilotAuditLogResource;
use Modules\Copilot\Queries\GetCopilotAuditLogQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * What only owners and administrators do with the Copilot: read its audit trail and feed its knowledge base.
 */
final class CopilotAdminController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(
        path: '/api/v1/copilot/audit-logs',
        operationId: 'listCopilotAuditLogs',
        description: 'The Copilot entries of the immutable audit ledger of this company, latest first: prompts (without their text), proposed, confirmed, rejected, executed and failed actions. Owners and administrators only.',
        summary: 'List the Copilot audit trail',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Copilot'],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['prompt', 'action_proposed', 'action_confirmed', 'action_executed', 'action_failed', 'action_rejected'])),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit entries', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CopilotAuditLogResource'))])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function auditLogs(ListCopilotAuditLogRequest $request, GetCopilotAuditLogQuery $query): JsonResponse
    {
        $this->assertManager($request);

        $entries = $query->execute((string) $this->tenantContext->getTenantId(), $request->safe()->only(['action', 'user_id', 'from', 'to']), (int) $request->validated('per_page', 25));

        return $this->successResponse(
            CopilotAuditLogResource::collection($entries->getCollection()),
            'Copilot audit trail',
            meta: ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'per_page' => $entries->perPage(), 'total' => $entries->total()],
        );
    }

    #[OA\Post(
        path: '/api/v1/copilot/knowledge',
        operationId: 'indexCopilotKnowledge',
        description: 'Indexes documents into the private knowledge base of this company, where the Copilot retrieves and cites them next to the global regulatory passages. Indexing a document again (same title, section and language) updates it. Owners and administrators only.',
        summary: 'Index company knowledge for the Copilot',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/IndexCopilotKnowledgeRequest')),
        tags: ['Copilot'],
        responses: [
            new OA\Response(response: 201, description: 'Number of passages written', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'chunks', type: 'integer')])])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function indexKnowledge(IndexCopilotKnowledgeRequest $request, IndexTenantKnowledgeRagCommand $command): JsonResponse
    {
        $this->assertManager($request);

        $chunks = $command->execute((string) $this->tenantContext->getTenantId(), array_values($request->validated('documents')));

        return $this->successResponse(['chunks' => $chunks], 'Knowledge indexed', Response::HTTP_CREATED);
    }

    private function assertManager(Request $request): void
    {
        $role = $request->user()?->getRoleInTenant((string) $this->tenantContext->getTenantId());

        if (! in_array($role, ['owner', 'admin'], true)) {
            throw new AccessDeniedHttpException('Only owners and administrators can do this.');
        }
    }
}
