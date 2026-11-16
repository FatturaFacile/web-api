<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Services\TransportationDashboardService;
use OpenApi\Attributes as OA;

/**
 * Transport summary: on-time delivery, cost per km, orders by status, disputes and cost per kg.
 */
final class DashboardController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TransportationDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/transportation/dashboard', operationId: 'showTransportationDashboard', description: 'On-time delivery percent (orders delivered in the period with a delivery window, delivered by the end of it), cost per km (cost of the trips completed in the period over their kilometres), orders by status, open disputes and the over-billed amount still contested, and the average cost per kg of the delivered orders. Filter: days (default 30).', summary: 'Transport dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationDashboard')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TmsTransportOrder::class);
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json(['data' => $this->dashboard->summary($this->tenantId(), (int) ($data['days'] ?? 30))]);
    }
}
