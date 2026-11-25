<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\ValueObjects;

/**
 * Outcome of applying a convention to a cart: per-line discounts, the totals and the split between employer and employee.
 *
 * @phpstan-type LineResult array{line_id: string, gross: string, existing_discount: string, convention_discount: string, net: string, cost: string, applied_rule_ids: list<string>}
 */
final readonly class PricingResult
{
    /**
     * @param  list<LineResult>  $lines
     */
    public function __construct(
        public array $lines,
        public string $totalGross,
        public string $conventionDiscount,
        public string $payableAfterDiscount,
        public string $employerSubsidy,
        public string $employeePaid,
        public string $totalCost,
        public string $cappedSpend,
        public ?string $capRemainingBefore,
        public bool $capReached,
        public ?string $subsidyRemainingBefore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'totals' => [
                'total_gross_amount' => $this->totalGross,
                'convention_discount_amount' => $this->conventionDiscount,
                'payable_after_discount' => $this->payableAfterDiscount,
                'employer_subsidy_amount' => $this->employerSubsidy,
                'employee_paid_amount' => $this->employeePaid,
                'cost_amount' => $this->totalCost,
            ],
            'cap' => [
                'remaining_before' => $this->capRemainingBefore,
                'counted_spend' => $this->cappedSpend,
                'reached' => $this->capReached,
            ],
            'subsidy' => ['remaining_before' => $this->subsidyRemainingBefore],
        ];
    }
}
