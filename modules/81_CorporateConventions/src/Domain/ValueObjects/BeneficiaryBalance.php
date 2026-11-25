<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\ValueObjects;

/**
 * What a beneficiary has already used in the current year, and whether the employer subsidy reaches this beneficiary.
 */
final readonly class BeneficiaryBalance
{
    public function __construct(
        public string $usedAmount = '0',
        public string $usedSubsidy = '0',
        public bool $subsidyEligible = true,
    ) {}
}
