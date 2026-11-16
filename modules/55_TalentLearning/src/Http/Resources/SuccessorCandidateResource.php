<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\SuccessionCandidate;

/**
 * A successor candidate.
 *
 * @mixin SuccessionCandidate
 */
final class SuccessorCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'succession_position_id' => $this->succession_position_id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee'),
            'readiness' => $this->readiness,
            'risk_of_loss' => $this->risk_of_loss,
            'potential' => $this->potential,
            'ranking' => $this->ranking,
            'development_plan' => $this->development_plan,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
