<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Services\ManufacturingCockpitService;
use Modules\Manufacturing\Services\ProductionGenealogyService;
use OpenApi\Attributes as OA;

/**
 * Plant manager cockpit and production genealogy read models.
 */
final class ManufacturingCockpitController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly ManufacturingCockpitService $cockpit, private readonly ProductionGenealogyService $genealogy) {}

    /**
     * The aggregated cockpit.
     */
    #[OA\Get(path: '/api/v1/manufacturing/cockpit', operationId: 'getMfgCockpit', description: 'Open orders by MTO/MTS/ATO, live machine states and alerts, OEE and bottlenecks, MRP exceptions of the latest run and live WIP valuation against standard cost.', summary: 'Manufacturing cockpit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing KPIs'],
        parameters: [new OA\Parameter(name: 'window_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 365))],
        responses: [new OA\Response(response: 200, description: 'Cockpit'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function cockpit(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->query('window_days', '30')));

        return response()->json(['data' => $this->cockpit->overview($this->tenantId(), $days)]);
    }

    /**
     * Genealogy of a production order.
     */
    #[OA\Get(path: '/api/v1/manufacturing/production-orders/{id}/genealogy', operationId: 'getMfgProductionOrderGenealogy', description: 'Output lots traced backward to supplier lots and forward to deliveries, with the machines, operators and machine state events of the operations.', summary: 'Production genealogy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Genealogy'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function genealogy(string $id): JsonResponse
    {
        /** @var MfgProductionOrder $order */
        $order = $this->owned(MfgProductionOrder::class, $id);

        return response()->json(['data' => $this->genealogy->forOrder($order)]);
    }
}
