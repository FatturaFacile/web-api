<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Domain\Support\LocalizedText;
use Modules\FranchiseNetwork\Models\FranchiseFormula;

/**
 * @mixin FranchiseFormula
 */
final class FormulaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => LocalizedText::pick($this->labels, app()->getLocale()),
            'labels' => $this->labels,
            'description' => LocalizedText::pick($this->descriptions, app()->getLocale()),
            'descriptions' => $this->descriptions,
            'is_preset' => $this->is_preset,
            'royalty_tiers' => $this->royalty_tiers,
            'marketing_tiers' => $this->marketing_tiers,
            'minimum_guarantee' => $this->minimum_guarantee,
            'startup_months' => $this->startup_months,
            'startup_mode' => $this->startup_mode->value,
            'startup_reduction_percent' => $this->startup_reduction_percent,
            'ramp_start_percent' => $this->ramp_start_percent,
            'startup_applies_to_marketing' => $this->startup_applies_to_marketing,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
        ];
    }
}
