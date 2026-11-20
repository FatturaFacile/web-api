<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;

/**
 * Summary of an extraction job (the payload and boxes are served by the workbench read model).
 *
 * @mixin DocumentExtractionJob
 */
final class ExtractionJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dms_document_id' => $this->dms_document_id,
            'status' => $this->status->value,
            'source_channel' => $this->source_channel->value,
            'document_category' => $this->document_category->value,
            'extraction_method' => $this->extraction_method?->value,
            'ocr_provider_used' => $this->ocr_provider_used?->value,
            'overall_confidence_score' => (float) $this->overall_confidence_score,
            'llm_tokens_used' => $this->llm_tokens_used,
            'vendor_id' => $this->vendor_id,
            'failure_reason' => $this->failure_reason,
            'confirmed_by_user_id' => $this->confirmed_by_user_id,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'resulting_entity_type' => $this->resulting_entity_type,
            'resulting_entity_id' => $this->resulting_entity_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
