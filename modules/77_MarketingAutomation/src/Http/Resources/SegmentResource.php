<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\Segment;

/**
 * @mixin Segment
 */
final class SegmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'criteria' => $this->criteria ?? [],
            'auto_refresh_hourly' => $this->auto_refresh_hourly,
            'eligible_contacts_count' => $this->eligible_contacts_count,
            'excluded_insolvent_count' => $this->excluded_insolvent_count,
            'excluded_no_consent_count' => $this->excluded_no_consent_count,
            'rfm_breakdown' => $this->rfm_breakdown ?? [],
            'last_evaluated_at' => $this->last_evaluated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
