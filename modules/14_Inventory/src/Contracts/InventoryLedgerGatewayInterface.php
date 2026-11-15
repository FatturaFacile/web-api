<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/**
 * Read-side gateway into Module 22 General Ledger used by inventory valuation
 * (FatturaFacile/FatturaFacile#144). Implemented by the General Ledger module so that
 * Inventory never imports GL models; journals themselves are posted by the GL
 * `InventoryAccountingListener` reacting to inventory domain events.
 */
interface InventoryLedgerGatewayInterface
{
    /**
     * Resolves the fiscal period a valuation journal dated $date would be posted to.
     * Returns null when the covering period is closed/locked or no open period exists.
     *
     * @return array{fiscal_period_id: string, posting_date: string}|null
     */
    public function resolvePostingPeriod(string $tenantId, ?string $companyId, string $date): ?array;

    public function findJournalEntryId(string $tenantId, string $sourceDocumentType, string $sourceDocumentId): ?string;

    /**
     * Balance (debit - credit, 2 decimals) of the inventory asset accounts as of $asOf:
     * default account 1200 plus any additional category-level inventory accounts.
     *
     * @param  list<string>  $additionalAccountIds
     */
    public function inventoryAccountBalance(string $tenantId, ?string $companyId, string $asOf, array $additionalAccountIds = []): string;

    /**
     * @return array{id: string, start_date: string, end_date: string, status: string}|null
     */
    public function findFiscalYear(string $tenantId, ?string $companyId, int $year): ?array;

    /**
     * True when $date falls in a closed fiscal year or a closed/locked fiscal period.
     */
    public function isDateInClosedPeriod(string $tenantId, ?string $companyId, string $date): bool;
}
