<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a catalogue query inside a read-only transaction with a time limit, then always rolls it back. PostgreSQL itself
 * refuses any write, so a mistake in a catalogue class cannot change data.
 */
final class ReadOnlyQueryRunner
{
    private const int TimeoutMilliseconds = 5000;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $query
     * @return TResult
     */
    public function run(Closure $query): mixed
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
                $connection->statement('SET LOCAL statement_timeout = '.self::TimeoutMilliseconds);
            }

            return $query();
        } finally {
            $connection->rollBack();
        }
    }
}
