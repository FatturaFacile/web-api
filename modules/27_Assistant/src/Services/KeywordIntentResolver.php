<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use Modules\Assistant\Contracts\IntentResolver;
use Modules\Assistant\DTOs\ResolvedIntent;

/**
 * Local matching: the longest keyword found in the question wins. Nothing leaves the installation.
 */
final class KeywordIntentResolver implements IntentResolver
{
    public function name(): string
    {
        return 'keywords';
    }

    public function resolve(string $tenantId, string $question, array $queries): ?ResolvedIntent
    {
        $text = mb_strtolower($question);
        $bestKey = null;
        $bestLength = 0;

        foreach ($queries as $query) {
            foreach ($query->keywords() as $keyword) {
                if (mb_strlen($keyword) > $bestLength && str_contains($text, $keyword)) {
                    $bestKey = $query->key();
                    $bestLength = mb_strlen($keyword);
                }
            }
        }

        return $bestKey === null ? null : new ResolvedIntent($bestKey, $this->parametersIn($text));
    }

    /**
     * @return array<string, int>
     */
    private function parametersIn(string $text): array
    {
        $parameters = [];

        if (preg_match('/\b(20\d{2})\b/', $text, $year) === 1) {
            $parameters['year'] = (int) $year[1];
        }

        if (preg_match('/\b(?:top|primi|prime|first|erste)\s+(\d{1,2})\b/u', $text, $limit) === 1) {
            $parameters['limit'] = (int) $limit[1];
        }

        if (preg_match('/\b(\d{1,3})\s*(?:giorni|days|tage|jours)\b/u', $text, $days) === 1) {
            $parameters['days'] = (int) $days[1];
        }

        return $parameters;
    }
}
