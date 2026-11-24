<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\DateRangeRequest;
use Modules\Manufacturing\Services\ProductionKpiService;
use OpenApi\Attributes as OA;

/**
 * Production KPIs from recorded data.
 */
final class ManufacturingKpiController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly ProductionKpiService $kpis) {}

    /**
     * KPIs of a window.
     */
    #[OA\Get(path: '/api/v1/manufacturing/kpis', operationId: 'getMfgKpis', description: 'Lead time and on-time share of the orders completed, scrap rate of the confirmations, OEE inputs per work centre (operating, downtime and ideal minutes, good and total units, availability, performance, quality, OEE when all exist) and variances of the orders closed in the window. Ratios without data are null.', summary: 'Production KPIs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing KPIs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgFrom'), new OA\Parameter(ref: '#/components/parameters/MfgTo'), new OA\Parameter(name: 'work_center_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'KPIs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgKpiSummary')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function __invoke(DateRangeRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->kpis->summary($this->tenantId(), (string) $data['from'], (string) $data['to'], $data['work_center_id'] ?? null)]);
    }
}
