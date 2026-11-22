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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Server-sent events of the machine states.
     */
    #[OA\Get(path: '/api/v1/manufacturing/machines/stream', operationId: 'streamMfgMachineStates', description: 'Server-sent events (text/event-stream): a "machines" event with the live machine states right away and again whenever they change, comment heartbeats in between. The stream ends after "seconds" (default 50, at most 55) so proxies do not cut it; the client reconnects.', summary: 'Machine state stream', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing KPIs'],
        parameters: [new OA\Parameter(name: 'seconds', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 55))],
        responses: [new OA\Response(response: 200, description: 'Event stream', content: new OA\MediaType(mediaType: 'text/event-stream')), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function machineStream(Request $request): StreamedResponse
    {
        $tenantId = $this->tenantId();
        $seconds = max(1, min(55, (int) $request->query('seconds', '50')));
        $interval = max(1, min(10, (int) $request->query('interval', '3')));

        return response()->stream(function () use ($tenantId, $seconds, $interval): void {
            $deadline = microtime(true) + $seconds;
            $last = null;
            echo "retry: 3000\n\n";

            do {
                $payload = json_encode($this->cockpit->machines($tenantId), JSON_THROW_ON_ERROR);
                if ($payload !== $last) {
                    echo "event: machines\ndata: {$payload}\n\n";
                    $last = $payload;
                } else {
                    echo ": heartbeat\n\n";
                }
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if (connection_aborted() !== 0 || microtime(true) + $interval > $deadline) {
                    break;
                }
                sleep($interval);
            } while (true);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
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
