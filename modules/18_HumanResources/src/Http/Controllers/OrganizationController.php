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

final class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationManagementServiceInterface $orgService,
        private readonly TenantContext $tenantContext
    ) {}

    public function departments(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $departments = Department::where('tenant_id', $tenant->id)->orderBy('code')->get();

        return response()->json(['data' => DepartmentResource::collection($departments)]);
    }

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

    public function positions(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $positions = Position::where('tenant_id', $tenant->id)->orderBy('code')->get();

        return response()->json(['data' => PositionResource::collection($positions)]);
    }

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

    public function orgChart(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();

        return response()->json([
            'data' => $this->orgService->buildOrgChart((string) $tenant->id),
        ]);
    }

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

    public function payrollForecast(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $periodCode = (string) $request->input('period_code', date('Y-m'));

        $summary = $this->orgService->generatePeriodCostAndPayrollForecast((string) $tenant->id, $periodCode);

        return response()->json(['data' => $summary]);
    }
}
