<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\DairyCheeseManufacturing\Services\LookupService;
use OpenApi\Attributes as OA;

/**
 * Enumerations and form lists of the dairy module.
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use DairyControllerSupport;

    public function __construct(private readonly LookupService $lookups) {}

    #[OA\Get(path: '/api/v1/dairy-cheese/lookups', operationId: 'dairyCheeseLookups', summary: 'Enumerations, tanks, breeders, racks and pricing grids', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Dairy Cheese'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function index(): JsonResponse
    {
        $this->view();

        return $this->successResponse($this->lookups->all());
    }
}
