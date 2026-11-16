<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovObligation;
use Modules\Governance\Services\GovernanceMembers;

/**
 * An entry of the compliance calendar with its due date, overdue and due-soon flags.
 *
 * @mixin GovObligation
 */
final class ObligationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $members = app(GovernanceMembers::class);

        return [
            'id' => $this->id,
            'template_key' => $this->template_key,
            'framework' => $this->framework,
            'jurisdiction' => $this->jurisdiction,
            'title' => $this->title,
            'description' => $this->description,
            'legal_reference' => $this->legal_reference,
            'recurrence' => $this->recurrence,
            'due_date' => $this->due_date->toDateString(),
            'reminder_days' => $this->reminder_days,
            'owner_id' => $this->owner_id,
            'owner' => $members->reference($this->owner_id),
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'is_due_soon' => $this->isDueSoon(),
            'last_completed_at' => $this->last_completed_at?->toDateString(),
            'last_completed_by' => $members->reference($this->last_completed_by),
            'evidence_document_id' => $this->evidence_document_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
