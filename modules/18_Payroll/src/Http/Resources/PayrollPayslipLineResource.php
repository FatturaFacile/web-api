<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayslipLine;

/**
 * A line of a payslip.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin PayslipLine
 */
final class PayrollPayslipLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'payslip_id' => $this->payslip_id,
            'wage_type_code' => $this->wage_type_code,
            'description' => $this->description,
            'kind' => $this->kind,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'certificate_box' => $this->certificate_box,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
        ];
    }
}
