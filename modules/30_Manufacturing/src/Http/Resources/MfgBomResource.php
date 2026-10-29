<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgBom;

/**
 * @mixin MfgBom
 */
final class MfgBomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bom_number' => $this->bom_number,
            'mfg_item_id' => $this->mfg_item_id,
            'name' => $this->name,
            'revision' => $this->revision,
            'version' => $this->version,
            'bom_type' => $this->bom_type,
            'status' => $this->status,
            'base_quantity' => (float) $this->base_quantity,
            'uom' => $this->uom,
            'is_locked' => (bool) $this->is_locked,
            'components_count' => $this->components()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
