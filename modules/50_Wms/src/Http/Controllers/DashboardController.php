<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Services\WmsDashboardService;
use OpenApi\Attributes as OA;

/**
 * Warehouse summary: task throughput, open work, short picks, dock utilization and pending recommendations.
 */
final class DashboardController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly WmsDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/wms/dashboard', operationId: 'showWmsDashboard', description: 'Tasks completed per day and by type over the last days (default 14), open tasks by status and type, the short rate of picks, the utilization of the active dock doors (booked hours over the operating hours of the period), pending slotting recommendations and open waves and cross-docks. Filters: days, warehouse_id, operating_hours (per day, default 16).', summary: 'Warehouse dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'operating_hours', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDashboard')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WmsTask::class);
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'warehouse_id' => ['nullable', 'uuid'],
            'operating_hours' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        return response()->json(['data' => $this->dashboard->summary($this->tenantId(), (int) ($data['days'] ?? 14), $data['warehouse_id'] ?? null, (int) ($data['operating_hours'] ?? 16))]);
    }
}
