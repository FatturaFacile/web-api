<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Facility\Models\FacilityRequestComment;

/**
 * A comment on a service request.
 *
 * @mixin FacilityRequestComment
 */
final class RequestCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_request_id' => $this->service_request_id,
            'author_id' => $this->author_id,
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : ['id' => $this->author->id, 'name' => $this->author->name]),
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
