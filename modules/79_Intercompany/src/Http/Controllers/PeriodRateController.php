<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Intercompany\Enums\RateType;
use Modules\Intercompany\Http\Requests\StorePeriodRateRequest;
use Modules\Intercompany\Http\Resources\PeriodRateResource;
use Modules\Intercompany\Models\PeriodRate;
use Modules\Intercompany\Services\PeriodRateService;
use Modules\Intercompany\Support\GroupScope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closing, average and historical rates fixed for a period end of a group.
 */
final class PeriodRateController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GroupScope $scope,
        private readonly PeriodRateService $rates,
    ) {}

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/period-rates', operationId: 'intercompanyListPeriodRates', summary: 'Period rates of a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Period rates')])]
    public function index(Request $request, string $groupId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $group = $this->scope->group($groupId);

        return $this->successResponse(PeriodRateResource::collection($this->rates->list($request->user(), $group, $request->query('currency'), $request->query('rate_type'), $request->query('period_end'))), 'Period rates');
    }

    #[OA\Post(path: '/api/v1/intercompany/groups/{groupId}/period-rates', operationId: 'intercompanyStorePeriodRate', summary: 'Fix a period rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Period rate')])]
    public function store(StorePeriodRateRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $data = $request->validated();
        $rate = $this->rates->upsert($request->user(), $group, $data['currency'], RateType::from($data['rate_type']), CarbonImmutable::parse($data['period_end']), (string) $data['rate'], $data['note'] ?? null);

        return $this->successResponse(new PeriodRateResource($rate), 'Period rate saved', Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/intercompany/period-rates/{id}', operationId: 'intercompanyDeletePeriodRate', summary: 'Remove a period rate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.manage');
        $this->rates->delete($request->user(), PeriodRate::query()->findOrFail($id));

        return $this->successResponse(null, 'Period rate removed');
    }
}
