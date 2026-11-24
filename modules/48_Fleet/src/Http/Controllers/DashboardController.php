<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Http\Controllers\Concerns\InteractsWithFleet;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Services\FleetDashboardService;
use OpenApi\Attributes as OA;

/**
 * Fleet summary: cost of ownership per vehicle, cost per km and the upcoming obligations.
 */
final class DashboardController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly FleetDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/fleet/dashboard', operationId: 'showFleetDashboard', description: 'Vehicles by status, running cost and cost per km of the fleet, total cost of ownership per vehicle, open deadlines expiring within the given days (expired ones included), overdue scheduled services and unpaid incidents. Filter: days (default 30).', summary: 'Fleet dashboard', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(name: 'days', description: 'Look-ahead window for the expiries, in days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30, minimum: 0, maximum: 365))], responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetDashboard')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FleetVehicle::class);
        $filters = $request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:365']]);

        return response()->json(['data' => $this->dashboard->summary($this->tenantId(), (int) ($filters['days'] ?? 30))]);
    }
}
