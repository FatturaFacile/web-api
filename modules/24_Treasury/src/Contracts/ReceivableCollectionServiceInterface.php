<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Models\ReceivableCollection;

interface ReceivableCollectionServiceInterface
{
    /**
     * Ingest customer receivable readiness event and record collection schedule.
     *
     * @param  array<string, mixed>  $data
     */
    public function scheduleCollection(array $data): ReceivableCollection;

    /**
     * Compute multi-tiered customer aging analysis (Current, 1-30, 31-60, 61-90, Over 90).
     *
     * @return array<string, mixed>
     */
    public function getCustomerAging(string $tenantId, ?string $companyId = null): array;

    /**
     * Mark receivable collection as settled upon bank receipt.
     */
    public function recordCollectionReceived(string $collectionId, float $amount, string $collectionDate): ReceivableCollection;

    /**
     * Prioritize overdue collections and generate dunning actions.
     *
     * @return array<int, ReceivableCollection>
     */
    public function evaluateDunningReadiness(string $tenantId): array;
}
