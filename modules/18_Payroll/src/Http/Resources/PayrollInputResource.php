<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollInput;

/**
 * A variable input of an employee for a month.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin PayrollInput
 */
final class PayrollInputResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_id' => $this->employee_id,
            'year' => $this->year,
            'month' => $this->month,
            'wage_type_code' => $this->wage_type_code,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'note' => $this->note,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
        ];
    }
}
