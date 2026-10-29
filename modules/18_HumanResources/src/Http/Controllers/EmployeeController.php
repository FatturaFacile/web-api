<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\DTOs\HireEmployeeDTO;
use Modules\HumanResources\DTOs\PromoteEmployeeDTO;
use Modules\HumanResources\DTOs\TerminateEmployeeDTO;
use Modules\HumanResources\DTOs\TransferEmployeeDTO;
use Modules\HumanResources\Http\Requests\HireEmployeeRequest;
use Modules\HumanResources\Http\Requests\PromoteEmployeeRequest;
use Modules\HumanResources\Http\Requests\TerminateEmployeeRequest;
use Modules\HumanResources\Http\Requests\TransferEmployeeRequest;
use Modules\HumanResources\Http\Resources\EmployeeResource;
use Modules\HumanResources\Models\Employee;

final class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeLifecycleServiceInterface $lifecycleService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Employee::where('tenant_id', $tenant->id);

        if ($request->has('employment_status')) {
            $query->where('employment_status', $request->query('employment_status'));
        }

        if ($request->has('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        $employees = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => EmployeeResource::collection($employees),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    public function store(HireEmployeeRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $employee = $this->lifecycleService->hireEmployee(new HireEmployeeDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            firstName: (string) $request->input('first_name'),
            lastName: (string) $request->input('last_name'),
            workEmail: (string) $request->input('work_email'),
            baseAnnualSalary: (float) $request->input('base_annual_salary'),
            hireDate: $request->input('hire_date'),
            employeeNumber: $request->input('employee_number'),
            userId: $request->input('user_id'),
            departmentId: $request->input('department_id'),
            teamId: $request->input('team_id'),
            businessUnitId: $request->input('business_unit_id'),
            costCenterId: $request->input('cost_center_id'),
            positionId: $request->input('position_id'),
            jobGradeId: $request->input('job_grade_id'),
            managerId: $request->input('manager_id'),
            employmentType: (string) $request->input('employment_type', 'full_time'),
            contractType: (string) $request->input('contract_type', 'permanent'),
            weeklyHours: (float) $request->input('weekly_hours', 40.0),
            probationMonths: (int) $request->input('probation_months', 3)
        ), $actorId);

        return response()->json(['data' => new EmployeeResource($employee)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new EmployeeResource($employee)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);

        $employee->update($request->only([
            'first_name', 'last_name', 'phone', 'personal_email', 'emergency_contact', 'metadata',
        ]));

        return response()->json(['data' => new EmployeeResource($employee->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);
        $employee->delete();

        return response()->json(['message' => 'Employee record archived']);
    }

    public function completeProbation(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->lifecycleService->completeProbation(
            $employee,
            $request->input('confirmation_date'),
            $actorId
        );

        return response()->json(['data' => new EmployeeResource($updated)]);
    }

    public function promote(PromoteEmployeeRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $event = $this->lifecycleService->promoteEmployee(new PromoteEmployeeDTO(
            employeeId: (string) $employee->id,
            newPositionId: $request->input('new_position_id'),
            newJobGradeId: $request->input('new_job_grade_id'),
            newBaseAnnualSalary: (float) $request->input('new_base_annual_salary'),
            effectiveDate: $request->input('effective_date'),
            reason: (string) $request->input('reason', 'Merit and performance promotion')
        ), $actorId);

        return response()->json(['data' => $event], 201);
    }

    public function transfer(TransferEmployeeRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $event = $this->lifecycleService->transferEmployee(new TransferEmployeeDTO(
            employeeId: (string) $employee->id,
            toDepartmentId: $request->input('to_department_id'),
            toTeamId: $request->input('to_team_id'),
            toBusinessUnitId: $request->input('to_business_unit_id'),
            toCostCenterId: $request->input('to_cost_center_id'),
            toPositionId: $request->input('to_position_id'),
            toManagerId: $request->input('to_manager_id'),
            effectiveDate: $request->input('effective_date'),
            reason: (string) $request->input('reason', 'Organizational reassignment')
        ), $actorId);

        return response()->json(['data' => $event], 201);
    }

    public function terminate(TerminateEmployeeRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->lifecycleService->terminateEmployee(new TerminateEmployeeDTO(
            employeeId: (string) $employee->id,
            terminationDate: (string) $request->input('termination_date'),
            reason: (string) $request->input('reason'),
            startOffboarding: (bool) $request->input('start_offboarding', true)
        ), $actorId);

        return response()->json(['data' => new EmployeeResource($updated)]);
    }

    public function rehire(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->lifecycleService->rehireEmployee(
            $employee,
            (float) $request->input('new_base_annual_salary', (float) $employee->base_annual_salary),
            $request->input('rehire_date'),
            $actorId
        );

        return response()->json(['data' => new EmployeeResource($updated)]);
    }
}
