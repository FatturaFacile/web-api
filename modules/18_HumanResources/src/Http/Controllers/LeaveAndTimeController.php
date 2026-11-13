<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\DTOs\RecordTimesheetDTO;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Http\Requests\SubmitLeaveRequestFormRequest;
use Modules\HumanResources\Http\Requests\SubmitTimesheetRequest;
use Modules\HumanResources\Http\Resources\LeaveBalanceResource;
use Modules\HumanResources\Http\Resources\LeaveRequestResource;
use Modules\HumanResources\Http\Resources\TimesheetResource;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\Timesheet;

final class LeaveAndTimeController extends Controller
{
    public function __construct(
        private readonly LeaveAndTimeServiceInterface $leaveAndTimeService,
        private readonly TenantContext $tenantContext
    ) {}

    public function leaveTypes(): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();

        return JsonResource::collection(LeaveType::where('tenant_id', $tenant->id)->orderBy('code')->gridPaginate(RequestedPerPage::resolve(200)));
    }

    public function leaveBalances(Request $request): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();
        $query = LeaveBalance::where('tenant_id', $tenant->id)->with('employee:id,full_name');

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        return LeaveBalanceResource::collection($query->orderByDesc('fiscal_year')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    public function leaveRequests(): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();

        return LeaveRequestResource::collection(LeaveRequest::where('tenant_id', $tenant->id)->with(['employee:id,full_name', 'leaveType:id,name'])->orderByDesc('created_at')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    public function submitLeave(SubmitLeaveRequestFormRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        try {
            $leaveRequest = $this->leaveAndTimeService->submitLeaveRequest(new SubmitLeaveRequestDTO(
                tenantId: (string) $tenant->id,
                employeeId: (string) $request->input('employee_id'),
                leaveTypeId: (string) $request->input('leave_type_id'),
                startDate: (string) $request->input('start_date'),
                endDate: (string) $request->input('end_date'),
                totalHours: (float) $request->input('total_hours'),
                reason: $request->input('reason'),
                medicalCertificateRef: $request->input('medical_certificate_ref')
            ), $actorId);
        } catch (InvalidArgumentException $exception) {
            throw new HumanResourcesException($exception->getMessage());
        }

        return response()->json(['data' => new LeaveRequestResource($leaveRequest)], 201);
    }

    public function approveLeave(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $leaveRequest = LeaveRequest::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->leaveAndTimeService->approveLeaveRequest($leaveRequest, $actorId);

        return response()->json(['data' => new LeaveRequestResource($updated)]);
    }

    public function rejectLeave(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $leaveRequest = LeaveRequest::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->leaveAndTimeService->rejectLeaveRequest(
            $leaveRequest,
            (string) $request->input('reason', 'Rejected by manager'),
            $actorId
        );

        return response()->json(['data' => new LeaveRequestResource($updated)]);
    }

    public function runAccruals(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $fiscalYear = (int) $request->input('fiscal_year', (int) date('Y'));
        $month = $request->integer('month');
        $processed = $this->leaveAndTimeService->runMonthlyAccruals((string) $tenant->id, $fiscalYear, $month >= 1 && $month <= 12 ? $month : null);

        return response()->json(['data' => ['balances_accrued' => $processed]]);
    }

    public function timesheets(): AnonymousResourceCollection
    {
        $tenant = $this->tenantContext->getTenant();

        return TimesheetResource::collection(Timesheet::where('tenant_id', $tenant->id)->with('employee:id,full_name')->orderByDesc('work_date')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    public function submitTimesheet(SubmitTimesheetRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $timesheet = $this->leaveAndTimeService->submitTimesheet(new RecordTimesheetDTO(
            tenantId: (string) $tenant->id,
            employeeId: (string) $request->input('employee_id'),
            workDate: (string) $request->input('work_date'),
            regularHours: (float) $request->input('regular_hours'),
            overtimeHours: (float) $request->input('overtime_hours', 0.0),
            billableHours: (float) $request->input('billable_hours', 0.0),
            projectId: $request->input('project_id'),
            taskId: $request->input('task_id'),
            costCenterId: $request->input('cost_center_id'),
            description: $request->input('description')
        ), $actorId);

        return response()->json(['data' => new TimesheetResource($timesheet)], 201);
    }

    public function approveTimesheet(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $timesheet = Timesheet::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->leaveAndTimeService->approveTimesheet($timesheet, $actorId);

        return response()->json(['data' => new TimesheetResource($updated)]);
    }
}
