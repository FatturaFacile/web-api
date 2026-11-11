<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsAuditChecklistItem;

/**
 * One requirement of an audit checklist and its result.
 *
 * @mixin QmsAuditChecklistItem
 */
final class ChecklistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audit_id' => $this->audit_id,
            'position' => $this->position,
            'clause' => $this->clause,
            'requirement' => $this->requirement,
            'result' => $this->result,
            'notes' => $this->notes,
        ];
    }
}
