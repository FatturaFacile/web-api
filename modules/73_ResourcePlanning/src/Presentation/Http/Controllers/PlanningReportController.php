<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\ResourcePlanning\Application\Handlers\GetBillableUtilizationReportHandler;
use Modules\ResourcePlanning\Application\Queries\GetBillableUtilizationReportQuery;
use Modules\ResourcePlanning\Application\Services\PlanningSettingsService;
use Modules\ResourcePlanning\Presentation\Http\Controllers\Concerns\MapsResourcePlanningErrors;
use Modules\ResourcePlanning\Presentation\Http\Requests\LocalHolidayRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\PlanningWindowRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\UtilizationTargetRequest;
use Modules\ResourcePlanning\Presentation\Http\Resources\LocalHolidayResource;
use Modules\ResourcePlanning\Presentation\Http\Resources\UtilizationTargetResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billable utilization analytics, its targets and the holiday calendars behind the capacity (Mod 079).
 */
final class PlanningReportController extends Controller
{
    use ApiResponse;
    use MapsResourcePlanningErrors;

    public function __construct(private readonly TenantContext $tenantContext, private readonly PlanningSettingsService $settings) {}

    #[OA\Get(path: '/api/v1/resource-planning/reports/billable-utilization', operationId: 'getBillableUtilizationReport', summary: 'Billable utilization, target against actual', description: 'By resource, department or cost centre: committed billable hours (actual), committed plus tentative (forecast), the target and the gap, over the net available hours.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The report')])]
    public function utilization(PlanningWindowRequest $request, GetBillableUtilizationReportHandler $handler): JsonResponse
    {
        $from = $this->date($request->validated('from'), CarbonImmutable::today()->startOfMonth());

        return $this->successResponse($handler->handle(new GetBillableUtilizationReportQuery(
            (string) $this->tenantContext->getTenantId(),
            $from,
            $this->date($request->validated('to'), $from->endOfMonth()->startOfDay()),
            (string) $request->validated('group_by', GetBillableUtilizationReportQuery::ByResource),
        )));
    }

    #[OA\Get(path: '/api/v1/resource-planning/utilization-targets', operationId: 'listUtilizationTargets', summary: 'Billable utilization targets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The targets')])]
    public function targets(): JsonResponse
    {
        return $this->successResponse(UtilizationTargetResource::collection($this->settings->targets()));
    }

    #[OA\Put(path: '/api/v1/resource-planning/utilization-targets', operationId: 'setUtilizationTarget', summary: 'Set a billable utilization target', description: 'For a department, a cost centre or, with neither, the whole tenant.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The target')])]
    public function setTarget(UtilizationTargetRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(new UtilizationTargetResource($this->settings->setTarget($data['department_id'] ?? null, $data['cost_center_id'] ?? null, (float) $data['target_percent'])), 'Target saved');
    }

    #[OA\Get(path: '/api/v1/resource-planning/holidays', operationId: 'listPlanningHolidays', summary: 'Holidays of a working location', description: 'National, Swiss cantonal or Italian regional holidays, the patron saint of the municipality and the local holidays of the tenant.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The holidays of the year')])]
    public function holidays(PlanningWindowRequest $request): JsonResponse
    {
        return $this->successResponse($this->settings->resolvedHolidays(
            strtoupper((string) $request->validated('country', 'CH')),
            $request->validated('region'),
            $request->validated('municipality'),
            $this->settings->resolvedYear($request->validated('year') === null ? null : (int) $request->validated('year')),
        ));
    }

    #[OA\Get(path: '/api/v1/resource-planning/local-holidays', operationId: 'listLocalHolidays', summary: 'Local holidays', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The local holidays')])]
    public function localHolidays(): JsonResponse
    {
        return $this->successResponse(LocalHolidayResource::collection($this->settings->localHolidays()));
    }

    #[OA\Post(path: '/api/v1/resource-planning/local-holidays', operationId: 'createLocalHoliday', summary: 'Add a local holiday', description: 'A municipal patron saint, a cantonal or regional feast; without a year it recurs every year.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 201, description: 'The holiday')])]
    public function storeLocalHoliday(LocalHolidayRequest $request): JsonResponse
    {
        return $this->successResponse(new LocalHolidayResource($this->settings->addLocalHoliday($request->validated())), 'Local holiday added', Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/resource-planning/local-holidays/{id}', operationId: 'deleteLocalHoliday', summary: 'Remove a local holiday', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 204, description: 'Removed'), new OA\Response(response: 404, description: 'Unknown holiday')])]
    public function destroyLocalHoliday(string $id): JsonResponse
    {
        return $this->guarded(function () use ($id): JsonResponse {
            $this->settings->removeLocalHoliday($id);

            return response()->json(null, Response::HTTP_NO_CONTENT);
        });
    }
}
