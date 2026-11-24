<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

/**
 * Validation of the AllocationPeriodRequest payload; every reference is limited to the active company.
 */
final class AllocationPeriodRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return ['fiscal_year' => ['required', 'integer', 'between:2000,2100'], 'fiscal_month' => ['required', 'integer', 'between:1,12']];
    }
}
