<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Warranty;

use Modules\ServiceManagement\Enums\WarrantyPolicyType;

/**
 * Switzerland, Art. 210 CO: two-year limitation period for defects in both B2C and B2B sales.
 * B2B parties may shorten or extend it by contract clause; for consumers it cannot go below 24 months.
 */
final readonly class ChArt210CoTwoYearWarrantyStrategy implements WarrantyStatutoryRulesStrategyInterface
{
    public const STATUTORY_MONTHS = 24;

    public function supports(WarrantyContext $context): bool
    {
        return $context->normalizedJurisdiction() === 'CH';
    }

    public function calculate(WarrantyContext $context): WarrantyResult
    {
        $months = self::STATUTORY_MONTHS;
        $source = WarrantyResult::SOURCE_STATUTORY;

        if ($context->contractWarrantyMonths !== null) {
            if ($context->isB2b()) {
                $months = $context->contractWarrantyMonths;
                $source = WarrantyResult::SOURCE_CONTRACT_CLAUSE;
            } elseif ($context->contractWarrantyMonths > self::STATUTORY_MONTHS) {
                $months = $context->contractWarrantyMonths;
                $source = WarrantyResult::SOURCE_CONTRACT_CLAUSE;
            }
        }

        return new WarrantyResult(
            startDate: $context->deliveryDate,
            expiryDate: $context->deliveryDate->addMonthsNoOverflow($months),
            policyType: WarrantyPolicyType::StatutoryCh24m,
            months: $months,
            source: $source,
            jurisdiction: 'CH',
            customerType: $context->customerType,
        );
    }
}
