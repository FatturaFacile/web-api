<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\AdventureParks\Http\Controllers\Concerns\ResolvesParkContext;
use Modules\AdventureParks\Http\Requests\DailyCheckRequest;
use Modules\AdventureParks\Http\Requests\DateQueryRequest;
use Modules\AdventureParks\Http\Resources\DailyCheckResource;
use Modules\AdventureParks\Services\PpeRegistryService;
use OpenApi\Attributes as OA;

/**
 * Daily opening check of the courses (UNI EN 15567-2).
 */
final class DailyCheckController extends Controller
{
    use ApiResponse;
    use ResolvesParkContext;

    public function __construct(private readonly PpeRegistryService $registry) {}

    #[OA\Get(path: '/api/v1/adventure-parks/courses/daily-opening-check', operationId: 'adventureParksDailyCheckShow', summary: 'Daily opening check of a day (null when not done)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], parameters: [new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'Daily check or null')])]
    public function show(DateQueryRequest $request): JsonResponse
    {
        $check = $this->registry->dailyCheckFor($this->day($request));

        return $this->successResponse($check === null ? null : new DailyCheckResource($check));
    }

    #[OA\Post(path: '/api/v1/adventure-parks/courses/daily-opening-check', operationId: 'adventureParksDailyCheckStore', summary: 'Sign the daily opening check (fails when an item is not ok)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Adventure Parks'], responses: [new OA\Response(response: 201, description: 'Daily check recorded')])]
    public function store(DailyCheckRequest $request): JsonResponse
    {
        return $this->successResponse(new DailyCheckResource($this->registry->recordDailyCheck($request->validated(), $this->userId($request))), $this->message('daily_check_recorded'), 201);
    }
}
