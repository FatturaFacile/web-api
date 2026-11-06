<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use Modules\Payroll\Calculation\Swiss\SwissWageTypeCatalog;
use Modules\Payroll\Models\PayrollWageType;
use Modules\Payroll\Models\SwissPayrollSettings;

/**
 * The EnsureSwissPayrollSetup of the desktop: settings and standard wage types of a Swiss company. The family
 * allowances are generic rows of the migration here.
 */
final class PayrollSetupService
{
    /**
     * @return bool whether anything was created
     */
    public function ensureSwissSetup(): bool
    {
        $changed = false;

        if (! SwissPayrollSettings::query()->exists()) {
            SwissPayrollSettings::query()->create([]);
            $changed = true;
        }

        $existing = PayrollWageType::query()->whereNotNull('tenant_id')->pluck('code')->all();

        foreach (SwissWageTypeCatalog::create() as $type) {
            if (in_array($type->code, $existing, true)) {
                continue;
            }

            PayrollWageType::query()->create([
                'code' => $type->code,
                'name' => $type->name,
                'kind' => $type->kind,
                'subject_to_ahv' => $type->subjectToAhv,
                'subject_to_alv' => $type->subjectToAlv,
                'subject_to_uvg' => $type->subjectToUvg,
                'subject_to_ktg' => $type->subjectToKtg,
                'subject_to_bvg' => $type->subjectToBvg,
                'subject_to_withholding_tax' => $type->subjectToWithholdingTax,
                'is_irregular' => $type->isIrregular,
                'certificate_box' => $type->certificateBox,
                'sort_order' => $type->sortOrder,
                'is_system' => $type->isSystem,
            ]);
            $changed = true;
        }

        return $changed;
    }
}
