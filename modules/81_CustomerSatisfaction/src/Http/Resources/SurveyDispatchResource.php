<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;

/**
 * The token is never exposed: it is the secret of the public link.
 *
 * @mixin SurveyDispatch
 */
final class SurveyDispatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey_template_id' => $this->survey_template_id,
            'template_code' => $this->whenLoaded('template', fn () => $this->template?->code),
            'survey_type' => $this->whenLoaded('template', fn () => $this->template?->survey_type->value),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->company_name ?: trim((string) $this->customer?->first_name.' '.(string) $this->customer?->last_name)),
            'trigger_event' => $this->trigger_event->value,
            'source_entity_type' => $this->source_entity_type,
            'source_entity_id' => $this->source_entity_id,
            'channel' => $this->channel->value,
            'locale' => $this->locale,
            'status' => $this->status->value,
            'throttle_reason' => $this->throttle_reason?->value,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'metadata' => $this->metadata ?? [],
            'response' => $this->whenLoaded('response', fn () => $this->response === null ? null : new SurveyResponseResource($this->response)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
