<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsAuditFinding;

/**
 * A finding of an audit, linked to the non-conformity it raised.
 *
 * @mixin QmsAuditFinding
 */
final class FindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audit_id' => $this->audit_id,
            'checklist_item_id' => $this->checklist_item_id,
            'number' => $this->number,
            'classification' => $this->classification,
            'description' => $this->description,
            'nonconformity_id' => $this->nonconformity_id,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
