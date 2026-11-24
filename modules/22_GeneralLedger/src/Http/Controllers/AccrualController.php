<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Modules\GeneralLedger\Http\Requests\AccrualReconciliationExportRequest;
use Modules\GeneralLedger\Http\Requests\AccrualSummaryRequest;
use Modules\GeneralLedger\Http\Requests\GenerateAccrualClosingEntriesRequest;
use Modules\GeneralLedger\Http\Requests\GenerateAccrualReversalsRequest;
use Modules\GeneralLedger\Http\Requests\PreviewAccrualRequest;
use Modules\GeneralLedger\Http\Requests\StoreAccrualRequest;
use Modules\GeneralLedger\Http\Resources\AccrualResource;
use Modules\GeneralLedger\Models\Accrual;
use Modules\GeneralLedger\Services\AccrualCalculator;
use Modules\GeneralLedger\Services\AccrualClosingService;
use Modules\GeneralLedger\Services\AccrualReconciliationExporter;
use Modules\GeneralLedger\Services\AccrualScheduleService;
use Modules\GeneralLedger\Services\AccrualService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accruals and deferrals (ratei e risconti / Transitorische Konten) with multi-period competence recognition,
 * year-end 31/12 closing automation, 01/01 reversal entries, and auditor reconciliation export.
 */
final class AccrualController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccrualService $accruals,
        private readonly AccrualCalculator $calculator,
        private readonly AccrualScheduleService $schedule,
        private readonly AccrualClosingService $closing,
        private readonly AccrualReconciliationExporter $exporter,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The accruals and deferrals of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accruals',
        operationId: 'listGeneralLedgerAccruals',
        description: 'Accruals and deferrals of the tenant, newest cut-off first, optionally of one type or one cut-off date, with the status of their entry (posted, pending_approval, reversed).',
        summary: 'List accruals and deferrals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        parameters: [
            new OA\Parameter(name: 'accrual_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: Accrual::Types)),
            new OA\Parameter(name: 'cutoff_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Accruals and deferrals', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerAccrualResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $accruals = Accrual::with(['journalEntry', 'reversalJournalEntry'])
            ->when($request->query('accrual_type'), static fn ($query, $type) => $query->where('accrual_type', $type))
            ->when($request->query('cutoff_date'), static fn ($query, $date) => $query->whereDate('cutoff_date', $date))
            ->orderByDesc('cutoff_date')
            ->orderByDesc('created_at')
            ->gridPaginate(min(1000, max(1, (int) $request->query('per_page', 1000))));

        return $this->successResponse(
            data: AccrualResource::collection($accruals),
            meta: ['current_page' => $accruals->currentPage(), 'last_page' => $accruals->lastPage(), 'per_page' => $accruals->perPage(), 'total' => $accruals->total()],
        );
    }

    /**
     * Computes a deterministic multi-period accrual/deferral preview without persisting anything.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/accruals/preview',
        operationId: 'previewGeneralLedgerAccrual',
        description: 'Computes exact calendar days, largest-remainder multi-year apportionment, current-year recognized amount, year-end transitory amount, and inferred classification (rateo/risconto attivo/passivo) without creating journal entries.',
        summary: 'Preview multi-period competence split',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerPreviewAccrualRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Preview calculated', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerPreviewAccrualResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function preview(PreviewAccrualRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $data = $request->validated();
        $window = $this->schedule->resolveFiscalWindow(
            $tenantId,
            isset($data['fiscal_year_id']) ? (string) $data['fiscal_year_id'] : null,
            isset($data['year']) ? (int) $data['year'] : null,
        );

        $fyStart = $window['start'];
        $fyEnd = ! empty($data['cutoff_date'])
            ? CarbonImmutable::parse((string) $data['cutoff_date'])->startOfDay()
            : $window['end'];
        if ($fyEnd->lessThan($fyStart)) {
            $fyStart = $fyEnd->startOfYear();
        }

        $result = $this->calculator->calculate(
            totalAmount: (string) $data['total_amount'],
            start: CarbonImmutable::parse((string) $data['competence_start_date']),
            end: CarbonImmutable::parse((string) $data['competence_end_date']),
            fyStart: $fyStart,
            fyEnd: $fyEnd,
            documentDate: ! empty($data['document_date']) ? CarbonImmutable::parse((string) $data['document_date']) : null,
            direction: isset($data['direction']) ? (string) $data['direction'] : null,
            explicitType: isset($data['accrual_type']) ? (string) $data['accrual_type'] : null,
        );

        return $this->successResponse($result);
    }

    /**
     * Year-end accruals & deferrals schedule aggregated from invoices, bills, manual journals, and standalone accruals.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accruals/summary',
        operationId: 'summaryGeneralLedgerAccruals',
        description: 'Returns the year-end accruals and deferrals workbench schedule (KPIs + filterable rows across sales invoice items, passive invoice lines, manual journal lines, and standalone accruals) with linked 31/12 closing and 01/01 reversal entries.',
        summary: 'Year-end accruals and deferrals summary',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        parameters: [
            new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'accrual_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: Accrual::Types)),
            new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'source_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: Accrual::SourceTypes)),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['calculated', 'closing_posted', 'reversal_posted'])),
            new OA\Parameter(name: 'competence_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'competence_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Year-end summary schedule', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccrualSummaryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function summary(AccrualSummaryRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        return $this->successResponse($this->schedule->summary($tenantId, $request->validated()));
    }

    /**
     * Generates 31/12 closing adjustment journal entries (and optionally 01/01 reversals) for pending transitory items.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/accruals/closing-entries',
        operationId: 'generateGeneralLedgerAccrualClosingEntries',
        description: '1-click generation of balanced year-end adjustment journal entries on the fiscal year-end cut-off date (31/12) for selected or all pending transitory items, preventing duplicate postings and optionally triggering 01/01 reversals.',
        summary: 'Generate year-end closing entries',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerGenerateClosingEntriesRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Closing entries processed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function generateClosingEntries(GenerateAccrualClosingEntriesRequest $request): JsonResponse
    {
        $result = $this->closing->generateClosingEntries(
            (string) $this->tenantContext->getTenantId(),
            $request->validated(),
            (string) $request->user()->id,
        );

        return $this->successResponse($result, 'Year-end accrual closing entries processed');
    }

    /**
     * Generates 01/01 mirror reversal journal entries for posted accrual closing entries.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/accruals/reversals',
        operationId: 'generateGeneralLedgerAccrualReversals',
        description: 'Generates the mirror opening reversal entries dated 01/01 of the next fiscal year (or the configured reversal date) for posted accrual closing entries.',
        summary: 'Generate new-year reversal entries',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerGenerateReversalsRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Reversal entries processed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function generateReversals(GenerateAccrualReversalsRequest $request): JsonResponse
    {
        $result = $this->closing->generateReversals(
            (string) $this->tenantContext->getTenantId(),
            $request->validated(),
            (string) $request->user()->id,
        );

        return $this->successResponse($result, 'Accrual reversal entries processed');
    }

    /**
     * Exports the auditor reconciliation worksheet as CSV (default) or native .xlsx (format=xlsx).
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accruals/reconciliation-export',
        operationId: 'exportGeneralLedgerAccrualReconciliation',
        description: 'Downloads the Mod 022 auditor reconciliation worksheet as CSV (UTF-8 BOM, semicolons, CRLF) or as a native .xlsx workbook (format=xlsx) containing accounts, document references, competence periods, day ratios, amounts, and closing/reversal journal entry references.',
        summary: 'Export accruals reconciliation worksheet',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        parameters: [
            new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'accrual_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: Accrual::Types)),
            new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'source_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: Accrual::SourceTypes)),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['calculated', 'closing_posted', 'reversal_posted'])),
            new OA\Parameter(name: 'competence_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'competence_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'Download format, csv by default.', schema: new OA\Schema(type: 'string', enum: ['csv', 'xlsx'], default: 'csv')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reconciliation worksheet (CSV or XLSX)', content: [new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary')), new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', schema: new OA\Schema(type: 'string', format: 'binary'))]),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function exportReconciliation(AccrualReconciliationExportRequest $request): HttpResponse
    {
        $export = $this->exporter->export(
            (string) $this->tenantContext->getTenantId(),
            $request->safe()->except('format'),
            (string) $request->validated('format', 'csv'),
        );

        return response($export['content'], 200, [
            'Content-Type' => $export['content_type'],
            'Content-Disposition' => "attachment; filename=\"{$export['filename']}\"",
        ]);
    }

    /**
     * Computes and posts an accrual or deferral.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/accruals',
        operationId: 'createGeneralLedgerAccrual',
        description: 'Computes and posts an accrual or deferral at the cut-off date. The share is total_amount × days / coverage days (days counted inclusively, rounded half up to the cent): for ratei (accrued_revenue = rateo attivo, accrued_expense = rateo passivo) the days from coverage_start to the cut-off, for risconti (prepaid_expense = risconto attivo, deferred_revenue = risconto passivo) the days after the cut-off. The adjusting entry is posted on the cut-off date (Dr asset/Cr revenue, Dr expense/Cr liability, Dr asset/Cr expense, Dr revenue/Cr liability respectively), subject to the four-eyes policy, and reversed automatically on the day after. The income statement account must be revenue (for *_revenue) or expense/cogs (for *_expense), the balance sheet account an asset (rateo attivo, risconto attivo) or a liability (rateo passivo, risconto passivo).',
        summary: 'Create accrual or deferral',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreAccrualRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Accrual posted', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccrualResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, accounts not fitting the type, cut-off outside the coverage, or fiscal period of the cut-off missing or closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreAccrualRequest $request): JsonResponse
    {
        $accrual = $this->accruals->create((string) $this->tenantContext->getTenantId(), $request->validated(), (string) $request->user()->id);

        return $this->successResponse(new AccrualResource($accrual), 'Accrual posted', Response::HTTP_CREATED);
    }

    /**
     * One accrual or deferral.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accruals/{id}',
        operationId: 'getGeneralLedgerAccrual',
        description: 'An accrual or deferral with the computation (coverage days, accrued days, amount) and its entry.',
        summary: 'Get accrual or deferral',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accruals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Accrual', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccrualResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new AccrualResource(Accrual::with(['journalEntry', 'reversalJournalEntry'])->findOrFail($id)));
    }
}
