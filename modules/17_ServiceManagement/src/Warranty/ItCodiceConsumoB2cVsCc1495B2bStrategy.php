<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Warranty;

use Modules\ServiceManagement\Enums\WarrantyPolicyType;

/**
 * Italy: consumers get 24 months legal conformity guarantee (Codice del Consumo, D.Lgs. 206/2005 art. 133);
 * business buyers fall under Art. 1495 c.c. (one year from delivery). A contract clause may extend the
 * period (and, for B2B only, shorten it); consumer protection cannot go below 24 months.
 */
final readonly class ItCodiceConsumoB2cVsCc1495B2bStrategy implements WarrantyStatutoryRulesStrategyInterface
{
    public const B2C_MONTHS = 24;

    public const B2B_MONTHS = 12;

    public function supports(WarrantyContext $context): bool
    {
        return $context->normalizedJurisdiction() === 'IT';
    }

    public function calculate(WarrantyContext $context): WarrantyResult
    {
        $isB2b = $context->isB2b();
        $months = $isB2b ? self::B2B_MONTHS : self::B2C_MONTHS;
        $source = WarrantyResult::SOURCE_STATUTORY;

        if ($context->contractWarrantyMonths !== null && ($isB2b || $context->contractWarrantyMonths > $months)) {
            $months = $context->contractWarrantyMonths;
            $source = WarrantyResult::SOURCE_CONTRACT_CLAUSE;
        }

        return new WarrantyResult(
            startDate: $context->deliveryDate,
            expiryDate: $context->deliveryDate->addMonthsNoOverflow($months),
            policyType: $isB2b ? WarrantyPolicyType::StatutoryItB2b12m : WarrantyPolicyType::StatutoryItB2c24m,
            months: $months,
            source: $source,
            jurisdiction: 'IT',
            customerType: $context->customerType,
        );
    }
}
