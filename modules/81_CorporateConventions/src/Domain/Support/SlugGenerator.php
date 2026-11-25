<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Support;

use Illuminate\Support\Str;

/**
 * URL slug of the landing page /convenzioni/<slug>: lower case, ASCII, dash separated, never one of the reserved paths.
 */
final class SlugGenerator
{
    /**
     * @param  callable(string): bool  $exists  whether a slug is already taken
     * @param  list<string>  $reserved
     */
    public static function generate(string $name, callable $exists, array $reserved = []): string
    {
        $base = Str::limit(Str::slug($name), 100, '');
        $base = $base === '' ? 'convention' : $base;
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $reserved, true) || $exists($candidate)) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 && strlen($slug) <= 120;
    }
}
