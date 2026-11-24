<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Resolves the page size requested by a grid (`per_page`), falling back to a default.
 */
final class RequestedPerPage
{
    public const int MAX = 200;

    public static function resolve(int $default = 25): int
    {
        $requested = (int) request()->query('per_page', $default);

        return max(1, min($requested > 0 ? $requested : $default, self::MAX));
    }
}
