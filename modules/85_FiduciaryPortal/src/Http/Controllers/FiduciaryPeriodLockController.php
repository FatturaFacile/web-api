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
use Modules\FiduciaryPortal\Http\Requests\FiduciaryPeriodLockRequest;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;
use Modules\FiduciaryPortal\Services\FiduciaryPeriodLockService;
use Modules\GeneralLedger\Http\Resources\FiscalPeriodResource;
use OpenApi\Attributes as OA;

final class FiduciaryPeriodLockController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiduciaryPeriodLockService $periodLocks,
    ) {}

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/periods',
        operationId: 'fiduciaryPortalPeriodsIndex',
        summary: 'List fiscal periods with fiduciary lock status and verification counts',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Fiscal periods list')]
    )]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        return $this->successResponse($this->periodLocks->listPeriods(
            tenantId: $tenantId,
            fiscalYearId: $request->query('fiscal_year_id') ? (string) $request->query('fiscal_year_id') : null,
        ));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/periods/{id}/lock',
        operationId: 'fiduciaryPortalPeriodsLock',
        summary: 'Lock a fiscal period by external fiduciary (Chiusura Periodo / Blocco Fiduciario)',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Period locked by fiduciary')]
    )]
    public function lock(FiduciaryPeriodLockRequest $request, string $id): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_LOCK_PERIOD);

        /** @var User $actor */
        $actor = $request->user();
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $actor->tenant_id);

        $period = $this->periodLocks->lockPeriod($tenantId, $id, (string) $request->validated('reason'), $actor);

        return $this->successResponse(new FiscalPeriodResource($period), __('fiduciary_portal.period_locked'));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/periods/{id}/unlock',
        operationId: 'fiduciaryPortalPeriodsUnlock',
        summary: 'Unlock a fiduciary-locked fiscal period',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Period unlocked')]
    )]
    public function unlock(FiduciaryPeriodLockRequest $request, string $id): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_LOCK_PERIOD);

        /** @var User $actor */
        $actor = $request->user();
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $actor->tenant_id);

        $period = $this->periodLocks->unlockPeriod($tenantId, $id, (string) $request->validated('reason'), $actor);

        return $this->successResponse(new FiscalPeriodResource($period), __('fiduciary_portal.period_unlocked'));
    }
}
