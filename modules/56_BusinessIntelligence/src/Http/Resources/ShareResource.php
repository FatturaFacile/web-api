<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BusinessIntelligence\Models\BiShare;

/**
 * A share of a dashboard or saved query with a member.
 *
 * @mixin BiShare
 */
final class ShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'permission' => $this->permission,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
