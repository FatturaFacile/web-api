<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\KnowledgeArticle;
use Modules\ServiceManagement\Models\KnowledgeArticleVersion;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketKnowledgeLink;

/**
 * Knowledge base articles with a version saved on every change of their text, and their links to tickets.
 */
final readonly class KnowledgeBaseService
{
    /**
     * Fields of the text: changing one of them saves a new version.
     *
     * @var list<string>
     */
    private const array VersionedFields = ['title', 'summary', 'content'];

    public function __construct(private TicketActivityLog $activityLog) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $authorUserId): KnowledgeArticle
    {
        return DB::transaction(function () use ($tenantId, $data, $authorUserId): KnowledgeArticle {
            $published = (bool) ($data['is_published'] ?? false);
            $changeNote = $data['change_note'] ?? 'Created';
            unset($data['change_note']);

            /** @var KnowledgeArticle $article */
            $article = KnowledgeArticle::query()->create([
                ...$data,
                'tenant_id' => $tenantId,
                'article_number' => 'KB-'.strtoupper(Str::random(8)),
                'is_published' => $published,
                'published_at' => $published ? now() : null,
                'visibility' => $data['visibility'] ?? KnowledgeArticle::VisibilityInternal,
                'current_version' => 1,
                'author_user_id' => $authorUserId,
            ]);

            $this->saveVersion($article, $authorUserId, $changeNote);

            return $article;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(KnowledgeArticle $article, array $data, ?string $authorUserId): KnowledgeArticle
    {
        return DB::transaction(function () use ($article, $data, $authorUserId): KnowledgeArticle {
            $article = KnowledgeArticle::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $textChanged = false;

            foreach (self::VersionedFields as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== $article->getAttribute($field)) {
                    $textChanged = true;
                }
            }

            $changeNote = $data['change_note'] ?? null;
            unset($data['change_note']);

            if (array_key_exists('is_published', $data) && (bool) $data['is_published'] && ! $article->is_published) {
                $data['published_at'] = now();
            }

            $article->fill($data);

            if ($textChanged) {
                $article->current_version = (int) $article->current_version + 1;
            }

            $article->save();

            if ($textChanged) {
                $this->saveVersion($article, $authorUserId, $changeNote);
            }

            return $article->refresh();
        });
    }

    /**
     * Puts the text of an earlier version back, as a new version.
     */
    public function restore(KnowledgeArticle $article, int $version, ?string $authorUserId): KnowledgeArticle
    {
        $saved = KnowledgeArticleVersion::query()->where('article_id', $article->id)->where('version', $version)->first()
            ?? throw new HelpdeskRuleViolation("Version {$version} does not exist.");

        return $this->update($article, ['title' => $saved->title, 'summary' => $saved->summary, 'content' => $saved->content, 'change_note' => "Restored version {$version}"], $authorUserId);
    }

    public function link(Ticket $ticket, KnowledgeArticle $article, ?string $userId): TicketKnowledgeLink
    {
        if ($ticket->tenant_id !== $article->tenant_id) {
            throw new HelpdeskRuleViolation('The article belongs to another company.');
        }

        $link = TicketKnowledgeLink::query()->firstOrCreate(
            ['ticket_id' => $ticket->id, 'article_id' => $article->id],
            ['tenant_id' => $ticket->tenant_id, 'linked_by_user_id' => $userId],
        );

        if ($link->wasRecentlyCreated) {
            $this->activityLog->record($ticket, 'knowledge_linked', null, $article->article_number, ['article_id' => $article->id], $userId);
        }

        return $link;
    }

    public function unlink(Ticket $ticket, string $articleId, ?string $userId): void
    {
        $removed = TicketKnowledgeLink::query()->where('ticket_id', $ticket->id)->where('article_id', $articleId)->forceDelete();

        if ($removed > 0) {
            $this->activityLog->record($ticket, 'knowledge_unlinked', $articleId, null, [], $userId);
        }
    }

    private function saveVersion(KnowledgeArticle $article, ?string $authorUserId, ?string $changeNote): void
    {
        KnowledgeArticleVersion::query()->create([
            'tenant_id' => $article->tenant_id,
            'article_id' => $article->id,
            'version' => $article->current_version,
            'title' => $article->title,
            'summary' => $article->summary,
            'content' => $article->content,
            'author_user_id' => $authorUserId,
            'change_note' => $changeNote === null ? null : mb_substr($changeNote, 0, 500),
        ]);
    }
}
