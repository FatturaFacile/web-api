<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\KnowledgeArticle;

/**
 * A knowledge base article as staff see it.
 *
 * @mixin KnowledgeArticle
 */
final class KnowledgeArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_number' => $this->article_number,
            'title' => $this->title,
            'summary' => $this->summary,
            'content' => $this->content,
            'knowledge_category_id' => $this->knowledge_category_id,
            'category_id' => $this->category_id,
            'visibility' => $this->visibility,
            'is_published' => (bool) $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'current_version' => (int) $this->current_version,
            'tags' => $this->tags ?? [],
            'views_count' => (int) $this->views_count,
            'helpful_count' => (int) $this->helpful_count,
            'author_user_id' => $this->author_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
