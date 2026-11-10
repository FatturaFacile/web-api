<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\CollectiveAgreement;

/**
 * A collective agreement; `levels` only when loaded.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin CollectiveAgreement
 */
final class PayrollCollectiveAgreementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'code' => $this->code,
            'name' => $this->name,
            'weekly_hours' => $this->weekly_hours,
            'installments' => $this->installments,
            'fourteenth_month' => $this->fourteenth_month,
            'employer_inps_rate_percent' => $this->employer_inps_rate_percent,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
            'levels' => PayrollCollectiveAgreementLevelResource::collection($this->whenLoaded('levels')),
        ];
    }
}
