<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BusinessIntelligence\Models\BiSavedQuery;

/**
 * A saved query. `access` says how the caller reaches it: owner, edit or view.
 *
 * @mixin BiSavedQuery
 */
final class SavedQueryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userId = (string) $request->user()?->id;

        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'owner_name' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'is_owner' => (string) $this->owner_id === $userId,
            'name' => $this->name,
            'description' => $this->description,
            'dataset' => $this->dataset,
            'spec' => $this->spec,
            'calculated_measures' => $this->calculated_measures ?? [],
            'pivot' => $this->pivot,
            'visibility' => $this->visibility,
            'snapshot_frequency' => $this->snapshot_frequency,
            'next_snapshot_on' => $this->next_snapshot_on?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
