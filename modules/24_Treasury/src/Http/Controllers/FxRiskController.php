<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Actions\PerformFxRevaluationAction;
use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;

final class FxRiskController extends Controller
{
    public function __construct(
        private readonly ForeignExchangeRiskServiceInterface $fxService,
        private readonly PerformFxRevaluationAction $revaluationAction
    ) {}

    public function exposure(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $date = $request->query('as_of_date', now()->toDateString());
        $companyId = $request->query('company_id');

        $positions = $this->fxService->computeCurrencyExposure($tenantId, $date, $companyId);

        return response()->json($positions);
    }

    public function revalue(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'period_end_date' => ['nullable', 'date'],
            'spot_rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $tenantId = $tenantContext->getTenantId();
        $currency = strtoupper((string) $request->input('currency'));
        $date = (string) $request->input('period_end_date', now()->toDateString());
        $spotRate = (float) $request->input('spot_rate');
        $companyId = $request->input('company_id');

        $revaluation = $this->revaluationAction->execute(
            $tenantId,
            $currency,
            $date,
            $spotRate,
            $companyId,
            (string) $request->user()?->id
        );

        return response()->json($revaluation, 201);
    }
}
