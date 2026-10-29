<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/**
 * Interface prepared for Module 22 General Ledger & Accounting integration.
 * Dedicated to booking double-entry stock transactions to inventory asset and COGS subledgers.
 */
interface GeneralLedgerInventoryIntegrationInterface
{
    public function bookInventoryReceiptJournal(string $movementId, float $totalValuation, string $currency): void;

    public function bookCostOfGoodsSoldJournal(string $movementId, float $totalValuation, string $cogsAccountCode): void;

    public function bookStockAdjustmentVarianceJournal(string $adjustmentId, float $netValuationChange, string $varianceAccountCode): void;

    public function bookInventoryWriteOffJournal(string $adjustmentId, float $writeOffValuation, string $lossAccountCode): void;
}
