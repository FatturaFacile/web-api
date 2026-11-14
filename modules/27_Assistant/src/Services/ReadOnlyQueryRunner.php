<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a catalogue query inside a read-only transaction, then always rolls it back. The database itself refuses any
 * write (PostgreSQL and MySQL read-only transactions, the SQLite query-only pragma), so a mistake in a catalogue class
 * cannot change data. PostgreSQL also gets a time limit.
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
        $driver = $connection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $connection->statement('SET TRANSACTION READ ONLY');
        }

        $connection->beginTransaction();

        try {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
                $connection->statement('SET LOCAL statement_timeout = '.self::TimeoutMilliseconds);
            } elseif ($driver === 'sqlite') {
                $connection->statement('PRAGMA query_only = ON');
            }

            return $query();
        } finally {
            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA query_only = OFF');
            }

            $connection->rollBack();
        }
    }
}
