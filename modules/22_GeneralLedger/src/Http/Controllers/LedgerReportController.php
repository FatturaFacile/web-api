<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Http\Requests\AccountLedgerReportRequest;
use Modules\GeneralLedger\Http\Requests\AuditTrailExportRequest;
use Modules\GeneralLedger\Http\Requests\BalanceSheetReportRequest;
use Modules\GeneralLedger\Http\Requests\DimensionReportRequest;
use Modules\GeneralLedger\Http\Requests\LedgerRangeReportRequest;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\AuditTrailExportService;
use Modules\GeneralLedger\Services\LedgerReportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports from the posted entries of the statutory ledger, with comparison ranges and drill-down to the account
 * ledger, and the audit trail export.
 */
final class LedgerReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LedgerReportService $reports,
        private readonly AuditTrailExportService $auditTrail,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Trial balance of a date range.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/reports/trial-balance',
        operationId: 'getGeneralLedgerTrialBalanceReport',
        description: 'Trial balance (bilancio di verifica) of a range of posting dates from posted entries only: per account the balance before the range, debit and credit of the range and the closing balance (debit minus credit), with an optional comparison range and optional cost centre, profit centre or project filter. Reversed entries and their reversals both count, so they cancel out; carried-forward opening entries are left out because they repeat balances already in the ledger. Each row carries the drill-down query of its account ledger. Amounts are decimal strings. Every amount is in the accounting (functional) currency of the tenant, stated in `currency`: a line booked in a foreign currency counts at its converted value.',
        summary: 'Trial balance report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reports'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportFrom'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportTo'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportCompareFrom'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportCompareTo'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportCostCenter'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportProfitCenter'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportProject'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trial balance', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerTrialBalanceReport')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid range or dimension of another tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function trialBalance(LedgerRangeReportRequest $request): JsonResponse
    {
        [$from, $to, $compareFrom, $compareTo] = $this->range($request);

        return $this->successResponse($this->reports->trialBalance($this->tenantId(), $from, $to, $compareFrom, $compareTo, $request->dimensions()));
    }

    /**
     * Ledger of one account (mastrino) with running balance.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/reports/account-ledger',
        operationId: 'getGeneralLedgerAccountLedgerReport',
        description: 'Account ledger (mastrino) of one account for a range of posting dates: the balance before the range, the posted lines oldest first with their journal entry (for drill-down to GET /journals/{id}), source document, partner and dimensions, the running balance after each line (it includes every earlier line of the range, whatever the page) and the closing balance. Balances and the debit/credit of each line are in the accounting currency (`currency`); each line also gives `transaction_currency`, `transaction_debit`, `transaction_credit` and `exchange_rate` as booked. 404 when the account is not in the active tenant.',
        summary: 'Account ledger report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reports'],
        parameters: [
            new OA\Parameter(name: 'account_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportFrom'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportTo'),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 500, minimum: 1)),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportCostCenter'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportProfitCenter'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportProject'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account ledger', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccountLedgerReport')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Account not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid range or parameters', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function accountLedger(AccountLedgerReportRequest $request): JsonResponse
    {
        $account = ChartOfAccount::findOrFail((string) $request->validated('account_id'));

        return $this->successResponse($this->reports->accountLedger(
            $this->tenantId(),
            $account,
            CarbonImmutable::parse((string) $request->validated('from')),
            CarbonImmutable::parse((string) $request->validated('to')),
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? 100),
            $request->dimensions()
        ));
    }

    /**
     * Balance sheet at a date.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/reports/balance-sheet',
        operationId: 'getGeneralLedgerBalanceSheetReport',
        description: 'Balance sheet (stato patrimoniale) at `as_of` from posted entries, by account type (asset and wip, liability, equity), amounts in the natural sign of each section; the result not yet closed into equity is reported on its own and added to equity, so `is_balanced` checks assets = liabilities + equity. With `compare_as_of` every account carries its comparison amount and the variance. For a statutory layout (art. 2424 c.c., Swiss OR) use a reclassification scheme (GET /financial-statements/reclassified). Every amount is in the accounting (functional) currency of the tenant, stated in `currency`: a line booked in a foreign currency counts at its converted value.',
        summary: 'Balance sheet report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reports'],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2027-12-31')),
            new OA\Parameter(name: 'compare_as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-12-31')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Balance sheet', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerStatementReport')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid date', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function balanceSheet(BalanceSheetReportRequest $request): JsonResponse
    {
        $compare = $request->validated('compare_as_of');

        return $this->successResponse($this->reports->balanceSheet(
            $this->tenantId(),
            CarbonImmutable::parse((string) $request->validated('as_of')),
            $compare !== null ? CarbonImmutable::parse((string) $compare) : null
        ));
    }

    /**
     * Income statement of a range.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/reports/income-statement',
        operationId: 'getGeneralLedgerIncomeStatementReport',
        description: 'Income statement (conto economico) of a range of posting dates from posted entries, by account type (revenue, cogs, expense) in the natural sign, without the year-end closing entries; gross profit and net income. With a comparison range every account carries its comparison amount and the variance; each account carries the drill-down query of its account ledger. Optional cost centre, profit centre or project filter. Every amount is in the accounting (functional) currency of the tenant, stated in `currency`: a line booked in a foreign currency counts at its converted value.',
        summary: 'Income statement report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reports'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportFrom'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportTo'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportCompareFrom'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportCompareTo'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportCostCenter'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportProfitCenter'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportProject'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Income statement', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerStatementReport')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid range or dimension of another tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function incomeStatement(LedgerRangeReportRequest $request): JsonResponse
    {
        [$from, $to, $compareFrom, $compareTo] = $this->range($request);

        return $this->successResponse($this->reports->incomeStatement($this->tenantId(), $from, $to, $compareFrom, $compareTo, $request->dimensions()));
    }

    /**
     * Result by cost centre, profit centre or project.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/reports/dimensions',
        operationId: 'getGeneralLedgerDimensionReport',
        description: 'Income statement accounts of a range grouped by cost centre, profit centre or project, from posted entries: per group the accounts with debit, credit and natural amount, and the result of the group (revenue minus costs). Lines without the dimension form a group with a null id, so the sum of the groups equals the net income of the range. Every amount is in the accounting (functional) currency of the tenant, stated in `currency`: a line booked in a foreign currency counts at its converted value.',
        summary: 'Dimension analysis report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reports'],
        parameters: [
            new OA\Parameter(name: 'dimension', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['cost_center', 'profit_center', 'project'])),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportFrom'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportTo'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dimension analysis', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerDimensionReport')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid dimension or range', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function dimensions(DimensionReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->reports->dimensionAnalysis(
            $this->tenantId(),
            (string) $request->validated('dimension'),
            CarbonImmutable::parse((string) $request->validated('from')),
            CarbonImmutable::parse((string) $request->validated('to'))
        ));
    }

    /**
     * Audit trail of the journal entries of a range.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/audit-trail/export',
        operationId: 'exportGeneralLedgerAuditTrail',
        description: 'Audit trail of the ledger for auditors: every journal entry with a posting date in the range, whatever its status (draft, pending_approval, posted, rejected, reversed), with who created, submitted, approved, rejected, posted and reversed it and when, the source document, the reversal link, the automatic reversal date and the debit/credit totals of its lines in the accounting currency. CSV (semicolon separated, UTF-8 with BOM, default) or JSON.',
        summary: 'Export ledger audit trail',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Reports'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportFrom'),
            new OA\Parameter(ref: '#/components/parameters/GeneralLedgerReportTo'),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'csv', enum: ['csv', 'json'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit trail file (CSV) or rows (JSON)', content: [new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string')), new OA\MediaType(mediaType: 'application/json', schema: new OA\Schema(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerAuditTrailRow'))]))]),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid range or format', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function auditTrail(AuditTrailExportRequest $request): JsonResponse|StreamedResponse
    {
        $tenantId = $this->tenantId();
        $from = CarbonImmutable::parse((string) $request->validated('from'));
        $to = CarbonImmutable::parse((string) $request->validated('to'));

        if ($request->validated('format') === 'json') {
            return $this->successResponse(iterator_to_array($this->auditTrail->rows($tenantId, $from, $to), false));
        }

        return response()->streamDownload(function () use ($tenantId, $from, $to): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, AuditTrailExportService::Header, ';', '"', '');

            foreach ($this->auditTrail->rows($tenantId, $from, $to) as $row) {
                fputcsv($output, array_map(static fn ($value): string => (string) ($value ?? ''), array_values($row)), ';', '"', '');
            }

            fclose($output);
        }, "audit-trail-{$from->toDateString()}-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?CarbonImmutable, 3: ?CarbonImmutable}
     */
    private function range(LedgerRangeReportRequest $request): array
    {
        $compareFrom = $request->validated('compare_from');
        $compareTo = $request->validated('compare_to');

        return [
            CarbonImmutable::parse((string) $request->validated('from')),
            CarbonImmutable::parse((string) $request->validated('to')),
            $compareFrom !== null ? CarbonImmutable::parse((string) $compareFrom) : null,
            $compareTo !== null ? CarbonImmutable::parse((string) $compareTo) : null,
        ];
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
