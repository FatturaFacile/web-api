<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollRun;

/**
 * A monthly payroll run (status Draft, Calculated, Approved or Final); `payslips` and `events` only when loaded.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin PayrollRun
 */
final class PayrollRunResource extends JsonResource
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
            'year' => $this->year,
            'month' => $this->month,
            'country' => $this->country,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_date' => $this->dateOnly('payment_date'),
            'calculated_at' => $this->calculated_at,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'finalized_at' => $this->finalized_at,
            'finalized_by' => $this->finalized_by,
            'reopened_at' => $this->reopened_at,
            'reopened_by' => $this->reopened_by,
            'reopen_reason' => $this->reopen_reason,
            'reopen_count' => $this->reopen_count,
            'employees_count' => $this->whenHas('employees_count'),
            'total_gross' => $this->whenHas('total_gross'),
            'total_net' => $this->whenHas('total_net'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
            'payslips' => PayrollPayslipResource::collection($this->whenLoaded('payslips')),
            'events' => PayrollRunEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
