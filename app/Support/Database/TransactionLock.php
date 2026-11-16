<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * A named lock held until the surrounding transaction ends, portable across databases: the row of the name in
 * `transaction_locks` is created when missing and locked FOR UPDATE, so a second transaction asking for the same name
 * waits for the first to commit or roll back. Engines without row locks (SQLite) already write one transaction at a
 * time. Outside a transaction nothing is held, so nothing is taken.
 */
final class TransactionLock
{
    public static function acquire(string $name): void
    {
        if (DB::transactionLevel() === 0) {
            return;
        }

        $key = hash('sha256', $name);

        DB::table('transaction_locks')->insertOrIgnore(['name' => $key]);
        DB::table('transaction_locks')->where('name', $key)->lockForUpdate()->first();
    }
}
