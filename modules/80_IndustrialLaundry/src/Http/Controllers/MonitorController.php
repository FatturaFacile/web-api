<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IndustrialLaundry\Application\Commands\AssignGarmentToLockerCommand;
use Modules\IndustrialLaundry\Application\Commands\AssignGarmentToLockerHandler;
use Modules\IndustrialLaundry\Application\Commands\ResolvePpeWashLimitAlertCommand;
use Modules\IndustrialLaundry\Application\Commands\ResolvePpeWashLimitAlertHandler;
use Modules\IndustrialLaundry\Application\Queries\GetDormantGarmentsHandler;
use Modules\IndustrialLaundry\Application\Queries\GetDormantGarmentsQuery;
use Modules\IndustrialLaundry\Application\Queries\GetLockerAssignmentsHandler;
use Modules\IndustrialLaundry\Application\Queries\GetLockerAssignmentsQuery;
use Modules\IndustrialLaundry\Application\Queries\GetParStockDashboardHandler;
use Modules\IndustrialLaundry\Application\Queries\GetParStockDashboardQuery;
use Modules\IndustrialLaundry\Application\Queries\GetPpeWashLimitAlertsHandler;
use Modules\IndustrialLaundry\Application\Queries\GetPpeWashLimitAlertsQuery;
use Modules\IndustrialLaundry\Http\Controllers\Concerns\InteractsWithLaundry;
use Modules\IndustrialLaundry\Http\Requests\AssignLockerRequest;
use Modules\IndustrialLaundry\Http\Requests\ListFiltersRequest;
use Modules\IndustrialLaundry\Http\Requests\ResolvePpeAlertRequest;
use Modules\IndustrialLaundry\Http\Resources\GarmentResource;
use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Models\ParContract;
use OpenApi\Attributes as OA;

/**
 * The monitors of the laundry floor: par stock against circulating stock, dormant garments, the PPE wash limit and the
 * garment -> employee -> locker assignments.
 */
final class MonitorController extends Controller
{
    use ApiResponse;
    use InteractsWithLaundry;

    #[OA\Get(path: '/api/v1/industrial-laundry/par-stock', operationId: 'industrialLaundryParStock', summary: 'Par level against circulating stock per customer, with the weight and the billing preview of the month', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Par stock dashboard')])]
    public function parStock(ListFiltersRequest $request, GetParStockDashboardHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ParContract::class);

        return $this->successResponse($handler->handle(new GetParStockDashboardQuery(
            segment: $request->input('segment'),
            customerId: $request->input('customer_id'),
            asOf: $request->input('as_of'),
            withPreview: $request->has('with_preview') ? $request->boolean('with_preview') : true,
        )), $this->message('listed'));
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/dormant-garments', operationId: 'industrialLaundryDormantGarments', summary: 'Garments at the customer that were not sent to the wash for more than the threshold (60 days by default)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Dormant garments')])]
    public function dormant(ListFiltersRequest $request, GetDormantGarmentsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', Garment::class);

        return $this->successResponse($handler->handle(new GetDormantGarmentsQuery(
            customerId: $request->input('customer_id'),
            days: $request->filled('days') ? (int) $request->input('days') : null,
            asOf: $request->input('as_of'),
        )), $this->message('listed'));
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/ppe-alerts', operationId: 'industrialLaundryPpeAlerts', summary: 'PPE garments near or at their certified wash limit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'PPE wash limit alerts')])]
    public function ppeAlerts(ListFiltersRequest $request, GetPpeWashLimitAlertsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', Garment::class);

        return $this->successResponse($handler->handle(new GetPpeWashLimitAlertsQuery($request->input('customer_id'), $request->boolean('limit_reached_only'))), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/garments/{id}/ppe-decision', operationId: 'industrialLaundryPpeDecision', summary: 'Replace, mend or condemn a PPE garment at its wash limit, or complete its mending', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Decision applied')])]
    public function ppeDecision(ResolvePpeAlertRequest $request, string $id, ResolvePpeWashLimitAlertHandler $handler): JsonResponse
    {
        Gate::authorize('update', Garment::query()->findOrFail($id));

        $data = $request->validated();
        $garment = $handler->handle(new ResolvePpeWashLimitAlertCommand($id, (string) $data['action'], $data['replacement_epc'] ?? null, $data['reason'] ?? null, $this->userId()));

        return $this->successResponse(new GarmentResource($garment), $this->message('ppe_resolved'));
    }

    #[OA\Get(path: '/api/v1/industrial-laundry/lockers', operationId: 'industrialLaundryLockers', summary: 'Garment, employee and locker assignments of the workwear customers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], responses: [new OA\Response(response: 200, description: 'Assignments grouped by employee')])]
    public function lockers(ListFiltersRequest $request, GetLockerAssignmentsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', Garment::class);

        return $this->successResponse($handler->handle(new GetLockerAssignmentsQuery($request->input('customer_id'), $request->input('q'))), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/industrial-laundry/garments/{id}/locker', operationId: 'industrialLaundryAssignLocker', summary: 'Assign a nominal garment to an employee and a locker, or release it', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Industrial Laundry'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Garment assigned or released')])]
    public function assignLocker(AssignLockerRequest $request, string $id, AssignGarmentToLockerHandler $handler): JsonResponse
    {
        Gate::authorize('update', Garment::query()->findOrFail($id));

        $data = $request->validated();
        $garment = $handler->handle(new AssignGarmentToLockerCommand($id, $data['employee_name'] ?? null, $data['locker_code'] ?? null, $data['customer_id'] ?? null, $this->userId()));

        return $this->successResponse(new GarmentResource($garment), $this->message('assigned'));
    }
}
