<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HumanResources\DTOs\Payroll\UpsertPayrollProfileDTO;
use Modules\HumanResources\Models\Payroll\PayrollProfile;
use Modules\HumanResources\Services\Payroll\AhvNumberValidator;

/**
 * Creates or replaces the Swiss payroll profile of an employee (one profile per employee and tenant).
 */
final readonly class UpsertPayrollProfileAction
{
    public function execute(UpsertPayrollProfileDTO $dto): PayrollProfile
    {
        return DB::transaction(function () use ($dto): PayrollProfile {
            $profile = PayrollProfile::withTrashed()
                ->where('tenant_id', $dto->tenantId)
                ->where('employee_id', $dto->employeeId)
                ->first();

            if ($profile === null) {
                $profile = new PayrollProfile([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $dto->tenantId,
                    'employee_id' => $dto->employeeId,
                ]);
            } elseif ($profile->trashed()) {
                $profile->restore();
            }

            $profile->fill([
                'company_id' => $dto->companyId,
                'ahv_number' => $dto->ahvNumber !== null ? AhvNumberValidator::normalize($dto->ahvNumber) : null,
                'work_canton' => strtoupper($dto->workCanton),
                'residence_canton' => $dto->residenceCanton !== null ? strtoupper($dto->residenceCanton) : null,
                'residence_country' => strtoupper($dto->residenceCountry),
                'residence_municipality_bfs' => $dto->residenceMunicipalityBfs,
                'permit_type' => $dto->permitType !== null ? strtoupper($dto->permitType) : null,
                'civil_status' => $dto->civilStatus,
                'children_count' => $dto->childrenCount,
                'church_tax' => $dto->churchTax,
                'qst_tariff_code' => $dto->qstTariffCode !== null ? strtoupper($dto->qstTariffCode) : null,
                'qst_canton' => $dto->qstCanton !== null ? strtoupper($dto->qstCanton) : null,
                'bvg_insured' => $dto->bvgInsured,
                'bvg_plan_code' => $dto->bvgPlanCode,
                'uvg_class' => $dto->uvgClass,
                'ktg_insured' => $dto->ktgInsured,
                'metadata' => $dto->metadata,
            ]);
            $profile->save();

            return $profile->refresh();
        });
    }
}
