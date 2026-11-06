<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Http\Requests\CalculateWithholdingSettlementRequest;
use Modules\GeneralLedger\Http\Requests\PayTaxSettlementRequest;
use Modules\GeneralLedger\Http\Resources\WithholdingSettlementResource;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Models\WithholdingSettlement;
use Modules\GeneralLedger\Services\WithholdingSettlementService;
use OpenApi\Attributes as OA;

/**
 * Tax filings share the access rules of the VAT settlement, so its policy decides who reaches them.
 */
final class WithholdingSettlementController extends Controller
{
    public function __construct(
        private readonly WithholdingSettlementService $settlements,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Lists the withholding settlements of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/withholding-settlements',
        operationId: 'listGeneralLedgerWithholdingSettlements',
        description: 'Lists the monthly withholding settlements of the active tenant, most recent first.',
        summary: 'List withholding settlements',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Filter by year', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string', enum: ['calculated', 'paid'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerWithholdingSettlementResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        $settlements = WithholdingSettlement::where('tenant_id', $this->tenantId($request))
            ->when($request->query('year'), fn ($query, $year) => $query->where('year', (int) $year))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return response()->json(['data' => WithholdingSettlementResource::collection($settlements)]);
    }

    /**
     * Calculates the register of a month from the supplier payments of that month.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/withholding-settlements/calculate',
        operationId: 'calculateGeneralLedgerWithholdingSettlement',
        description: 'Calculates the monthly register from the supplier invoices paid in the month: withholding is due at payment, so a partial payment carries its share. A settlement still `calculated` is replaced; a paid one cannot be calculated again.',
        summary: 'Calculate withholding settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year', 'month'], properties: [
            new OA\Property(property: 'year', type: 'integer', example: 2027),
            new OA\Property(property: 'month', type: 'integer', maximum: 12, minimum: 1, example: 3),
        ])),
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerWithholdingSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The month is already paid', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function calculate(CalculateWithholdingSettlementRequest $request): JsonResponse
    {
        $this->authorize('calculate', VatSettlement::class);

        $settlement = $this->settlements->calculate(
            $this->tenantId($request),
            (int) $request->validated('year'),
            (int) $request->validated('month'),
            (string) $request->user()->id
        );

        return response()->json(['data' => new WithholdingSettlementResource($settlement)]);
    }

    /**
     * Returns a withholding settlement with its lines.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/withholding-settlements/{id}',
        operationId: 'getGeneralLedgerWithholdingSettlement',
        description: 'Returns a withholding settlement of the active tenant with its lines.',
        summary: 'Get withholding settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerWithholdingSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        return response()->json(['data' => new WithholdingSettlementResource($this->settlementOf($request, $id)->load('lines'))]);
    }

    /**
     * Returns the F24 data of a withholding settlement.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/withholding-settlements/{id}/f24',
        operationId: 'getGeneralLedgerWithholdingSettlementF24',
        description: 'Returns the F24 data of a withholding settlement: one line per tax code, reference month and year, and due date. Contributions such as Enasarco are not part of it.',
        summary: 'Get withholding F24 data',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerWithholdingF24Data')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function f24(Request $request, string $id): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        return response()->json(['data' => $this->settlements->f24($this->settlementOf($request, $id)->load('lines'))]);
    }

    /**
     * Marks a withholding settlement as paid.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/withholding-settlements/{id}/pay',
        operationId: 'payGeneralLedgerWithholdingSettlement',
        description: 'Marks a withholding settlement as paid. With `bank_account_id` the payment is also registered in the journal. A paid settlement can no longer change.',
        summary: 'Pay withholding settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerPayTaxSettlementRequest')),
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the settlement', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerWithholdingSettlementResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already paid', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Nothing to pay, or validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function pay(PayTaxSettlementRequest $request, string $id): JsonResponse
    {
        $this->authorize('calculate', VatSettlement::class);

        $settlement = $this->settlementOf($request, $id);

        $paid = $this->settlements->markPaid(
            (string) $settlement->tenant_id,
            $id,
            (string) $request->validated('payment_date'),
            $request->validated('bank_account_id'),
            (string) $request->user()->id
        );

        return response()->json(['data' => new WithholdingSettlementResource($paid)]);
    }

    /**
     * Downloads the yearly totals per supplier and causale for the CU.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/withholding-settlements/certification-export',
        operationId: 'exportGeneralLedgerWithholdingCertification',
        description: 'Downloads a CSV with what each supplier was paid and withheld in the year, per causale, to prepare the CU. It is a working document built from the calculated months: nothing is sent to the tax authority.',
        summary: 'Export CU data',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, description: 'Year of the payments', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function certificationExport(Request $request): Response
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        $validated = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $file = $this->settlements->certificationExport($this->tenantId($request), (int) $validated['year']);

        return response($file['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$file['file_name']}\"",
        ]);
    }

    private function settlementOf(Request $request, string $id): WithholdingSettlement
    {
        return WithholdingSettlement::where('id', $id)
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
}
