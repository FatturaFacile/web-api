<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\AuditAction;

/**
 * @mixin AuditAction
 */
final class AuditActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audit_id' => $this->audit_id,
            'store_id' => $this->store_id,
            'criterion_code' => $this->criterion_code,
            'description' => $this->description,
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status->value,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completion_note' => $this->completion_note,
            'overdue' => $this->status->value === 'open' && $this->due_date->isPast(),
        ];
    }
}
