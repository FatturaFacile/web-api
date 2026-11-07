<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'access_roles' => $this->access_roles ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
