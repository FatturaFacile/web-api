<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\AssetManagement\Http\Requests\AssetRegisterRequest;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Services\AssetCoverageAlertService;
use Modules\AssetManagement\Services\AssetRegisterReportService;
use Modules\AssetManagement\Support\AssetLedger;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports of the asset module: the asset register (libro cespiti) with CSV export and the expiring warranties and
 * insurances.
 */
final class AssetReportController extends Controller
{
    public function __construct(
        private readonly AssetRegisterReportService $register,
        private readonly AssetCoverageAlertService $coverage,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Asset register of a year and ledger, as paginated JSON or CSV.
     */
    #[OA\Get(
        path: '/api/v1/eam/reports/asset-register',
        operationId: 'eamAssetRegister',
        description: 'Asset register (libro cespiti) of a year and ledger: cost at start, additions, disposals, cost at end, accumulated depreciation at start, depreciation of the year, depreciation released by disposals, accumulated depreciation, value adjustments and net book value at year end. format=csv streams every row (semicolon separated).',
        summary: 'Asset register',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Reports'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(ref: '#/components/parameters/EamLedgerType'),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv', 'pdf'])),
            new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['CH', 'IT'])),
            new OA\Parameter(name: 'locale', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/EamPage'),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 200, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Register page (JSON) or file (CSV / PDF)', content: [
                new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementAssetRegisterRow')),
                    new OA\Property(property: 'meta', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'year', type: 'integer'), new OA\Property(property: 'ledger_type', type: 'string')], type: 'object'),
                ]),
                new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string')),
                new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
            ]),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function register(AssetRegisterRequest $request): JsonResponse|StreamedResponse|Response
    {
        $data = $request->validated();
        $tenantId = (string) $this->tenantContext->getTenantId();
        $year = (int) $data['year'];
        $ledgerType = AssetLedger::normalize($data['ledger_type'] ?? null);
        $jurisdiction = $data['jurisdiction'] ?? null;
        $format = (string) ($data['format'] ?? 'json');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($tenantId, $year, $ledgerType): void {
                $output = fopen('php://output', 'wb');
                fputcsv($output, AssetRegisterReportService::Columns, ';', '"', '');
                foreach ($this->register->all($tenantId, $year, $ledgerType) as $row) {
                    fputcsv($output, array_map(static fn (string $column): string => (string) ($row[$column] ?? ''), AssetRegisterReportService::Columns), ';', '"', '');
                }
                fclose($output);
            }, "asset-register-{$year}-{$ledgerType}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        if ($format === 'pdf') {
            $rows = iterator_to_array($this->register->all($tenantId, $year, $ledgerType), false);
            $header = $this->register->complianceHeader($tenantId, $year, $ledgerType, $jurisdiction);
            $categorySubtotals = $this->register->categorySubtotals($tenantId, $year, $ledgerType);
            $totals = $this->register->totals($tenantId, $year, $ledgerType);
            $locale = (string) ($data['locale'] ?? app()->getLocale());

            $pdfBytes = Pdf::loadView('documents.asset-register', [
                'rows' => $rows,
                'header' => $header,
                'categorySubtotals' => $categorySubtotals,
                'totals' => $totals,
                'locale' => $locale,
            ])->setPaper('a4', 'landscape')->output();

            return response($pdfBytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"libro-cespiti-{$year}-{$ledgerType}-{$header['jurisdiction']}.pdf\"",
            ]);
        }

        $page = $this->register->page($tenantId, $year, $ledgerType, (int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'year' => $year,
                'ledger_type' => $ledgerType,
                'compliance_header' => $this->register->complianceHeader($tenantId, $year, $ledgerType, $jurisdiction),
                'category_subtotals' => $this->register->categorySubtotals($tenantId, $year, $ledgerType),
                'totals' => $this->register->totals($tenantId, $year, $ledgerType),
            ],
        ]);
    }

    /**
     * Assets whose warranty or insurance expires within the horizon.
     */
    #[OA\Get(
        path: '/api/v1/eam/alerts/expiring',
        operationId: 'eamExpiringCoverage',
        description: 'Assets whose warranty or insurance expires within `days` (default 30, at most 365); expired=true also lists the already expired ones. The daily eam:expiring-coverage job notifies owners, administrators, accountants and the custodian once per expiry date.',
        summary: 'Expiring warranties and insurances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Reports'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 365, minimum: 1)),
            new OA\Parameter(name: 'expired', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Expiring coverages', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementExpiringCoverage'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function expiring(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->query('days', '30')));

        $assets = $this->coverage->expiringQuery((string) $this->tenantContext->getTenantId(), $days, $request->boolean('expired'))->limit(500)->get();

        return response()->json(['data' => $assets->map(static fn (FixedAsset $asset): array => [
            'asset_id' => (string) $asset->id,
            'asset_number' => (string) $asset->asset_number,
            'name' => (string) $asset->name,
            'custodian_user_id' => $asset->custodian_user_id,
            'warranty_expiry_date' => $asset->warranty_expiry_date?->format('Y-m-d'),
            'warranty_provider' => $asset->warranty_provider,
            'insurance_expiry_date' => $asset->insurance_expiry_date?->format('Y-m-d'),
            'insurance_provider' => $asset->insurance_provider,
            'insurance_policy_number' => $asset->insurance_policy_number,
        ])->values()]);
    }
}
