<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Plm\Models\PlmChangeOrder;

/**
 * A change order with its approval flow and, when loaded, the affected items and bills of material.
 *
 * @mixin PlmChangeOrder
 */
final class ChangeOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'reason' => $this->reason,
            'change_type' => $this->change_type,
            'priority' => $this->priority,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'effective_date' => $this->effective_date?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'implemented_at' => $this->implemented_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'notes' => $this->notes,
            'approvals' => $this->whenLoaded('approvals', fn (): array => $this->approvals->map(static fn ($approval): array => [
                'id' => $approval->id,
                'approver_id' => $approval->approver_id,
                'approver_name' => $approval->relationLoaded('approver') ? $approval->approver?->name : null,
                'sequence' => $approval->sequence,
                'status' => $approval->status,
                'decided_at' => $approval->decided_at?->toIso8601String(),
                'comment' => $approval->comment,
            ])->all()),
            'affected_items' => $this->whenLoaded('affectedItems', fn (): array => $this->affectedItems->map(static fn ($affected): array => [
                'id' => $affected->id,
                'mfg_item_id' => $affected->mfg_item_id,
                'item_code' => $affected->relationLoaded('item') ? $affected->item?->item_code : null,
                'item_name' => $affected->relationLoaded('item') ? $affected->item?->name : null,
                'to_revision' => $affected->to_revision,
                'change_summary' => $affected->change_summary,
            ])->all()),
            'affected_boms' => $this->whenLoaded('affectedBoms', fn (): array => $this->affectedBoms->map(static fn ($affected): array => [
                'id' => $affected->id,
                'bom_id' => $affected->bom_id,
                'bom_number' => $affected->relationLoaded('bom') ? $affected->bom?->bom_number : null,
                'bom_name' => $affected->relationLoaded('bom') ? $affected->bom?->name : null,
                'bom_revision' => $affected->relationLoaded('bom') ? $affected->bom?->revision : null,
                'bom_status' => $affected->relationLoaded('bom') ? $affected->bom?->status : null,
                'note' => $affected->note,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
