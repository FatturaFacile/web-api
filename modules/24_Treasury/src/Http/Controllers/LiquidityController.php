<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Actions\CalculateWorkingCapitalAction;
use Modules\Treasury\Actions\ExecuteCashConcentrationAction;
use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Http\Requests\IntercompanyTransferRequest;

final class LiquidityController extends Controller
{
    public function __construct(
        private readonly LiquidityManagementServiceInterface $liquidityService,
        private readonly ExecuteCashConcentrationAction $sweepAction,
        private readonly CalculateWorkingCapitalAction $wcAction
    ) {}

    public function workingCapital(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $date = $request->query('metric_date', now()->toDateString());
        $companyId = $request->query('company_id');

        $metric = $this->wcAction->execute($tenantId, $date, $companyId);

        return response()->json($metric);
    }

    public function sweepPool(Request $request, string $poolId): JsonResponse
    {
        $transfers = $this->sweepAction->execute($poolId, (string) $request->user()?->id);

        return response()->json([
            'message' => 'Cash pool concentration sweep executed successfully.',
            'transfers_count' => count($transfers),
            'transfers' => $transfers,
        ]);
    }

    public function intercompanyTransfer(IntercompanyTransferRequest $request): JsonResponse
    {
        $transfer = $this->liquidityService->transferIntercompanyFunds(
            sourceBankAccountId: (string) $request->input('source_bank_account_id'),
            destinationBankAccountId: (string) $request->input('destination_bank_account_id'),
            amount: (float) $request->input('amount'),
            transferDate: (string) $request->input('transfer_date', now()->toDateString()),
            interestRate: (float) $request->input('intercompany_interest_rate', 0.0250),
            cashPoolId: $request->input('cash_pool_id'),
            userId: (string) $request->user()?->id
        );

        return response()->json($transfer, 201);
    }
}
