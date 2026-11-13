<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Contracts\VatSettlementServiceInterface;
use Modules\GeneralLedger\Http\Requests\CalculateTaxSettlementRequest;
use Modules\GeneralLedger\Http\Requests\PayTaxSettlementRequest;
use Modules\GeneralLedger\Http\Resources\JournalEntryResource;
use Modules\GeneralLedger\Http\Resources\VatSettlementResource;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\GeneralLedger\Services\Tax\VatPeriod;
use Modules\GeneralLedger\Services\VatAnnualReconciliationService;
use Modules\GeneralLedger\Services\VatFilingExportService;
use OpenApi\Attributes as OA;

final class TaxSettlementController extends Controller
{
    public function __construct(
        private readonly VatSettlementServiceInterface $settlementService,
        private readonly VatFilingExportService $exportService,
        private readonly VatAnnualReconciliationService $annualReconciliationService,
        private readonly VatAccountResolver $accountResolver,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Lists the VAT settlements of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settlements',
        operationId: 'listGeneralLedgerTaxSettlements',
        description: 'Lists the VAT settlements of the active tenant, most recent first.',
        summary: 'List VAT settlements',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string', enum: ['calculated', 'posted', 'paid'])),
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Filter by year', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerVatSettlementResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VatSettlement::class);

        $settlements = VatSettlement::where('tenant_id', $this->tenantId($request))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('year'), fn ($query, $year) => $query->where('year', (int) $year))
            ->orderByDesc('period_to')
            ->gridPaginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => VatSettlementResource::collection($settlements->getCollection()),
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'last_page' => $settlements->lastPage(),
                'per_page' => $settlements->perPage(),
                'total' => $settlements->total(),
            ],
        ]);
    }

    /**
     * Returns a VAT settlement of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settlements/{id}',
        operationId: 'getGeneralLedgerTaxSettlement',
        description: 'Returns a VAT settlement of the active tenant.',
        summary: 'Get VAT settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $settlement = $this->settlementOf($request, $id);
        $this->authorize('view', $settlement);

        return response()->json([
            'data' => new VatSettlementResource($settlement),
        ]);
    }

    /**
     * Calculates the VAT settlement of a fiscal period, or of a month, quarter or half year.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/tax-settlements/calculate',
        operationId: 'calculateGeneralLedgerTaxSettlement',
        description: 'Calculates the VAT settlement from the posted journal entries, for a fiscal period or for a year and period number. A settlement still `calculated` is updated; a posted or paid one cannot be calculated again.',
        summary: 'Calculate VAT settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerCalculateTaxSettlementRequest')),
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Fiscal period not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The period is already settled', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function calculate(CalculateTaxSettlementRequest $request): JsonResponse
    {
        $this->authorize('calculate', VatSettlement::class);

        $tenantId = $this->tenantId($request);
        $actorId = (string) $request->user()->id;
        $fiscalPeriodId = $request->validated('fiscal_period_id');

        $settlement = $fiscalPeriodId !== null
            ? $this->settlementService->calculateForFiscalPeriod($tenantId, (string) $fiscalPeriodId, $actorId)
            : $this->settlementService->calculate($tenantId, $this->requestedPeriod($request, $tenantId), $actorId);

        return response()->json([
            'message' => 'VAT settlement calculated successfully',
            'data' => new VatSettlementResource($settlement),
        ]);
    }

    /**
     * Posts the journal entry of a VAT settlement and returns it.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/tax-settlements/{id}/post',
        operationId: 'postGeneralLedgerTaxSettlement',
        description: 'Posts the journal entry of a VAT settlement and returns it. `data` is null when the period moved no VAT. The settlement can no longer change and can be posted only once.',
        summary: 'Post VAT settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerJournalEntryResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already posted, or the ledger changed after the calculation', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The entry cannot be registered', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function post(Request $request, string $id): JsonResponse
    {
        $settlement = $this->settlementOf($request, $id);
        $this->authorize('post', $settlement);

        $journal = $this->settlementService->post((string) $settlement->tenant_id, $id, (string) $request->user()->id);

        return response()->json([
            'message' => 'VAT settlement journal posted successfully',
            'data' => $journal !== null ? new JournalEntryResource($journal) : null,
        ]);
    }

    /**
     * Records the payment of a posted VAT settlement.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/tax-settlements/{id}/pay',
        operationId: 'payGeneralLedgerTaxSettlement',
        description: 'Marks a posted VAT settlement as paid. With `bank_account_id` the payment is also registered in the journal.',
        summary: 'Pay VAT settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerPayTaxSettlementRequest')),
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The settlement is not posted', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Nothing to pay, or validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function pay(PayTaxSettlementRequest $request, string $id): JsonResponse
    {
        $settlement = $this->settlementOf($request, $id);
        $this->authorize('pay', $settlement);

        $paid = $this->settlementService->markPaid(
            (string) $settlement->tenant_id,
            $id,
            (string) $request->validated('payment_date'),
            $request->validated('bank_account_id'),
            (string) $request->user()->id
        );

        return response()->json([
            'message' => 'VAT settlement marked as paid',
            'data' => new VatSettlementResource($paid),
        ]);
    }

    /**
     * Downloads the LIPE XML file of an Italian VAT settlement.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settlements/{id}/lipe',
        operationId: 'exportGeneralLedgerTaxSettlementLipe',
        description: 'Downloads the LIPE XML file (supply IVP18) of an Italian VAT settlement. The file must be checked with the control software of the Agenzia delle Entrate before it is sent.',
        summary: 'Export LIPE XML',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'LIPE XML file', content: new OA\MediaType(mediaType: 'application/xml')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Not an Italian settlement, or company data missing', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function lipe(Request $request, string $id): Response
    {
        $settlement = $this->settlementOf($request, $id);
        $this->authorize('export', $settlement);

        $file = $this->exportService->lipe($settlement);

        return $this->download($file['content'], $file['file_name'], 'application/xml');
    }

    /**
     * Returns the F24 data of an Italian VAT settlement.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settlements/{id}/f24',
        operationId: 'getGeneralLedgerTaxSettlementF24',
        description: 'Returns the F24 data of an Italian VAT settlement: tax code, reference year and amount, or the credit or small debt carried forward.',
        summary: 'Get F24 data',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerVatF24Data')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Not an Italian settlement', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function f24(Request $request, string $id): JsonResponse
    {
        $settlement = $this->settlementOf($request, $id);
        $this->authorize('export', $settlement);

        return response()->json(['data' => $this->exportService->f24($settlement)]);
    }

    /**
     * Downloads the Swiss MWST statement of a VAT settlement.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settlements/{id}/statement',
        operationId: 'exportGeneralLedgerTaxSettlementStatement',
        description: 'Downloads the Swiss MWST statement (effective method) of a VAT settlement as CSV or PDF. It is a working document: nothing is sent to the tax authority.',
        summary: 'Export MWST statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'File format', schema: new OA\Schema(type: 'string', default: 'csv', enum: ['csv', 'pdf'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statement file', content: [new OA\MediaType(mediaType: 'text/csv'), new OA\MediaType(mediaType: 'application/pdf')]),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Not a Swiss settlement', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function statement(Request $request, string $id): Response
    {
        $settlement = $this->settlementOf($request, $id);
        $this->authorize('export', $settlement);

        if ($request->query('format') === 'pdf') {
            $file = $this->exportService->swissStatementPdf($settlement);

            return $this->download($file['content'], $file['file_name'], 'application/pdf');
        }

        $file = $this->exportService->swissStatementCsv($settlement);

        return $this->download($file['content'], $file['file_name'], 'text/csv; charset=UTF-8');
    }

    /**
     * Downloads the Swiss eCH-0217 XML declaration of a VAT settlement.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/tax-settlements/{id}/swiss-xml',
        operationId: 'exportGeneralLedgerTaxSettlementSwissXml',
        description: 'Downloads the official Swiss eCH-0217 v2.0 XML VAT declaration for ESTV ePortal upload.',
        summary: 'Export Swiss eCH-0217 VAT XML',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the tax settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'eCH-0217 XML file', content: new OA\MediaType(mediaType: 'application/xml')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Not a Swiss settlement', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function swissXml(Request $request, string $id): Response
    {
        $settlement = $this->settlementOf($request, $id);
        $this->authorize('export', $settlement);

        $file = $this->exportService->swissEstvXml($settlement);

        return $this->download($file['content'], $file['file_name'], 'application/xml');
    }

    /**
     * Returns the Swiss VAT Annual Reconciliation (Umsatzabstimmung & Vorsteuerabstimmung, Art. 72 LIVA).
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/vat-annual-reconciliation',
        operationId: 'getGeneralLedgerVatAnnualReconciliation',
        description: 'Computes the Swiss VAT annual reconciliation (Umsatzabstimmung & Vorsteuerabstimmung under Art. 72 LIVA) comparing declared periodic VAT returns against the statutory General Ledger.',
        summary: 'Get Swiss VAT annual reconciliation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Calendar year', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Annual VAT reconciliation report', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function annualReconciliation(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VatSettlement::class);

        $year = (int) ($request->query('year') ?: now()->year);

        return response()->json([
            'data' => $this->annualReconciliationService->reconcileYear($this->tenantId($request), $year),
        ]);
    }

    private function requestedPeriod(CalculateTaxSettlementRequest $request, string $tenantId): VatPeriod
    {
        $settings = $this->accountResolver->settingsOf($tenantId);

        return VatPeriod::of(
            (string) ($request->validated('period_type') ?? $settings->periodicity ?? VatPeriod::MONTHLY),
            (int) $request->validated('year'),
            (int) $request->validated('period')
        );
    }

    private function settlementOf(Request $request, string $id): VatSettlement
    {
        return VatSettlement::where('id', $id)
            ->where('tenant_id', $this->tenantId($request))
            ->firstOrFail();
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return $tenantId;
    }

    private function download(string $content, string $fileName, string $contentType): Response
    {
        return response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
