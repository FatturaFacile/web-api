<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollWageType;

/**
 * A wage type with its contribution and tax flags.
 *
 * The same JSON as the generic PayrollRecordResource, with the fields spelled out so that the API documentation can
 * describe them: decimal columns are decimal strings, dates are Y-m-d. `is_generic` marks the shared rows without a tenant.
 *
 * @mixin PayrollWageType
 */
final class PayrollWageTypeResource extends JsonResource
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
            'kind' => $this->kind,
            'subject_to_ahv' => $this->subject_to_ahv,
            'subject_to_alv' => $this->subject_to_alv,
            'subject_to_uvg' => $this->subject_to_uvg,
            'subject_to_ktg' => $this->subject_to_ktg,
            'subject_to_bvg' => $this->subject_to_bvg,
            'subject_to_withholding_tax' => $this->subject_to_withholding_tax,
            'is_irregular' => $this->is_irregular,
            'certificate_box' => $this->certificate_box,
            'sort_order' => $this->sort_order,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_generic' => $this->resource->isGeneric(),
        ];
    }
}
