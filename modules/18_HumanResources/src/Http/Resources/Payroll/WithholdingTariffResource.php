<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Modules\HumanResources\Models\Payroll\WithholdingTariffBracket;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * @mixin WithholdingTariff
 */
final class WithholdingTariffResource extends JsonResource
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
            'canton' => $this->canton,
            'tariff_code' => $this->tariff_code,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'source' => $this->source,
            'description' => $this->description,
            'brackets' => $this->whenLoaded('brackets', fn (): array => $this->brackets->map(
                static fn (WithholdingTariffBracket $bracket): array => [
                    'income_from' => Rappen::toDecimal($bracket->income_from_rappen),
                    'income_to' => $bracket->income_to_rappen !== null ? Rappen::toDecimal($bracket->income_to_rappen) : null,
                    'rate_percent' => Rappen::ppmToPercent($bracket->rate_ppm),
                    'fixed_amount' => Rappen::toDecimal($bracket->fixed_amount_rappen),
                ],
            )->values()->all()),
        ];
    }
}
