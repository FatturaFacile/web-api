<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\PayslipLine;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * @mixin PayslipLine
 */
final class PayslipLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'line_type' => $this->line_type->value,
            'side' => $this->side->value,
            'wage_type_id' => $this->wage_type_id,
            'base' => Rappen::toDecimal($this->base_rappen),
            'rate_percent' => $this->rate_ppm !== null ? Rappen::ppmToPercent($this->rate_ppm) : null,
            'amount' => Rappen::toDecimal($this->amount_rappen),
            'amount_rappen' => $this->amount_rappen,
        ];
    }
}
