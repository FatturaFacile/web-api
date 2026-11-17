<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Access\AccessAuditEventResource;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\AccessAudit;
use App\Services\Tenancy\AccessPlanLimits;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The plan limits of the tenant's people and the history of access changes.
 */
class AccessOverviewController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccessPlanLimits $limits,
    ) {}

    #[OA\Get(path: '/api/v1/settings/access/limits', operationId: 'getAccessLimits', description: 'The plan of the active tenant, how many active users and custom roles it has and how many its plan allows (null is unlimited).', summary: 'Plan limits', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 200, description: 'Limits retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function limits(): JsonResponse
    {
        return $this->successResponse(data: $this->limits->describe($this->tenantContext->getTenant()), message: 'Access limits retrieved');
    }

    #[OA\Get(path: '/api/v1/settings/access/audit', operationId: 'getAccessAudit', description: 'Paginated history of access changes of the active tenant (members, roles, teams, delegations, invitations, imports, plan), newest first, from the audit ledger.', summary: 'Access history', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], parameters: [
        new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: AccessAudit::Types)),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 5, maximum: 100)),
        new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ], responses: [
        new OA\Response(response: 200, description: 'History retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function audit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', AccessAudit::Types)],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = AuditLedgerEntry::query()->with('user')
            ->where('tenant_id', $this->tenantContext->getTenantId())
            ->where('module_code', AccessAudit::Module)
            ->orderByDesc('event_timestamp')
            ->orderByDesc('id');

        if (! empty($validated['type'])) {
            $query->where('action', 'like', strtoupper($validated['type']).'.%');
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return $this->successResponse(
            data: AccessAuditEventResource::collection($page->getCollection())->resolve(),
            message: 'Access history retrieved',
            meta: [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        );
    }
}
