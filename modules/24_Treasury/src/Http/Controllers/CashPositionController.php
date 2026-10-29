<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;

final class CashPositionController extends Controller
{
    public function __construct(
        private readonly TreasuryPositionServiceInterface $positionService
    ) {}

    public function intraday(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $companyId = $request->query('company_id');
        $currency = $request->query('currency');

        $position = $this->positionService->getIntradayPosition($tenantId, $companyId, $currency);

        return response()->json($position);
    }

    public function liquidityBuffer(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $companyId = $request->query('company_id');

        $buffer = $this->positionService->calculateLiquidityBuffer($tenantId, $companyId);

        return response()->json($buffer);
    }
}
