<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Services\LookupService;
use OpenApi\Attributes as OA;

/**
 * Reference data of the screens (the labels of the enumerations and programs are in the frontend dictionary served by the API).
 */
final class LookupController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Get(path: '/api/v1/industrial-laundry/lookups', operationId: 'industrialLaundryLookups', summary: 'Enumerations, wash programs, defaults and customers of the industrial laundry module', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Lookups')])]
    public function lookups(LookupService $lookups): JsonResponse
    {
        Gate::authorize('viewAny', Garment::class);

        return $this->successResponse($lookups->reference($this->tenantId()));
    }
}
