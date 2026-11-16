<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Serialises the issue of commercial documents and the closure of their day, per company and day. Without it a document
 * checked before the closure could commit after the day was aggregated and stay out of the totals sent to the agency.
 */
final class CorrispettiviDayLock
{
    /**
     * Takes a transaction lock held until the end of the current transaction.
     *
     * @throws LogicException when called outside a transaction, where the lock would be released at once
     */
    public function acquire(string $tenantId, string $businessDate): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The corrispettivi day lock is taken inside a transaction.');
        }

        TransactionLock::acquire("corrispettivi:{$tenantId}:{$businessDate}");
    }
}
