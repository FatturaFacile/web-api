<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\Campaign;

/**
 * @mixin Campaign
 */
final class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'channel' => $this->channel,
            'trigger_type' => $this->trigger_type,
            'segment_id' => $this->segment_id,
            'sender_name' => $this->sender_name,
            'sender_email' => $this->sender_email,
            'sender_postal_address' => $this->sender_postal_address,
            'provider' => $this->provider,
            'template_mjml_json' => $this->template_mjml_json ?? new \stdClass,
            'ab_test_split_variant_json' => $this->ab_test_split_variant_json,
            'utm_campaign_code' => $this->utm_campaign_code,
            'attribution_model' => $this->attribution_model,
            'attribution_window_days' => $this->attribution_window_days,
            'currency' => $this->currency,
            'campaign_cost' => (float) $this->campaign_cost,
            'coupon_code' => $this->coupon_code,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'steps' => JourneyStepResource::collection($this->whenLoaded('steps')),
            'metric' => $this->whenLoaded('metric', fn (): ?array => $this->metric === null ? null : (new CampaignMetricResource($this->metric))->toArray($request)),
        ];
    }
}
