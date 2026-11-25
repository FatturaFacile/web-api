<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Queries;

/**
 * Prices a cart under the convention of a pass: discounts, remaining cap and the employer / employee split.
 */
final readonly class CalculateConventionCartPricingQuery
{
    public function __construct(
        public string $identifier,
        public ?string $conventionId,
        /** @var list<array<string, mixed>> */
        public array $lines,
        public ?string $currency = null,
    ) {}
}
