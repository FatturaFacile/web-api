<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;

/**
 * @mixin SurveyTemplate
 */
final class SurveyTemplateResource extends JsonResource
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
            'survey_type' => $this->survey_type->value,
            'scale' => ['min' => $this->survey_type->min(), 'max' => $this->survey_type->max()],
            'trigger_event' => $this->trigger_event->value,
            'trigger_filters' => $this->trigger_filters ?? [],
            'priority' => $this->priority,
            'channels' => $this->channels,
            'delay_hours' => $this->delay_hours,
            'cooldown_days' => $this->cooldown_days,
            'detractor_threshold' => $this->detractor_threshold,
            'effective_detractor_threshold' => $this->effectiveDetractorThreshold(),
            'sla_hours' => $this->sla_hours,
            'allow_voice_comment' => $this->allow_voice_comment,
            'messages' => $this->messages ?? [],
            'follow_up_config' => $this->follow_up_config ?? [],
            'dimensions' => $this->dimensions ?? [],
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
