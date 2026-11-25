<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\StoreAudit;

/**
 * @mixin StoreAudit
 */
final class AuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'store_code' => $this->store?->code,
            'store_name' => $this->store?->name,
            'template_id' => $this->template_id,
            'template_code' => $this->template?->code,
            'auditor_id' => $this->auditor_id,
            'status' => $this->status->value,
            'audited_on' => $this->audited_on->toDateString(),
            'score' => $this->score,
            'max_score' => $this->max_score,
            'score_percent' => $this->score_percent,
            'passed' => $this->passed,
            'notes' => $this->notes,
            'answers' => $this->answers ?? [],
            'photos' => array_map(static fn (array $photo): array => ['id' => $photo['id'], 'original_name' => $photo['original_name'], 'mime' => $photo['mime'], 'size' => $photo['size'], 'criterion_code' => $photo['criterion_code'] ?? null, 'caption' => $photo['caption'] ?? null, 'taken_at' => $photo['taken_at']], $this->photos ?? []),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'actions' => AuditActionResource::collection($this->whenLoaded('actions')),
        ];
    }
}
