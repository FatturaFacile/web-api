<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\AdventureParks\Models\Ticket;
use Modules\AdventureParks\Services\ParkOverviewService;
use OpenApi\Attributes as OA;

/**
 * Lookups of the screens: enumerations, size table, park configuration, workshops, checklist keys and waiver versions.
 */
final class LookupController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ParkOverviewService $overview) {}

    #[OA\Get(path: '/api/v1/adventure-parks/lookups', operationId: 'adventureParksLookups', summary: 'Lookups of the adventure park screens', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        return $this->successResponse($this->overview->lookups());
    }
}
