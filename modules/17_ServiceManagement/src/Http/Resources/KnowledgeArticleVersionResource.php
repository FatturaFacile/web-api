<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\KnowledgeArticleVersion;

/**
 * @mixin KnowledgeArticleVersion
 */
final class KnowledgeArticleVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'version' => (int) $this->version,
            'title' => $this->title,
            'summary' => $this->summary,
            'content' => $this->content,
            'author_user_id' => $this->author_user_id,
            'change_note' => $this->change_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
