<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Models\DmsOcrJob;

/**
 * API representation of an OCR job. The text is returned only when asked for with `include_text=1`.
 *
 * @mixin DmsOcrJob
 */
final class OcrJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'document_id' => (string) $this->document_id,
            'document_version_id' => (string) $this->document_version_id,
            'status' => $this->status->value,
            'engine' => $this->engine,
            'page_count' => $this->page_count,
            'confidence' => $this->confidence,
            'failure_reason' => $this->failure_reason,
            'attempts' => $this->attempts,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'text_content' => $this->when($request->boolean('include_text'), fn (): ?string => $this->text_content),
        ];
    }
}
