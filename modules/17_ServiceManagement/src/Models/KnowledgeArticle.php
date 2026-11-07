<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class KnowledgeArticle extends BaseEnterpriseModel
{
    public const string VisibilityInternal = 'internal';

    public const string VisibilityPublic = 'public';

    protected $table = 'esm_knowledge_articles';

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_published' => 'boolean',
            'views_count' => 'integer',
            'helpful_count' => 'integer',
            'translations' => 'array',
            'metadata' => 'array',
            'current_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Published public articles, outside internal categories: what the customer portal may show.
     *
     * @param  Builder<KnowledgeArticle>  $query
     * @return Builder<KnowledgeArticle>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where('visibility', self::VisibilityPublic)
            ->where(fn (Builder $category) => $category->whereNull('knowledge_category_id')
                ->orWhereHas('knowledgeCategory', fn (Builder $public) => $public->where('visibility', self::VisibilityPublic)));
    }

    /**
     * Full-text search on title, summary and content, best matches first.
     *
     * @param  Builder<KnowledgeArticle>  $query
     * @return Builder<KnowledgeArticle>
     */
    public function scopeSearch(Builder $query, string $terms): Builder
    {
        $document = "to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(summary, '') || ' ' || coalesce(content, ''))";

        return $query->whereRaw("{$document} @@ websearch_to_tsquery('simple', ?)", [$terms])
            ->orderByRaw("ts_rank({$document}, websearch_to_tsquery('simple', ?)) desc", [$terms]);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<KnowledgeCategory, $this>
     */
    public function knowledgeCategory(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'knowledge_category_id');
    }

    /**
     * @return HasMany<KnowledgeArticleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeArticleVersion::class, 'article_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'knowledge_article_id');
    }

    /**
     * @return BelongsToMany<Ticket, $this>
     */
    public function linkedTickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'esm_ticket_knowledge_links', 'article_id', 'ticket_id')->withTimestamps();
    }
}
