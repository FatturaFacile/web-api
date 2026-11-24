<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AssetManagement\Models\FiscalDepreciationRate;

/**
 * @mixin FiscalDepreciationRate
 */
final class FiscalDepreciationRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = (string) ($request->query('locale') ?: app()->getLocale());

        return [
            'id' => $this->id,
            'jurisdiction' => $this->jurisdiction,
            'regime_code' => $this->regime_code,
            'sector_code' => $this->sector_code,
            'sector_name' => $this->sector_name,
            'category_code' => $this->category_code,
            'category_name' => $this->category_name,
            'localized_category_name' => $this->localizedCategoryName($locale),
            'declining_rate_percent' => $this->declining_rate_percent !== null ? (string) $this->declining_rate_percent : null,
            'linear_rate_percent' => $this->linear_rate_percent !== null ? (string) $this->linear_rate_percent : null,
            'first_year_half_rate' => (bool) $this->first_year_half_rate,
            'immediate_expensing_allowed' => (bool) $this->immediate_expensing_allowed,
            'immediate_expensing_threshold' => $this->immediate_expensing_threshold !== null ? (string) $this->immediate_expensing_threshold : null,
            'legal_reference' => $this->legal_reference,
            'translations' => $this->translations,
        ];
    }
}
