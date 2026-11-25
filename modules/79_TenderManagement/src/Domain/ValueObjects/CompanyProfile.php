<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\ValueObjects;

/**
 * What the ERP knows about the company for a tender: the turnover over the reference years and the similar projects it has
 * delivered in that period (Mod 065).
 */
final readonly class CompanyProfile
{
    public function __construct(
        public string $turnover,
        public int $similarProjectsCount,
        public string $largestSimilarProjectAmount,
    ) {}
}
