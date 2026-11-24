<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\MarketingConsent;

/**
 * @mixin MarketingConsent
 */
final class ConsentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'contact_id' => $this->contact_id,
            'marketing_opt_in' => $this->marketing_opt_in,
            'profiling_opt_in' => $this->profiling_opt_in,
            'soft_opt_in_eligible' => $this->soft_opt_in_eligible,
            'double_opt_in_confirmed' => $this->double_opt_in_confirmed,
            'channel_preferences' => $this->channel_preferences ?? new \stdClass,
            'locale' => $this->locale,
            'unsubscribed_at' => $this->unsubscribed_at?->toIso8601String(),
        ];
    }
}
