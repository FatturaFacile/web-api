<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollRunEvent;

/**
 * An entry of the immutable history of a run.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin PayrollRunEvent
 */
final class PayrollRunEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'payroll_run_id' => $this->payroll_run_id,
            'action' => $this->action,
            'reason' => $this->reason,
            'totals' => $this->totals,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'occurred_at' => $this->occurred_at,
            'created_at' => $this->created_at,
            'is_generic' => $this->resource->isGeneric(),
        ];
    }
}
