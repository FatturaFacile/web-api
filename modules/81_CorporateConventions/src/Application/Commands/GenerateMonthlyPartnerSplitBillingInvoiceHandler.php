<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\SplitBillingConsolidationService;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Consolidates the pending employer quotas of a convention and period into one B2B invoice with the beneficiary schedule.
 */
final class GenerateMonthlyPartnerSplitBillingInvoiceHandler
{
    public function __construct(
        private readonly SplitBillingConsolidationService $consolidation,
    ) {}

    /**
     * @return array{invoice: SalesInvoice, attachment_path: string, rows_count: int, total_employer_quota: string}
     */
    public function handle(GenerateMonthlyPartnerSplitBillingInvoiceCommand $command): array
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($command->conventionId);

        return $this->consolidation->consolidate($convention, $command->from, $command->to, $command->anonymize, $command->userId);
    }
}
