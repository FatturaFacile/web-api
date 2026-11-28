<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\AdventureParks\Http\Controllers\Concerns\ResolvesParkContext;
use Modules\AdventureParks\Http\Requests\DateQueryRequest;
use Modules\AdventureParks\Services\ParkOverviewService;
use OpenApi\Attributes as OA;

/**
 * Dashboard and slot availability of a day.
 */
final class DashboardController extends Controller
{
    use ApiResponse;
    use ResolvesParkContext;

    public function __construct(private readonly ParkOverviewService $overview) {}

    #[OA\Get(path: '/api/v1/adventure-parks/dashboard', operationId: 'adventureParksDashboard', summary: 'Dashboard of a day: slots, visitors on course, alerts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function index(DateQueryRequest $request): JsonResponse
    {
        return $this->successResponse($this->overview->dashboard($this->day($request)));
    }

    #[OA\Get(path: '/api/v1/adventure-parks/slots/availability', operationId: 'adventureParksSlotAvailability', summary: 'Stock per size of every slot of a day', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Availability')])]
    public function availability(DateQueryRequest $request): JsonResponse
    {
        return $this->successResponse($this->overview->availability($this->day($request)));
    }
}
