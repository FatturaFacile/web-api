<?php

declare(strict_types=1);

namespace Modules\Collaboration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Collaboration\Models\CollabComment;

/**
 * A comment on a record, with its author and what the requesting member may do with it.
 *
 * @mixin CollabComment
 */
final class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'record_type' => $this->commentable_type,
            'record_id' => $this->commentable_id,
            'body' => $this->body,
            'mentions' => $this->mentioned_user_ids ?? [],
            'author' => ['id' => $this->user_id, 'name' => $this->author?->name],
            'can_edit' => $user !== null && $user->can('update', $this->resource),
            'can_delete' => $user !== null && $user->can('delete', $this->resource),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
