<?php

declare(strict_types=1);

namespace Modules\Academy\Queries;

use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Models\ContextualHelpArticle;

/**
 * The help article of an ERP route in the requested language: it-CH falls back to it-IT, then to English (the chain of
 * AcademyLocale). Patterns use ":param" for a variable segment ("/invoices/:id"); the most specific pattern wins and a
 * tenant's own article wins over the shared one.
 */
final class GetContextualHelpForRouteQuery
{
    /**
     * @return array{article: ContextualHelpArticle, locale: string}|null
     */
    public function execute(string $route, AcademyLocale $locale): ?array
    {
        $path = $this->normalise($route);

        foreach ($locale->fallbackChain() as $candidate) {
            $best = null;
            $bestScore = -1;

            foreach (ContextualHelpArticle::query()->where('locale', $candidate->value)->get() as $article) {
                $score = $this->score($article->route_pattern, $path) + ($article->tenant_id !== null ? 1000 : 0);

                if ($score >= 0 && $score > $bestScore) {
                    $best = $article;
                    $bestScore = $score;
                }
            }

            if ($best !== null) {
                return ['article' => $best, 'locale' => $candidate->value];
            }
        }

        return null;
    }

    private function normalise(string $route): string
    {
        $path = (string) parse_url($route, PHP_URL_PATH);

        return '/'.trim($path, '/');
    }

    /**
     * -1 when the pattern does not match, otherwise the number of literal segments (more is more specific).
     */
    private function score(string $pattern, string $path): int
    {
        $patternSegments = explode('/', trim($this->normalise($pattern), '/'));
        $pathSegments = explode('/', trim($path, '/'));

        if (count($patternSegments) !== count($pathSegments)) {
            return -1;
        }

        $literals = 0;

        foreach ($patternSegments as $i => $segment) {
            if (str_starts_with($segment, ':')) {
                continue;
            }

            if ($segment !== $pathSegments[$i]) {
                return -1;
            }

            $literals++;
        }

        return $literals;
    }
}
