<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Contracts\FinancialStatementServiceInterface;
use Modules\GeneralLedger\Services\SwissAnhangService;
use OpenApi\Attributes as OA;

final class FinancialStatementController extends Controller
{
    public function __construct(
        private readonly FinancialStatementServiceInterface $statementService,
        private readonly SwissAnhangService $swissAnhangService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Generates the trial balance of the fiscal period.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/trial-balance',
        operationId: 'getTrialBalanceGeneralLedgerFinancialStatement',
        description: 'Debit and credit per account of the posted entries of the fiscal period (reversed entries and their reversals both count, so they cancel out). Amounts are decimal strings since April 2027. Every amount is in the accounting (functional) currency of the tenant, stated in `currency`. For a date range, opening balances, comparison and dimension filters use GET /api/v1/general-ledger/reports/trial-balance.',
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
        description: 'Balance sheet at the end of the fiscal period from every posted entry up to that day, classified by account type only (no account-code guessing); the result not yet closed into equity is added to equity. Amounts are decimal strings since April 2027. Every amount is in the accounting (functional) currency of the tenant, stated in `currency`. With comparison dates use GET /api/v1/general-ledger/reports/balance-sheet.',
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
        $presentationCurrency = $request->query('presentation_currency');

        $data = $this->statementService->generateBalanceSheet(
            $tenantId,
            $fiscalPeriodId,
            is_string($presentationCurrency) ? $presentationCurrency : null
        );

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
        description: 'Income statement of the fiscal period from posted entries, classified by account type only (revenue, cogs, expense), without the year-end closing entry. Amounts are decimal strings since April 2027. Every amount is in the accounting (functional) currency of the tenant, stated in `currency`. With comparison ranges use GET /api/v1/general-ledger/reports/income-statement.',
        summary: 'Get income statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: false, description: 'Filter by fiscal period', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'presentation_currency', in: 'query', required: false, description: 'Optional presentation currency (e.g. CHF per CO Art. 958d para. 3)', schema: new OA\Schema(type: 'string')),
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
        $presentationCurrency = $request->query('presentation_currency');

        $data = $this->statementService->generateIncomeStatement(
            $tenantId,
            $fiscalPeriodId,
            is_string($presentationCurrency) ? $presentationCurrency : null
        );

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
        description: 'Deprecated: use GET /api/v1/general-ledger/financial-statements/cash-flow-statement. Indirect operating cash flow of the fiscal period (net income corrected by the change of customer and supplier control accounts), as decimal strings; investing, financing and net cash flow are not derived here and are null.',
        deprecated: true,
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

    /**
     * Retrieves the Swiss statutory notes (Anhang, CO Art. 959c) for a fiscal year.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/financial-statements/swiss-anhang/{fiscalYearId}',
        operationId: 'getSwissAnhangGeneralLedgerFinancialStatement',
        description: 'Returns the Swiss statutory notes to the financial statements (Anhang, CO Art. 959c) for the given fiscal year, including auto-derived BVG/pension liabilities and general ledger figures.',
        summary: 'Get Swiss statutory notes (Anhang)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'fiscalYearId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Swiss Anhang disclosures and ledger figures', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Fiscal year not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function swissAnhang(Request $request, string $fiscalYearId): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return response()->json([
            'success' => true,
            'data' => $this->swissAnhangService->getForFiscalYear((string) $tenantId, $fiscalYearId),
        ]);
    }

    /**
     * Updates the Swiss statutory notes (Anhang, CO Art. 959c) for a fiscal year.
     */
    #[OA\Put(
        path: '/api/v1/general-ledger/financial-statements/swiss-anhang/{fiscalYearId}',
        operationId: 'updateSwissAnhangGeneralLedgerFinancialStatement',
        description: 'Creates or updates the Swiss statutory notes to the financial statements (Anhang, CO Art. 959c) for the given fiscal year.',
        summary: 'Update Swiss statutory notes (Anhang)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Financial Statements'],
        parameters: [
            new OA\Parameter(name: 'fiscalYearId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated Swiss Anhang disclosures and ledger figures', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateSwissAnhang(Request $request, string $fiscalYearId): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $validated = $request->validate([
            'fte_band' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', SwissAnhangService::ALLOWED_FTE_BANDS)],
            'valuation_principles' => ['sometimes', 'nullable'],
            'pledged_assets_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'pledged_assets_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'leasing_commitments_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'leasing_commitments_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'pension_fund_liabilities_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'pension_fund_liabilities_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fire_insurance_values' => ['sometimes', 'nullable', 'array'],
            'subsequent_events' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'auditor_fees_audit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'auditor_fees_other' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'extra_disclosures' => ['sometimes', 'nullable', 'array'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->swissAnhangService->upsertForFiscalYear(
                (string) $tenantId,
                $fiscalYearId,
                $validated,
                $request->user()?->id !== null ? (string) $request->user()->id : null
            ),
        ]);
    }
}
