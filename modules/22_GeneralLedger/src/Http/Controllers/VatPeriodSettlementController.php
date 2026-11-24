<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Http\Requests\PreviewVatSettlementPeriodRequest;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Models\VatSettlementPeriod;
use Modules\GeneralLedger\Services\Tax\VatPeriod;
use Modules\GeneralLedger\Services\VatPeriodSettlementService;
use OpenApi\Attributes as OA;

final class VatPeriodSettlementController extends Controller
{
    public function __construct(
        private readonly VatPeriodSettlementService $service,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/vat/settlements',
        operationId: 'listVatSettlementPeriods',
        summary: 'List locked CH/IT VAT periods',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['VAT Settlement Engine'],
        responses: [new OA\Response(response: 200, description: 'Request completed successfully')]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VatSettlement::class);

        $periods = VatSettlementPeriod::query()
            ->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('period_to')
            ->get();

        return response()->json(['data' => $periods->map(fn (VatSettlementPeriod $period): array => $this->present($period, false))->values()]);
    }

    #[OA\Post(
        path: '/api/v1/vat/settlements/preview',
        operationId: 'previewVatSettlementPeriod',
        summary: 'Preview a CH (MWST) or IT (LIPE) VAT period without storing it',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'period_type', type: 'string', enum: ['monthly', 'quarterly', 'semiannual']),
            new OA\Property(property: 'year', type: 'integer'),
            new OA\Property(property: 'period', type: 'integer'),
        ])),
        tags: ['VAT Settlement Engine'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function preview(PreviewVatSettlementPeriodRequest $request): JsonResponse
    {
        $this->authorize('calculate', VatSettlement::class);

        return response()->json(['data' => $this->service->preview($this->tenantId($request), $this->period($request))]);
    }

    #[OA\Post(
        path: '/api/v1/vat/settlements',
        operationId: 'lockVatSettlementPeriod',
        summary: 'Lock a VAT period with a frozen calculation snapshot',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'period_type', type: 'string', enum: ['monthly', 'quarterly', 'semiannual']),
            new OA\Property(property: 'year', type: 'integer'),
            new OA\Property(property: 'period', type: 'integer'),
        ])),
        tags: ['VAT Settlement Engine'],
        responses: [
            new OA\Response(response: 201, description: 'Period locked'),
            new OA\Response(response: 409, description: 'The period is already locked'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(PreviewVatSettlementPeriodRequest $request): JsonResponse
    {
        $this->authorize('calculate', VatSettlement::class);

        $period = $this->service->lock($this->tenantId($request), $this->period($request), (string) $request->user()?->id);

        return response()->json(['data' => $this->present($period, true)], 201);
    }

    #[OA\Get(
        path: '/api/v1/vat/settlements/{settlement}',
        operationId: 'showVatSettlementPeriod',
        summary: 'Show a locked VAT period with its frozen calculation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['VAT Settlement Engine'],
        parameters: [new OA\Parameter(name: 'settlement', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Request completed successfully'), new OA\Response(response: 404, description: 'Not found')]
    )]
    public function show(Request $request, string $settlement): JsonResponse
    {
        $this->authorize('viewAny', VatSettlement::class);

        return response()->json(['data' => $this->present($this->periodOf($request, $settlement), true)]);
    }

    #[OA\Get(
        path: '/api/v1/vat/settlements/{settlement}/export-xml',
        operationId: 'exportVatSettlementPeriodXml',
        summary: 'Export the filing XML of a locked period (eCH-0217 for CH, LIPE for IT)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['VAT Settlement Engine'],
        parameters: [new OA\Parameter(name: 'settlement', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'XML file'), new OA\Response(response: 404, description: 'Not found')]
    )]
    public function exportXml(Request $request, string $settlement): Response
    {
        $this->authorize('viewAny', VatSettlement::class);

        $file = $this->service->exportXml($this->periodOf($request, $settlement));

        return response($file['content'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['file_name'].'"',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/vat/settlements/{settlement}/export-pdf',
        operationId: 'exportVatSettlementPeriodPdf',
        summary: 'Export a PDF summary of a locked period',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['VAT Settlement Engine'],
        parameters: [new OA\Parameter(name: 'settlement', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'PDF file'), new OA\Response(response: 404, description: 'Not found')]
    )]
    public function exportPdf(Request $request, string $settlement): Response
    {
        $this->authorize('viewAny', VatSettlement::class);

        $period = $this->periodOf($request, $settlement);
        $calculation = $period->calculation;
        $boxes = $period->jurisdiction === 'CH' ? ($calculation['swiss_boxes'] ?? []) : ($calculation['lipe_boxes'] ?? []);
        $rows = '';

        foreach ($boxes as $code => $value) {
            $text = is_array($value) ? e(json_encode($value, JSON_THROW_ON_ERROR)) : e((string) $value);
            $rows .= '<tr><td>'.e((string) $code).'</td><td style="text-align:right">'.$text.'</td></tr>';
        }

        $title = $period->jurisdiction === 'CH' ? 'MWST' : 'LIPE';
        $html = '<html><body style="font-family:DejaVu Sans,sans-serif;font-size:11px"><h2>'.$title.' '.e((string) $calculation['period']['label']).'</h2>'
            .'<p>'.e($period->period_from->toDateString()).' - '.e($period->period_to->toDateString()).' / '.e((string) $period->status).'</p>'
            .'<table width="100%" border="1" cellspacing="0" cellpadding="3">'.$rows.'</table>'
            .'<p><strong>'.e((string) $period->payable_amount).'</strong></p></body></html>';

        return response(Pdf::loadHTML($html)->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="vat-'.strtolower($title).'-'.$calculation['period']['label'].'.pdf"',
        ]);
    }

    private function period(PreviewVatSettlementPeriodRequest $request): VatPeriod
    {
        return VatPeriod::of((string) $request->validated('period_type'), (int) $request->validated('year'), (int) $request->validated('period'));
    }

    private function periodOf(Request $request, string $id): VatSettlementPeriod
    {
        return VatSettlementPeriod::query()
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId($request))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(VatSettlementPeriod $period, bool $withCalculation): array
    {
        $data = [
            'id' => $period->id,
            'jurisdiction' => $period->jurisdiction,
            'period_type' => $period->period_type,
            'period_from' => $period->period_from->toDateString(),
            'period_to' => $period->period_to->toDateString(),
            'regime' => $period->regime,
            'basis' => $period->basis,
            'payable_amount' => (string) $period->payable_amount,
            'status' => $period->status,
            'locked_at' => $period->locked_at?->toIso8601String(),
            'filed_at' => $period->filed_at?->toIso8601String(),
        ];

        if ($withCalculation) {
            $data['calculation'] = $period->calculation;
        }

        return $data;
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }
}
