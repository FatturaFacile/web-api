<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\DTOs;

/**
 * The cash discount of one open item at a reference date. `daysRemaining` is negative once the deadline has passed and
 * null when the item has no terms.
 */
final readonly class SkontoResult
{
    public function __construct(
        public bool $eligible,
        public ?string $percentage,
        public ?string $deadlineDate,
        public ?int $daysRemaining,
        public string $savingsAmount,
        public string $netPayableWithSkonto,
        public string $standardAmount,
        public ?string $termsSource,
        public ?int $netDays,
    ) {}

    /**
     * The fields of the API: the same keys in the list, the detail and the payment run preview.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skonto_eligible' => $this->eligible,
            'skonto_percentage' => $this->percentage,
            'skonto_deadline_date' => $this->deadlineDate,
            'skonto_days_remaining' => $this->daysRemaining,
            'skonto_savings_amount' => $this->savingsAmount,
            'net_payable_with_skonto' => $this->netPayableWithSkonto,
            'standard_amount' => $this->standardAmount,
            'skonto_terms_source' => $this->termsSource,
            'skonto_net_days' => $this->netDays,
        ];
    }
}
