<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
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
use OpenApi\Attributes as OA;

final class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeLifecycleServiceInterface $lifecycleService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of employees of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/hr/employees',
        operationId: 'listHrEmployees',
        description: 'Returns the paginated list of employees of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List employees',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'employment_status', in: 'query', required: false, description: 'Filter by employment status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'department_id', in: 'query', required: false, description: 'Filter by department', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of employees', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesEmployeeResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

        $employees = $query->orderByDesc('created_at')->gridPaginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => EmployeeResource::collection($employees),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    /**
     * Creates a new employee in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees',
        operationId: 'createHrEmployee',
        description: 'Creates a new employee in the active tenant.',
        summary: 'Create employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesHireEmployeeRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Employee created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Returns a single employee of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/hr/employees/{id}',
        operationId: 'getHrEmployee',
        description: 'Returns a single employee of the active tenant.',
        summary: 'Get employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new EmployeeResource($employee)]);
    }

    /**
     * Updates an existing employee of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/hr/employees/{id}',
        operationId: 'updateHrEmployee',
        description: 'Updates an existing employee of the active tenant.',
        summary: 'Update employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'first_name'), new OA\Property(property: 'last_name'), new OA\Property(property: 'phone'), new OA\Property(property: 'personal_email'), new OA\Property(property: 'emergency_contact'), new OA\Property(property: 'metadata')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);

        $employee->update($request->only([
            'first_name', 'last_name', 'phone', 'personal_email', 'emergency_contact', 'metadata',
        ]));

        return response()->json(['data' => new EmployeeResource($employee->fresh())]);
    }

    /**
     * Deletes a employee of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/hr/employees/{id}',
        operationId: 'deleteHrEmployee',
        description: 'Deletes a employee of the active tenant.',
        summary: 'Delete employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $employee = Employee::where('tenant_id', $tenant->id)->findOrFail($id);
        $employee->delete();

        return response()->json(['message' => 'Employee record archived']);
    }

    /**
     * Confirms an employee at the end of the probation period.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees/{id}/complete-probation',
        operationId: 'completeProbationHrEmployee',
        description: 'Confirms an employee at the end of the probation period.',
        summary: 'Complete employee probation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/HrCompleteProbationRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Promotes an employee to a new position, job grade and salary.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees/{id}/promote',
        operationId: 'promoteHrEmployee',
        description: 'Promotes an employee to a new position, job grade and salary.',
        summary: 'Promote employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesPromoteEmployeeRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Employee created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeLifecycleEventModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Transfers an employee to another department, team or business unit.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees/{id}/transfer',
        operationId: 'transferHrEmployee',
        description: 'Transfers an employee to another department, team or business unit.',
        summary: 'Transfer employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesTransferEmployeeRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Employee created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeLifecycleEventModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Terminates an employee at the given date, recording the reason.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees/{id}/terminate',
        operationId: 'terminateHrEmployee',
        description: 'Terminates an employee at the given date, recording the reason.',
        summary: 'Terminate employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesTerminateEmployeeRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Rehires a former employee at the given date and salary.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees/{id}/rehire',
        operationId: 'rehireHrEmployee',
        description: 'Rehires a former employee at the given date and salary.',
        summary: 'Rehire employee',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Employees'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the employee', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'new_base_annual_salary', type: 'number'), new OA\Property(property: 'rehire_date')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesEmployeeResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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
