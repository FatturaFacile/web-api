<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\SuccessionPosition;
use Modules\TalentLearning\Services\SuccessionService;

/**
 * A critical position with its successor bench.
 *
 * @mixin SuccessionPosition
 */
final class SuccessionPositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position_id' => $this->position_id,
            'title' => $this->title,
            'incumbent_employee_id' => $this->incumbent_employee_id,
            'incumbent' => $this->whenLoaded('incumbent'),
            'criticality' => $this->criticality,
            'vacancy_risk' => $this->vacancy_risk,
            'status' => $this->status,
            'notes' => $this->notes,
            'coverage' => $this->whenLoaded('candidates', fn (): string => app(SuccessionService::class)->coverage($this->candidates)),
            'candidates' => $this->whenLoaded('candidates', fn () => SuccessorCandidateResource::collection($this->candidates)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
