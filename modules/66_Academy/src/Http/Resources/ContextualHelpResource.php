<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Academy\Models\ContextualHelpArticle;

/**
 * A help article with the language it was actually served in (after the fallback).
 */
final class ContextualHelpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{article: ContextualHelpArticle, locale: string} $payload */
        $payload = $this->resource;
        $article = $payload['article'];

        return [
            'id' => (string) $article->id,
            'route_pattern' => $article->route_pattern,
            'locale' => $payload['locale'],
            'title' => $article->title,
            'body_markdown' => $article->body_markdown,
            'field_tooltips' => (object) ($article->field_tooltips_json ?? []),
        ];
    }
}
