<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Collaboration\Models\CollabTag;

/**
 * A shared tag. The usage count is present when the list loaded it.
 *
 * @mixin CollabTag
 */
final class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'usage_count' => $this->taggables_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
