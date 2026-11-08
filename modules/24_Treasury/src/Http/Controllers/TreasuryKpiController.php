<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Services\TreasuryKpiService;
use OpenApi\Attributes as OA;

/**
 * Treasury KPIs: DSO, DPO, cash conversion cycle, overdue items and liquidity.
 */
final class TreasuryKpiController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TreasuryKpiService $kpis) {}

    /**
     * The KPIs of one currency at a date.
     */
    #[OA\Get(
        path: '/api/v1/treasury/kpis',
        operationId: 'getTreasuryKpis',
        description: 'DSO (open receivables / gross sales of the period x days), DPO (open payables / gross purchases x days), cash conversion cycle (DSO - DPO; inventory days are not derived, includes_inventory = false), overdue receivables and payables, cash, net liquidity and quick ratio of one currency. A ratio without a denominator is null.',
        summary: 'Treasury KPIs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Liquidity'],
        parameters: [
            new OA\Parameter(name: 'as_of_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'period_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 365, maximum: 730, minimum: 7)),
            new OA\Parameter(name: 'currency', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 3, minLength: 3)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'KPIs', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
            'period_days' => ['nullable', 'integer', 'min:7', 'max:730'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
        ]);
        $asOf = isset($data['as_of_date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['as_of_date']) : CarbonImmutable::today();

        return $this->successResponse($this->kpis->kpis((string) $tenantContext->getTenantId(), $asOf, (int) ($data['period_days'] ?? 365), $data['currency'] ?? null), 'Treasury KPIs');
    }
}
