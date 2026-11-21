<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Domain\Services;

/**
 * Turns a line description into a stable signature and compares two descriptions. The signature drops what changes from
 * one invoice to the next (dates, quantities, units, stop words, word order) and keeps what identifies the item bought.
 */
final class LineDescriptionMatcher
{
    private const int MaxPatternLength = 512;

    /**
     * Stop words of the four document languages (it, de, fr, en).
     *
     * @var list<string>
     */
    private const array StopWords = [
        'il', 'lo', 'la', 'le', 'gli', 'i', 'un', 'una', 'di', 'del', 'della', 'dei', 'delle', 'da', 'in', 'con', 'per', 'su', 'tra', 'fra', 'e', 'ed', 'a', 'al', 'alla',
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'und', 'von', 'mit', 'für', 'fur', 'zu', 'im', 'am', 'auf', 'bei', 'nach',
        'les', 'une', 'du', 'et', 'pour', 'avec', 'sur', 'dans', 'au', 'aux', 'par',
        'the', 'an', 'of', 'and', 'for', 'with', 'to', 'on', 'at', 'by', 'from',
        'pz', 'pcs', 'stk', 'stück', 'stuck', 'pce', 'x', 'nr', 'n', 'no', 'art', 'cad', 'cadauno',
    ];

    /**
     * Lowercase, accent-free, date-free and quantity-free sorted token signature of at most 512 characters.
     */
    public function normalize(string $description): string
    {
        $text = mb_strtolower($description);
        $text = preg_replace('/\b\d{1,4}[.\/-]\d{1,2}[.\/-]\d{1,4}\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:x|pcs|pz|stk|kg|g|l|ml|m|cm|mm|h|ore|std|%)(?=\W|$)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d+(?:[.,]\d+)?\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\s]/u', ' ', $text) ?? $text;

        $tokens = [];

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
            if ($token !== '' && mb_strlen($token) > 1 && ! in_array($token, self::StopWords, true)) {
                $tokens[$token] = true;
            }
        }

        $tokens = array_keys($tokens);
        sort($tokens);

        return mb_substr(implode(' ', $tokens), 0, self::MaxPatternLength);
    }

    /**
     * Similarity in 0.0–1.0 of two signatures: the better of the cosine overlap of their tokens and their Levenshtein ratio.
     */
    public function similarity(string $patternA, string $patternB): float
    {
        if ($patternA === '' || $patternB === '') {
            return 0.0;
        }

        if ($patternA === $patternB) {
            return 1.0;
        }

        $a = explode(' ', $patternA);
        $b = explode(' ', $patternB);
        $cosine = count(array_intersect($a, $b)) / sqrt(count($a) * count($b));

        $longest = max(strlen($patternA), strlen($patternB));
        $levenshtein = 1 - (levenshtein($patternA, $patternB) / $longest);

        return round(min(1.0, max($cosine, $levenshtein)), 4);
    }
}
