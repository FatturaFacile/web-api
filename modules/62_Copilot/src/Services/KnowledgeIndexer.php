<?php

declare(strict_types=1);

namespace Modules\Copilot\Services;

use Illuminate\Support\Facades\DB;
use Modules\Copilot\Embeddings\EmbeddingProviderManager;
use Modules\Copilot\Models\CopilotKnowledgeChunk;
use Throwable;

/**
 * Splits documents into passages, embeds them and stores them as knowledge chunks. Indexing the same document again
 * updates its chunks in place (the key is tenant, source, locale, title and section), so it is idempotent. Embeddings are
 * computed before the transaction: no provider call runs inside DB::transaction().
 */
final class KnowledgeIndexer
{
    private const int ChunkCharacters = 1200;

    public function __construct(private readonly EmbeddingProviderManager $embeddings) {}

    /**
     * @param  list<array{title: string, content: string, jurisdiction: string, locale: string, source_type?: string, section_ref?: string|null, badge?: string|null, metadata?: array<string, mixed>}>  $documents
     * @param  string|null  $tenantId  null stores global documents, embedded with the local provider (no tenant consent involved)
     * @return int number of chunks written
     */
    public function index(?string $tenantId, array $documents): int
    {
        $prepared = [];

        foreach ($documents as $document) {
            $parts = $this->split($document['content']);

            foreach ($parts as $index => $part) {
                $section = $document['section_ref'] ?? null;

                if (count($parts) > 1) {
                    $section = trim(($section ?? '').' (part '.($index + 1).')');
                }

                [$vector, $provider] = $this->embed($tenantId, $document['title']."\n".($document['section_ref'] ?? '')."\n".$part);

                $prepared[] = [
                    'key' => [
                        'source_type' => $document['source_type'] ?? ($tenantId === null ? 'regulatory' : 'tenant_document'),
                        'locale' => $document['locale'],
                        'document_title' => $document['title'],
                        'section_ref' => $section,
                    ],
                    'values' => [
                        'jurisdiction' => $document['jurisdiction'],
                        'content_text' => $part,
                        'embedding_vector' => $vector,
                        'metadata_json' => ($document['metadata'] ?? []) + array_filter([
                            'badge' => $document['badge'] ?? null,
                            'embedding_provider' => $provider,
                        ], static fn (mixed $value): bool => $value !== null),
                    ],
                ];
            }
        }

        DB::transaction(function () use ($tenantId, $prepared): void {
            foreach ($prepared as $entry) {
                if ($tenantId === null) {
                    CopilotKnowledgeChunk::updateOrCreateGlobal($entry['key'], $entry['values']);
                } else {
                    CopilotKnowledgeChunk::query()->updateOrCreate($entry['key'] + ['tenant_id' => $tenantId], $entry['values']);
                }
            }
        });

        return count($prepared);
    }

    /**
     * @return array{0: list<float>|null, 1: string|null}
     */
    private function embed(?string $tenantId, string $text): array
    {
        try {
            $provider = $this->embeddings->forTenant($tenantId ?? '', $tenantId === null ? 'local' : null);

            return [$provider->embed($text), $provider->name()];
        } catch (Throwable) {
            // Stored without a vector: the retriever ranks such a passage by lexical overlap.
            return [null, null];
        }
    }

    /**
     * @return list<string>
     */
    private function split(string $content): array
    {
        $content = trim($content);

        if (mb_strlen($content) <= self::ChunkCharacters) {
            return [$content];
        }

        $parts = [];
        $current = '';

        foreach (preg_split('/\n{2,}/', $content) ?: [] as $paragraph) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) > self::ChunkCharacters) {
                $parts[] = $current;
                $current = '';
            }

            while (mb_strlen($paragraph) > self::ChunkCharacters) {
                $parts[] = mb_substr($paragraph, 0, self::ChunkCharacters);
                $paragraph = mb_substr($paragraph, self::ChunkCharacters);
            }

            $current = trim($current."\n\n".$paragraph);
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
