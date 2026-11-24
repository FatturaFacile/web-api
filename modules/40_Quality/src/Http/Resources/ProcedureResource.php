<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsProcedure;

/**
 * A document-controlled procedure; the document is referenced by id.
 *
 * @mixin QmsProcedure
 */
final class ProcedureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'version' => $this->version,
            'title' => $this->title,
            'description' => $this->description,
            'document_id' => $this->document_id,
            'owner_id' => $this->owner_id,
            'author_id' => $this->author_id,
            'status' => $this->status,
            'effective_date' => $this->effective_date?->toDateString(),
            'review_due_date' => $this->review_due_date?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejection_reason' => $this->rejection_reason,
            'workflow_instance_id' => $this->workflow_instance_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
