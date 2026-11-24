<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Models\Department;
use Modules\LeaveManagement\Application\Queries\GetDepartmentAbsenceGanttHandler;
use Modules\LeaveManagement\Application\Queries\GetDepartmentAbsenceGanttQuery;
use Modules\LeaveManagement\Http\Controllers\Concerns\ResolvesLeaveContext;
use Modules\LeaveManagement\Http\Requests\UpsertStaffingThresholdRequest;
use Modules\LeaveManagement\Http\Resources\DepartmentStaffingThresholdResource;
use Modules\LeaveManagement\Models\DepartmentStaffingThreshold;
use OpenApi\Attributes as OA;

/**
 * Department Absence Gantt Calendar (Calendario di Reparto) & Minimum Staffing Thresholds (Presidio Minimo).
 */
final class DepartmentCalendarController extends Controller
{
    use ApiResponse;
    use ResolvesLeaveContext;

    public function __construct(
        private readonly GetDepartmentAbsenceGanttHandler $ganttHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/leave-management/department-calendar',
        operationId: 'leaveMgmtDepartmentGanttCalendar',
        summary: 'Department absence Gantt calendar with holiday markers and daily minimum staffing threshold alerts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Department Gantt calendar')]
    )]
    public function gantt(Request $request): JsonResponse
    {
        $from = (string) $request->query('from', CarbonImmutable::now()->startOfMonth()->format('Y-m-d'));
        $to = (string) $request->query('to', CarbonImmutable::now()->endOfMonth()->format('Y-m-d'));
        $departmentId = $request->query('department_id');
        $country = (string) $request->query('country', 'CH');
        $canton = $request->query('canton_or_province_code');

        $payload = $this->ganttHandler->handle(new GetDepartmentAbsenceGanttQuery(
            tenantId: $this->tenantId(),
            fromDate: $from,
            toDate: $to,
            departmentId: is_string($departmentId) && $departmentId !== '' ? $departmentId : null,
            country: $country,
            cantonOrProvince: is_string($canton) && $canton !== '' ? $canton : null,
        ));

        return $this->successResponse($payload);
    }

    #[OA\Get(
        path: '/api/v1/leave-management/staffing-thresholds',
        operationId: 'leaveMgmtListStaffingThresholds',
        summary: 'List department minimum staffing thresholds',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Department staffing thresholds')]
    )]
    public function thresholds(): JsonResponse
    {
        $items = DepartmentStaffingThreshold::query()
            ->where('tenant_id', $this->tenantId())
            ->with('department:id,name')
            ->get();

        return $this->successResponse(DepartmentStaffingThresholdResource::collection($items));
    }

    #[OA\Put(
        path: '/api/v1/leave-management/staffing-thresholds/{departmentId}',
        operationId: 'leaveMgmtUpsertStaffingThreshold',
        summary: 'Configure minimum staffing threshold (presidio minimo) for a department',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'departmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Threshold updated')]
    )]
    public function upsertThreshold(UpsertStaffingThresholdRequest $request, string $departmentId): JsonResponse
    {
        $tenantId = $this->tenantId();
        Department::query()->where('tenant_id', $tenantId)->findOrFail($departmentId);

        $threshold = DepartmentStaffingThreshold::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'department_id' => $departmentId],
            array_merge($request->validated(), ['tenant_id' => $tenantId, 'department_id' => $departmentId])
        );

        $threshold->load('department:id,name');

        return $this->successResponse(new DepartmentStaffingThresholdResource($threshold), __('leave_management.messages.threshold_saved'));
    }
}
