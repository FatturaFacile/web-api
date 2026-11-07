<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\CannedResponse;

/**
 * @mixin CannedResponse
 */
final class CannedResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'body' => $this->body,
            'is_shared' => (bool) $this->is_shared,
            'owner_user_id' => $this->owner_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
