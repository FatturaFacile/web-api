<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CustomerSatisfaction\Models\SurveyResponse;

/**
 * @mixin SurveyResponse
 */
final class SurveyResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'survey_dispatch_id' => $this->survey_dispatch_id,
            'survey_template_id' => $this->survey_template_id,
            'template_code' => $this->whenLoaded('template', fn () => $this->template?->code),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->company_name ?: trim((string) $this->customer?->first_name.' '.(string) $this->customer?->last_name)),
            'survey_type' => $this->survey_type->value,
            'score' => $this->score,
            'normalized_band' => $this->normalized_band->value,
            'is_detractor' => $this->is_detractor,
            'comment_text' => $this->comment_text,
            'has_voice' => $this->voice_attachment_path !== null,
            'voice_duration_seconds' => $this->voice_duration_seconds,
            'voice_transcript' => $this->voice_transcript,
            'sentiment_score' => $this->sentiment_score === null ? null : (float) $this->sentiment_score,
            'sentiment_label' => $this->sentiment_label,
            'sentiment_topics' => $this->sentiment_topics ?? [],
            'department_code' => $this->department_code,
            'technician_id' => $this->technician_id,
            'product_line_code' => $this->product_line_code,
            'branch_code' => $this->branch_code,
            'submitted_via' => $this->submitted_via->value,
            'locale' => $this->locale,
            'initial_clicked_at' => $this->initial_clicked_at->toIso8601String(),
            'comment_updated_at' => $this->comment_updated_at?->toIso8601String(),
            'recovery_case_id' => $this->whenLoaded('recoveryCase', fn () => $this->recoveryCase?->id),
        ];
    }
}
