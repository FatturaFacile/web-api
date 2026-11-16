<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;

/**
 * Switch of the immutability guards of App\Support\Database\Concerns\ProtectsRecords. The guards live in the
 * application so they behave the same on every database; the few legitimate exceptions (a legal retention purge, the
 * reopening of a payroll run, a tenant restore) run inside lifted() and nowhere else.
 */
final class Immutability
{
    private static int $lifted = 0;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function lifted(Closure $work): mixed
    {
        self::$lifted++;

        try {
            return $work();
        } finally {
            self::$lifted--;
        }
    }

    public static function isLifted(): bool
    {
        return self::$lifted > 0;
    }
}
