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
use OpenApi\Attributes as OA;

final class LiquidityController extends Controller
{
    public function __construct(
        private readonly LiquidityManagementServiceInterface $liquidityService,
        private readonly ExecuteCashConcentrationAction $sweepAction,
        private readonly CalculateWorkingCapitalAction $wcAction
    ) {}

    /**
     * Calculates the working capital metrics of the active tenant at the given date.
     */
    #[OA\Get(
        path: '/api/v1/treasury/liquidity/working-capital',
        operationId: 'getWorkingCapitalTreasuryLiquidity',
        description: 'Calculates the working capital metrics of the active tenant at the given date.',
        summary: 'Get working capital',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        parameters: [
            new OA\Parameter(name: 'metric_date', in: 'query', required: false, description: 'Filter by metric date', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'company_id', in: 'query', required: false, description: 'Filter by company', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryWorkingCapitalMetricModel')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function workingCapital(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $date = $request->query('metric_date', now()->toDateString());
        $companyId = $request->query('company_id');

        $metric = $this->wcAction->execute($tenantId, $date, $companyId);

        return response()->json($metric);
    }

    /**
     * Executes the cash concentration sweep of a liquidity pool and returns the resulting transfers.
     */
    #[OA\Post(
        path: '/api/v1/treasury/liquidity/pools/{poolId}/sweep',
        operationId: 'sweepPoolTreasuryLiquidityPool',
        description: 'Executes the cash concentration sweep of a liquidity pool and returns the resulting transfers.',
        summary: 'Sweep cash pool',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        parameters: [
            new OA\Parameter(name: 'poolId', in: 'path', required: true, description: 'Identifier of the pool', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'transfers_count', type: 'integer'), new OA\Property(property: 'transfers', type: 'array', items: new OA\Items)])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function sweepPool(Request $request, string $poolId): JsonResponse
    {
        $transfers = $this->sweepAction->execute($poolId, (string) $request->user()?->id);

        return response()->json([
            'message' => 'Cash pool concentration sweep executed successfully.',
            'transfers_count' => count($transfers),
            'transfers' => $transfers,
        ]);
    }

    /**
     * Transfers funds between two bank accounts of the group.
     */
    #[OA\Post(
        path: '/api/v1/treasury/liquidity/intercompany-transfer',
        operationId: 'intercompanyTransferTreasuryLiquidity',
        description: 'Transfers funds between two bank accounts of the group.',
        summary: 'Create intercompany transfer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryIntercompanyTransferRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Intercompany transfer created', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryIntercompanyFundingModel')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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
