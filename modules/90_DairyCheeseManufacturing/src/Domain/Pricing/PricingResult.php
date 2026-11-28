<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Pricing;

/**
 * Result of the quality pricing of a month: the itemized lines and the totals (all amounts in the settlement currency).
 */
final readonly class PricingResult
{
    /**
     * @param  list<array{component: string, label_key: string, quantity_kg: string, rate: string, amount: float}>  $lines
     */
    public function __construct(
        public array $lines,
        public float $base,
        public float $bonus,
        public float $supplement,
        public float $penalty,
        public float $gross,
        public float $vat,
        public float $net,
    ) {}
}
