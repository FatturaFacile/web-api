<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;
use OpenApi\Attributes as OA;

final class FinancialStatementController extends Controller
{
    public function __construct(
        private readonly FinancialStatementServiceInterface $statementService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Generates the trial balance of the fiscal period.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/trial-balance',
        operationId: 'getTrialBalanceGeneralLedgerFinancialStatement',
        description: 'Generates the trial balance of the fiscal period.',
        summary: 'Get trial balance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: false, description: 'Filter by fiscal period', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Generates the balance sheet of the fiscal period.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/balance-sheet',
        operationId: 'getBalanceSheetGeneralLedgerFinancialStatement',
        description: 'Generates the balance sheet of the fiscal period.',
        summary: 'Get balance sheet',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: false, description: 'Filter by fiscal period', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Generates the income statement of the fiscal period.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/income-statement',
        operationId: 'getIncomeStatementGeneralLedgerFinancialStatement',
        description: 'Generates the income statement of the fiscal period.',
        summary: 'Get income statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: false, description: 'Filter by fiscal period', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Generates the data used to prepare the cash flow statement of the fiscal period.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/cash-flow',
        operationId: 'getCashFlowGeneralLedgerFinancialStatement',
        description: 'Generates the data used to prepare the cash flow statement of the fiscal period.',
        summary: 'Get cash flow preparation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: false, description: 'Filter by fiscal period', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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
