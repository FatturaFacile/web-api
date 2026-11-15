<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BusinessIntelligence\Models\BiDashboard;

/**
 * A dashboard, with its widgets when they are loaded.
 *
 * @mixin BiDashboard
 */
final class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'owner_name' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'is_owner' => (string) $this->owner_id === (string) $request->user()?->id,
            'can_edit' => (bool) $request->user()?->can('update', $this->resource),
            'name' => $this->name,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'filters' => $this->filters ?? (object) [],
            'widgets' => WidgetResource::collection($this->whenLoaded('widgets')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
