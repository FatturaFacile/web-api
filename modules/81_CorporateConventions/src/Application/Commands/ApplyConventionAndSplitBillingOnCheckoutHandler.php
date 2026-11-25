<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Domain\ValueObjects\PricingResult;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\ConventionSplitBilling;
use Modules\CorporateConventions\Services\ConventionCheckoutService;

/**
 * Commits discount and employer subsidy atomically and records the split billing of the purchase.
 */
final class ApplyConventionAndSplitBillingOnCheckoutHandler
{
    public function __construct(
        private readonly ConventionCheckoutService $checkout,
    ) {}

    /**
     * @return array{split_billing: ConventionSplitBilling, pricing: PricingResult}
     */
    public function handle(ApplyConventionAndSplitBillingOnCheckoutCommand $command): array
    {
        $enrollment = ConventionBeneficiaryEnrollment::query()->with('convention.discountRules')->findOrFail($command->enrollmentId);

        return $this->checkout->apply(
            $enrollment->convention,
            $enrollment,
            $command->lines,
            $command->documentType,
            $command->documentId,
            $command->employeeDocumentId,
            $command->transactionDate,
            $command->currency,
            $command->userId,
        );
    }
}
