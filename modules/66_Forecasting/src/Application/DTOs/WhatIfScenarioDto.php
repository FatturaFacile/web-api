<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\DTOs;

/**
 * Overrides of a what-if liquidity simulation.
 */
final readonly class WhatIfScenarioDto
{
    public function __construct(
        /** Days added to the expected collection date of every open receivable (negative = earlier). */
        public int $globalCustomerDelayShiftDays = 0,
        /** Percent change of the receipts (open receivables and recurring inflows). */
        public float $revenueGrowthPercentage = 0.0,
        /** Days added to the payment date of every supplier bill. */
        public int $supplierPaymentExtensionDays = 0,
        /** Extra annual interest rate, in percentage points, charged on a negative balance. */
        public float $interestRateDelta = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            (int) ($values['global_customer_delay_shift_days'] ?? 0),
            (float) ($values['revenue_growth_percentage'] ?? 0.0),
            (int) ($values['supplier_payment_extension_days'] ?? 0),
            (float) ($values['interest_rate_delta'] ?? 0.0),
        );
    }

    public function isNeutral(): bool
    {
        return $this->globalCustomerDelayShiftDays === 0 && $this->revenueGrowthPercentage === 0.0
            && $this->supplierPaymentExtensionDays === 0 && $this->interestRateDelta === 0.0;
    }

    /**
     * @return array{global_customer_delay_shift_days: int, revenue_growth_percentage: float, supplier_payment_extension_days: int, interest_rate_delta: float}
     */
    public function toArray(): array
    {
        return [
            'global_customer_delay_shift_days' => $this->globalCustomerDelayShiftDays,
            'revenue_growth_percentage' => $this->revenueGrowthPercentage,
            'supplier_payment_extension_days' => $this->supplierPaymentExtensionDays,
            'interest_rate_delta' => $this->interestRateDelta,
        ];
    }
}
