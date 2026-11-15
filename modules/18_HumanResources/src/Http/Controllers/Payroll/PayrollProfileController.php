<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\HumanResources\Actions\UpsertPayrollProfileAction;
use Modules\HumanResources\DTOs\Payroll\UpsertPayrollProfileDTO;
use Modules\HumanResources\Http\Requests\Payroll\UpsertPayrollProfileRequest;
use Modules\HumanResources\Http\Resources\Payroll\PayrollProfileResource;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Payroll\PayrollProfile;

final class PayrollProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(string $id): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $employee = Employee::query()->where('tenant_id', $tenantId)->findOrFail($id);

        $profile = PayrollProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->authorize('view', $profile);

        return $this->successResponse(new PayrollProfileResource($profile), 'Payroll profile retrieved successfully');
    }

    public function upsert(UpsertPayrollProfileRequest $request, string $id, UpsertPayrollProfileAction $action): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $employee = Employee::query()->where('tenant_id', $tenantId)->findOrFail($id);

        $existing = PayrollProfile::query()->where('tenant_id', $tenantId)->where('employee_id', $employee->id)->first();

        if ($existing !== null) {
            $this->authorize('update', $existing);
        }

        $profile = $action->execute(new UpsertPayrollProfileDTO(
            tenantId: $tenantId,
            companyId: $employee->company_id !== null ? (string) $employee->company_id : null,
            employeeId: (string) $employee->id,
            ahvNumber: $request->filled('ahv_number') ? $request->string('ahv_number')->value() : null,
            workCanton: $request->string('work_canton')->value(),
            residenceCanton: $request->filled('residence_canton') ? $request->string('residence_canton')->value() : null,
            residenceCountry: $request->filled('residence_country') ? $request->string('residence_country')->value() : 'CH',
            residenceMunicipalityBfs: $request->filled('residence_municipality_bfs') ? $request->string('residence_municipality_bfs')->value() : null,
            permitType: $request->filled('permit_type') ? $request->string('permit_type')->value() : null,
            civilStatus: $request->filled('civil_status') ? $request->string('civil_status')->value() : 'single',
            childrenCount: $request->integer('children_count'),
            churchTax: $request->boolean('church_tax'),
            qstTariffCode: $request->filled('qst_tariff_code') ? $request->string('qst_tariff_code')->value() : null,
            qstCanton: $request->filled('qst_canton') ? $request->string('qst_canton')->value() : null,
            bvgInsured: $request->boolean('bvg_insured', true),
            bvgPlanCode: $request->filled('bvg_plan_code') ? $request->string('bvg_plan_code')->value() : null,
            uvgClass: $request->filled('uvg_class') ? $request->string('uvg_class')->value() : null,
            ktgInsured: $request->boolean('ktg_insured'),
            metadata: (array) $request->input('metadata', []),
        ));

        return $this->successResponse(new PayrollProfileResource($profile), 'Payroll profile saved');
    }
}
