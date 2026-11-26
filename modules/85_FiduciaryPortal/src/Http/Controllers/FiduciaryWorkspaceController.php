<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FiduciaryPortal\Http\Resources\FiduciaryAuditLogResource;
use Modules\FiduciaryPortal\Models\FiduciaryAuditLog;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;
use Modules\FiduciaryPortal\Services\FiduciaryMultiTenantOverviewService;
use OpenApi\Attributes as OA;

final class FiduciaryWorkspaceController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiduciaryMultiTenantOverviewService $overviewService,
    ) {}

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/overview',
        operationId: 'fiduciaryPortalOverview',
        summary: 'Get multi-tenant fiduciary workspace overview, client list, periods, and studio export formats',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Fiduciary portal overview')]
    )]
    public function overview(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);

        /** @var User $user */
        $user = $request->user();
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $user->tenant_id);

        return $this->successResponse($this->overviewService->overview($user, $tenantId));
    }

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/audit-logs',
        operationId: 'fiduciaryPortalAuditLogs',
        summary: 'List fiduciary audit logs for the active tenant',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Fiduciary audit logs')]
    )]
    public function auditLogs(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);

        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);
        $perPage = max(1, min(100, (int) $request->query('per_page', 30)));

        $logs = FiduciaryAuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', (string) $action))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return $this->successResponse([
            'items' => FiduciaryAuditLogResource::collection($logs->getCollection()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
