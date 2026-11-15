<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;

/**
 * @mixin PayrollRateSet
 */
final class RateSetResource extends JsonResource
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
            'year' => $this->year,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_active' => $this->is_active,
            'rates' => $this->rates,
            'notes' => $this->notes,
        ];
    }
}
