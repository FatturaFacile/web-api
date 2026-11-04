<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;

final class FinancialStatementController extends Controller
{
    public function __construct(
        private readonly FinancialStatementServiceInterface $statementService,
        private readonly TenantContext $tenantContext
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $fiscalPeriodId = (string) $request->query('fiscal_period_id');

        $data = $this->statementService->generateTrialBalance($tenantId, $fiscalPeriodId);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $fiscalPeriodId = (string) $request->query('fiscal_period_id');

        $data = $this->statementService->generateBalanceSheet($tenantId, $fiscalPeriodId);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $fiscalPeriodId = (string) $request->query('fiscal_period_id');

        $data = $this->statementService->generateIncomeStatement($tenantId, $fiscalPeriodId);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $fiscalPeriodId = (string) $request->query('fiscal_period_id');

        $data = $this->statementService->generateCashFlowPreparation($tenantId, $fiscalPeriodId);

        return response()->json([
            'data' => $data,
        ]);
    }
}
