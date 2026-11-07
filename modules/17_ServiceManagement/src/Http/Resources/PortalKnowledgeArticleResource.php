<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\KnowledgeArticle;

/**
 * A public article as customers read it on the portal: no internal data. The content is left out of lists.
 *
 * @mixin KnowledgeArticle
 */
final class PortalKnowledgeArticleResource extends JsonResource
{
    public bool $withContent = false;

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
            'content' => $this->when($this->withContent, $this->content),
            'knowledge_category_id' => $this->knowledge_category_id,
            'tags' => $this->tags ?? [],
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
