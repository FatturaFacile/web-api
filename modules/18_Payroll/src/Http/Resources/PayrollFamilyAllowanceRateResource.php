<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;

/**
 * The family allowances of a canton from a date.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin PayrollFamilyAllowanceRate
 */
final class PayrollFamilyAllowanceRateResource extends JsonResource
{
    use FormatsDateAttributes;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'canton' => $this->canton,
            'valid_from' => $this->dateOnly('valid_from'),
            'child_allowance' => $this->child_allowance,
            'education_allowance' => $this->education_allowance,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
        ];
    }
}
