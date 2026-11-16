<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\WageType;

/**
 * @mixin WageType
 */
final class WageTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'is_system_default' => $this->tenant_id === null,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category->value,
            'is_ahv_subject' => $this->is_ahv_subject,
            'is_alv_subject' => $this->is_alv_subject,
            'is_bvg_subject' => $this->is_bvg_subject,
            'is_uvg_subject' => $this->is_uvg_subject,
            'is_qst_subject' => $this->is_qst_subject,
            'elm_code' => $this->elm_code,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
