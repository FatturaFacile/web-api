<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\LeadSetting;

/**
 * @mixin LeadSetting
 */
final class LeadSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'warm_threshold' => (int) $this->warm_threshold,
            'hot_threshold' => (int) $this->hot_threshold,
            'auto_qualify_threshold' => (int) $this->auto_qualify_threshold,
            'dedup_mode' => $this->dedup_mode,
            'dedup_levenshtein_max' => (int) $this->dedup_levenshtein_max,
            'dedup_similarity_min' => (float) $this->dedup_similarity_min,
            'default_country' => $this->default_country,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
