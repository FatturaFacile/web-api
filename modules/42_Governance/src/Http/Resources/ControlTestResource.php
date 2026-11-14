<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovControlTestRun;
use Modules\Governance\Services\GovernanceMembers;

/**
 * A test of a control with its result and the evidence documents.
 *
 * @mixin GovControlTestRun
 */
final class ControlTestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'control_id' => $this->control_id,
            'tested_on' => $this->tested_on->toDateString(),
            'tester_id' => $this->tester_id,
            'tester' => app(GovernanceMembers::class)->reference($this->tester_id),
            'result' => $this->result,
            'sample_size' => $this->sample_size,
            'exceptions_found' => $this->exceptions_found,
            'notes' => $this->notes,
            'evidence_document_ids' => $this->evidence_document_ids ?? [],
            'finding_id' => $this->finding_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
