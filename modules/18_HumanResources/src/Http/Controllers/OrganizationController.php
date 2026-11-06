<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\OrganizationManagementServiceInterface;
use Modules\HumanResources\Http\Resources\DepartmentResource;
use Modules\HumanResources\Http\Resources\PositionResource;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Position;
use OpenApi\Attributes as OA;

final class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationManagementServiceInterface $orgService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the departments of the active tenant ordered by code.
     */
    #[OA\Get(
        path: '/api/v1/hr/departments',
        operationId: 'getDepartmentsHr',
        description: 'Returns the departments of the active tenant ordered by code.',
        summary: 'List departments',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Departments'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesDepartmentResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function departments(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $departments = Department::where('tenant_id', $tenant->id)->orderBy('code')->get();

        return response()->json(['data' => DepartmentResource::collection($departments)]);
    }

    /**
     * Creates a department in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/hr/departments',
        operationId: 'storeDepartmentHrDepartments',
        description: 'Creates a department in the active tenant.',
        summary: 'Create department',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Departments'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'parent_id'), new OA\Property(property: 'business_unit_id'), new OA\Property(property: 'cost_center_id'), new OA\Property(property: 'manager_employee_id'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string')])),
        responses: [
            new OA\Response(response: 201, description: 'Department created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesDepartmentResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function storeDepartment(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $dept = Department::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'company_id' => $tenant->id,
            'parent_id' => $request->input('parent_id'),
            'business_unit_id' => $request->input('business_unit_id'),
            'cost_center_id' => $request->input('cost_center_id'),
            'manager_employee_id' => $request->input('manager_employee_id'),
            'code' => (string) $request->input('code'),
            'name' => (string) $request->input('name'),
            'is_active' => true,
        ]);

        return response()->json(['data' => new DepartmentResource($dept)], 201);
    }

    /**
     * Returns the positions of the active tenant ordered by code.
     */
    #[OA\Get(
        path: '/api/v1/hr/positions',
        operationId: 'getPositionsHr',
        description: 'Returns the positions of the active tenant ordered by code.',
        summary: 'List positions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Positions'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesPositionResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function positions(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $positions = Position::where('tenant_id', $tenant->id)->orderBy('code')->get();

        return response()->json(['data' => PositionResource::collection($positions)]);
    }

    /**
     * Creates a position in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/hr/positions',
        operationId: 'storePositionHrPositions',
        description: 'Creates a position in the active tenant.',
        summary: 'Create position',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Positions'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'department_id'), new OA\Property(property: 'job_grade_id'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'headcount_budget', type: 'integer', default: 1), new OA\Property(property: 'is_critical_role', type: 'boolean', default: false)])),
        responses: [
            new OA\Response(response: 201, description: 'Position created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesPositionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function storePosition(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $position = Position::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'company_id' => $tenant->id,
            'department_id' => $request->input('department_id'),
            'job_grade_id' => $request->input('job_grade_id'),
            'code' => (string) $request->input('code'),
            'title' => (string) $request->input('title'),
            'headcount_budget' => (int) $request->input('headcount_budget', 1),
            'is_critical_role' => (bool) $request->input('is_critical_role', false),
            'is_active' => true,
        ]);

        return response()->json(['data' => new PositionResource($position)], 201);
    }

    /**
     * Builds the organization chart of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/hr/org-chart',
        operationId: 'getOrgChartHr',
        description: 'Builds the organization chart of the active tenant.',
        summary: 'Get organization chart',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Organization'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function orgChart(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        return response()->json([
            'data' => $this->orgService->buildOrgChart((string) $tenant->id),
        ]);
    }

    /**
     * Assigns the successor of a position with the readiness level.
     */
    #[OA\Patch(
        path: '/api/v1/hr/positions/{positionId}/successor',
        operationId: 'assignSuccessorHrPosition',
        description: 'Assigns the successor of a position with the readiness level.',
        summary: 'Assign position successor',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Positions'],
        parameters: [
            new OA\Parameter(name: 'positionId', in: 'path', required: true, description: 'Identifier of the position', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'successor_employee_id', type: 'string'), new OA\Property(property: 'readiness_level', type: 'string', default: 'ready_1_2_years')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesPositionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function assignSuccessor(Request $request, string $positionId): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $position = Position::where('tenant_id', $tenant->id)->findOrFail($positionId);
        $actorId = (string) ($request->user()?->id ?? '');

        $updated = $this->orgService->assignSuccessor(
            $position,
            (string) $request->input('successor_employee_id'),
            (string) $request->input('readiness_level', 'ready_1_2_years'),
            $actorId
        );

        return response()->json(['data' => new PositionResource($updated)]);
    }

    /**
     * Generates the cost and payroll forecast of a period.
     */
    #[OA\Post(
        path: '/api/v1/hr/payroll-forecast',
        operationId: 'payrollForecastHr',
        description: 'Generates the cost and payroll forecast of a period.',
        summary: 'Generate payroll forecast',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Payroll Forecast'],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'period_code', type: 'string')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function payrollForecast(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $periodCode = (string) $request->input('period_code', date('Y-m'));

        $summary = $this->orgService->generatePeriodCostAndPayrollForecast((string) $tenant->id, $periodCode);

        return response()->json(['data' => $summary]);
    }
}
