<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\JourneyStep;

/**
 * @mixin JourneyStep
 */
final class JourneyStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'step_key' => $this->step_key,
            'type' => $this->type,
            'config' => $this->config ?? new \stdClass,
            'next_step_key' => $this->next_step_key,
            'true_step_key' => $this->true_step_key,
            'false_step_key' => $this->false_step_key,
            'is_entry' => $this->is_entry,
            'pos_x' => $this->pos_x,
            'pos_y' => $this->pos_y,
        ];
    }
}
