<?php

declare(strict_types=1);

namespace Modules\Analytics\Support;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * SQL fragments that bring the amount of a document to the accounting currency of the company, the way the ledger does
 * (amount times the exchange rate stored on the document). A document in another currency without a rate cannot be
 * converted: it is left out of the sums and counted apart, so a total never mixes currencies.
 */
final readonly class ConvertedAmountSql
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Accounting currency of the active company.
     */
    public function currency(): string
    {
        return TenantAccountingCurrency::of((string) $this->tenantContext->getTenantId());
    }

    /**
     * Factor to the accounting currency for a row of the table. Only meaningful together with {@see convertible()}.
     */
    public function rate(string $table): string
    {
        $currency = DB::getPdo()->quote($this->currency());

        return "(CASE WHEN UPPER({$table}.currency) = {$currency} THEN 1 ELSE COALESCE(NULLIF({$table}.exchange_rate, 0), 1) END)";
    }

    /**
     * Condition that is true for rows that can be converted (accounting currency, or a stored rate).
     */
    public function convertible(string $table): string
    {
        $currency = DB::getPdo()->quote($this->currency());

        return "(UPPER({$table}.currency) = {$currency} OR COALESCE({$table}.exchange_rate, 0) <> 0)";
    }
}
