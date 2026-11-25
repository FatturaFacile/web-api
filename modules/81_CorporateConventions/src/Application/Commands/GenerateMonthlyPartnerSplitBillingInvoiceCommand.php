<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Consolidates the pending employer quotas of a convention and period into one B2B invoice with the beneficiary schedule.
 */
final readonly class GenerateMonthlyPartnerSplitBillingInvoiceCommand
{
    public function __construct(
        public string $conventionId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?bool $anonymize = null,
        public ?string $userId = null,
    ) {}
}
