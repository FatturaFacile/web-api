<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\ValueObjects;

use Modules\CorporateConventions\Domain\Enums\SubsidyType;

/**
 * The caps and the employer subsidy of a convention that bear on a single beneficiary.
 */
final readonly class ConventionTerms
{
    public function __construct(
        public ?string $annualSpendingCap = null,
        public bool $subsidyEnabled = false,
        public ?SubsidyType $subsidyType = null,
        public ?string $subsidyValue = null,
        public ?string $subsidyAnnualCap = null,
    ) {}
}
