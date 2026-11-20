<?php

declare(strict_types=1);

namespace Modules\Copilot\Services;

use Modules\Copilot\Embeddings\EmbeddingProviderManager;
use Modules\Copilot\Models\CopilotKnowledgeChunk;
use Throwable;

/**
 * Retrieval of the knowledge passages for a question: the chunks of the tenant plus the global regulatory ones, narrowed to
 * the jurisdiction and language of the locale, ranked by cosine similarity (computed here, so it runs the same on SQLite and
 * PostgreSQL) with a lexical overlap fallback for chunks that carry no comparable vector.
 */
final class KnowledgeRetriever
{
    private const float MinimumScore = 0.12;

    public function __construct(private readonly EmbeddingProviderManager $embeddings) {}

    /**
     * @return list<array{chunk: CopilotKnowledgeChunk, score: float}>
     */
    public function retrieve(string $tenantId, string $query, string $locale, ?int $topK = null): array
    {
        $topK ??= (int) config('copilot.limits.rag_top_k', 5);
        [$language, $jurisdictions] = $this->parseLocale($locale);

        $candidates = $this->candidates($tenantId, $jurisdictions, $language);

        if ($candidates === []) {
            $candidates = $this->candidates($tenantId, $jurisdictions, null);
        }

        if ($candidates === []) {
            return [];
        }

        $providerName = null;
        $queryVector = null;

        try {
            $provider = $this->embeddings->forTenant($tenantId);
            $queryVector = $provider->embed($query);
            $providerName = $provider->name();
        } catch (Throwable) {
            // No usable embedding provider: the lexical overlap alone ranks the passages.
        }

        $queryTokens = $this->tokens($query);
        $scored = [];

        foreach ($candidates as $chunk) {
            $vector = $chunk->embedding_vector;
            $comparable = $queryVector !== null && is_array($vector) && $vector !== []
                && ($chunk->metadata_json['embedding_provider'] ?? null) === $providerName
                && count($vector) === count($queryVector);

            $overlap = $this->overlap($queryTokens, $this->tokens($chunk->document_title.' '.$chunk->section_ref.' '.$chunk->content_text));

            // Hybrid ranking: the vector finds paraphrases, the overlap pins exact codes such as TD04 or N6.9.
            $score = $comparable ? 0.5 * $this->cosine($queryVector, $vector) + 0.5 * $overlap : $overlap;

            if ($score >= self::MinimumScore) {
                $scored[] = ['chunk' => $chunk, 'score' => $score];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * The citation the client renders as a badge, e.g. [LIVA Art. 25].
     *
     * @return array<string, mixed>
     */
    public function citation(CopilotKnowledgeChunk $chunk): array
    {
        $badge = $chunk->metadata_json['badge'] ?? trim($chunk->document_title.' '.$chunk->section_ref);

        return [
            'type' => 'regulatory',
            'chunk_id' => (string) $chunk->id,
            'label' => '['.$badge.']',
            'title' => $chunk->document_title,
            'section_ref' => $chunk->section_ref,
            'jurisdiction' => $chunk->jurisdiction,
            'source_type' => $chunk->source_type,
        ];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function parseLocale(string $locale): array
    {
        $parts = preg_split('/[-_]/', strtolower($locale)) ?: [];
        $language = $parts[0] !== '' ? $parts[0] : 'en';
        $region = strtoupper($parts[1] ?? '');

        $jurisdictions = match ($region) {
            'CH' => ['CH', 'GLOBAL'],
            'IT' => ['IT', 'GLOBAL'],
            default => ['CH', 'IT', 'GLOBAL'],
        };

        return [$language, $jurisdictions];
    }

    /**
     * @param  list<string>  $jurisdictions
     * @return list<CopilotKnowledgeChunk>
     */
    private function candidates(string $tenantId, array $jurisdictions, ?string $language): array
    {
        return CopilotKnowledgeChunk::query()
            ->forTenant($tenantId)
            ->whereIn('jurisdiction', $jurisdictions)
            ->when($language !== null, static fn ($query) => $query->where('locale', $language))
            ->limit((int) config('copilot.limits.rag_candidates', 2000))
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $folded = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text));

        return array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $token): bool => strlen($token) > 2,
        )));
    }

    /**
     * @param  list<string>  $query
     * @param  list<string>  $document
     */
    private function overlap(array $query, array $document): float
    {
        if ($query === [] || $document === []) {
            return 0.0;
        }

        return count(array_intersect($query, $document)) / count($query);
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $other = (float) ($b[$index] ?? 0.0);
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        return $normA > 0.0 && $normB > 0.0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }
}
