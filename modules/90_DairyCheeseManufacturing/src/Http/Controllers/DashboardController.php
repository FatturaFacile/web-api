<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Application\Queries\GetDairyDashboardHandler;
use Modules\DairyCheeseManufacturing\Application\Queries\GetDairyDashboardQuery;
use Modules\DairyCheeseManufacturing\Http\Requests\ViewRequest;
use Modules\DairyCheeseManufacturing\Http\Resources\CareLogResource;
use OpenApi\Attributes as OA;

/**
 * Dairy dashboard.
 */
final class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly GetDairyDashboardHandler $dashboard) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/dashboard', operationId: 'dairyCheeseDashboard', summary: 'Dairy dashboard: intake, quality trends, cellar occupancy, care tasks and filing deadlines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Dashboard')])]
    public function index(ViewRequest $request): JsonResponse
    {
        $data = $this->dashboard->handle(new GetDairyDashboardQuery($request->validated('date')));
        $data['upcoming_care'] = CareLogResource::collection($data['upcoming_care'])->resolve();

        return $this->successResponse($data);
    }
}
