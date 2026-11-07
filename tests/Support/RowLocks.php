<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Records the row locks a piece of code takes, for the tests of a lock-and-recheck path where real parallel processes
 * would need a whole committed fixture.
 */
final class RowLocks
{
    /**
     * @return list<string> The tables locked with SELECT ... FOR UPDATE, in order.
     */
    public static function takenBy(callable $operation): array
    {
        $tables = [];
        $recording = true;

        DB::listen(static function (QueryExecuted $query) use (&$tables, &$recording): void {
            if ($recording && preg_match('/\bfor update\b/i', $query->sql) === 1 && preg_match('/\bfrom\s+"?(\w+)"?/i', $query->sql, $match) === 1) {
                $tables[] = $match[1];
            }
        });

        try {
            $operation();
        } finally {
            $recording = false;
        }

        return $tables;
    }
}
