<?php

declare(strict_types=1);

namespace Modules\Plm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Plm\Models\PlmItemRevision;

/**
 * A revision of a manufacturing item.
 *
 * @mixin PlmItemRevision
 */
final class RevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mfg_item_id' => $this->mfg_item_id,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->item === null ? null : ['id' => $this->item->id, 'item_code' => $this->item->item_code, 'name' => $this->item->name]),
            'revision' => $this->revision,
            'status' => $this->status,
            'change_summary' => $this->change_summary,
            'change_order_id' => $this->change_order_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'released_at' => $this->released_at?->toIso8601String(),
            'obsoleted_at' => $this->obsoleted_at?->toIso8601String(),
        ];
    }
}
