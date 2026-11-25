<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Contracts;

/**
 * Sentiment of an open comment or voice transcript (Mod 301 hook): a score from -1 (negative) to +1 (positive), the label and
 * the recurring themes found. Bind another implementation to route the text to the AI gateway.
 */
interface SentimentAnalyzerInterface
{
    /**
     * @return array{score: float, label: string, topics: list<string>}
     */
    public function analyze(string $text, string $locale): array;
}
