<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Services\WelfareVoucherService;

/**
 * Reconciles the vouchers of a provider against its settlement statement or self-invoice.
 */
final class ReconcileWelfareVoucherBatchHandler
{
    public function __construct(
        private readonly WelfareVoucherService $vouchers,
    ) {}

    /**
     * @return array{reconciled: int, rejected: int, total_expected: string, total_settled: string, discrepancies: list<array<string, string>>}
     */
    public function handle(ReconcileWelfareVoucherBatchCommand $command): array
    {
        return $this->vouchers->reconcile($command->provider, $command->batchReference, $command->selfInvoiceNumber, $command->lines, $command->userId);
    }
}
