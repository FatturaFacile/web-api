<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Get executive KPI summary (Total revenue, unpaid invoices, open tickets, cross-border metrics).
     */
    #[OA\Get(
        path: '/api/v1/dashboard/kpi-summary',
        operationId: 'getKpiSummary',
        description: 'Get executive KPI summary (total revenue, unpaid receivables, invoice count).',
        summary: 'Get KPI summary',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard KPI summary retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function kpiSummary(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $currency = $tenant?->default_currency ?? 'EUR';

        $totalRevenue = (float) Invoice::where('payment_status', 'paid')->sum('total_gross');
        $totalUnpaid = (float) Invoice::where('payment_status', 'unpaid')->sum('total_gross');
        $invoicesCount = Invoice::count();

        return $this->successResponse(
            data: [
                'tenant' => [
                    'id' => $tenant?->id,
                    'name' => $tenant?->name,
                    'country' => $tenant?->country,
                    'currency' => $currency,
                ],
                'kpis' => [
                    'total_revenue' => $totalRevenue,
                    'total_unpaid' => $totalUnpaid,
                    'invoices_count' => $invoicesCount,
                    'active_currency' => $currency,
                ],
                'metrics_timestamp' => now()->toIso8601String(),
            ],
            message: 'Dashboard KPI summary retrieved'
        );
    }

    /**
     * Cash flow 30-60-90 days forecast based on active receivables and payables.
     */
    #[OA\Get(
        path: '/api/v1/dashboard/cash-flow-forecast',
        operationId: 'getCashFlowForecast',
        description: 'Cash flow 30-60-90 days forecast based on active receivables and payables.',
        summary: 'Cash flow forecast',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        responses: [
            new OA\Response(response: 200, description: 'Cash flow forecast generated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cashFlowForecast(): JsonResponse
    {
        $now = Carbon::now();
        $tenant = $this->tenantContext->getTenant();
        $currency = $tenant?->default_currency ?? 'EUR';

        $days30 = (float) Invoice::whereBetween('due_date', [$now, $now->copy()->addDays(30)])
            ->where('payment_status', '!=', 'paid')
            ->sum('total_gross');

        $days60 = (float) Invoice::whereBetween('due_date', [$now->copy()->addDays(31), $now->copy()->addDays(60)])
            ->where('payment_status', '!=', 'paid')
            ->sum('total_gross');

        $days90 = (float) Invoice::whereBetween('due_date', [$now->copy()->addDays(61), $now->copy()->addDays(90)])
            ->where('payment_status', '!=', 'paid')
            ->sum('total_gross');

        return $this->successResponse(
            data: [
                'currency' => $currency,
                'forecast' => [
                    'next_30_days' => $days30,
                    'days_31_to_60' => $days60,
                    'days_61_to_90' => $days90,
                    'total_projected_inflow' => $days30 + $days60 + $days90,
                ],
            ],
            message: 'Cash flow forecast generated'
        );
    }

    /**
     * Tax and compliance deadlines (Italian Liquidazione IVA / Swiss MWST Abrechnung / Frontalieri reporting).
     */
    #[OA\Get(
        path: '/api/v1/dashboard/tax-deadlines',
        operationId: 'getTaxDeadlines',
        description: 'Tax and compliance deadlines (Italian Liquidazione IVA / Swiss MWST Abrechnung / Frontalieri reporting).',
        summary: 'Tax and compliance deadlines',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard', 'Fiscal'],
        responses: [
            new OA\Response(response: 200, description: 'Fiscal deadlines retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function taxDeadlines(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $country = $tenant?->country ?? 'IT';

        $deadlines = ($country === 'IT') ? [
            ['title' => 'Liquidazione IVA Mensile', 'due_date' => now()->startOfMonth()->addDays(15)->toDateString(), 'authority' => 'Agenzia delle Entrate', 'status' => 'upcoming'],
            ['title' => 'Esterometro / SDI Cross-Border', 'due_date' => now()->endOfMonth()->toDateString(), 'authority' => 'SDI', 'status' => 'upcoming'],
            ['title' => 'Certificazione Unica Frontalieri', 'due_date' => now()->startOfYear()->addMonths(2)->endOfMonth()->toDateString(), 'authority' => 'INPS / AdE', 'status' => 'pending'],
        ] : [
            ['title' => 'MWST-Abrechnung (Swiss VAT)', 'due_date' => now()->endOfQuarter()->addDays(60)->toDateString(), 'authority' => 'ESTV Bern', 'status' => 'upcoming'],
            ['title' => 'Quellensteuer Abrechnung (Imposta alla Fonte)', 'due_date' => now()->endOfMonth()->toDateString(), 'authority' => 'Kantonales Steueramt TI/ZH', 'status' => 'upcoming'],
            ['title' => 'Lohnbescheinigung / Swissdec ELM', 'due_date' => now()->startOfYear()->addMonths(1)->endOfMonth()->toDateString(), 'authority' => 'Ausgleichskasse', 'status' => 'scheduled'],
        ];

        return $this->successResponse(
            data: [
                'jurisdiction' => $country,
                'deadlines' => $deadlines,
            ],
            message: 'Fiscal deadlines retrieved'
        );
    }
}
