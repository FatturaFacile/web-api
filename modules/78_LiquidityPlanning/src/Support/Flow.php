<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Support;

use Carbon\CarbonImmutable;

/**
 * One expected cash movement of the forecast: a date, a direction, a reliability tier (certain, probable, estimated), a
 * category, a positive amount and, for the debt service, the component (principal or interest).
 */
final readonly class Flow
{
    public const string Inflow = 'inflow';

    public const string Outflow = 'outflow';

    public const string Certain = 'certain';

    public const string Probable = 'probable';

    public const string Estimated = 'estimated';

    /** @var list<string> */
    public const array Tiers = [self::Certain, self::Probable, self::Estimated];

    /** @var list<string> */
    public const array CustomerCategories = ['receivable', 'sales_order', 'recurring_contract'];

    /** @var list<string> */
    public const array SupplierCategories = ['payable', 'purchase_order'];

    /** @var list<string> */
    public const array DebtCategories = ['debt_principal', 'debt_interest'];

    public function __construct(
        public CarbonImmutable $date,
        public string $direction,
        public string $tier,
        public string $category,
        public string $label,
        public string $amount,
        public ?string $labelKey = null,
    ) {}

    public function withDate(CarbonImmutable $date): self
    {
        return new self($date, $this->direction, $this->tier, $this->category, $this->label, $this->amount, $this->labelKey);
    }

    public function withAmount(string $amount): self
    {
        return new self($this->date, $this->direction, $this->tier, $this->category, $this->label, $amount, $this->labelKey);
    }

    public function isInflow(): bool
    {
        return $this->direction === self::Inflow;
    }

    public function isCustomerInflow(): bool
    {
        return $this->isInflow() && in_array($this->category, self::CustomerCategories, true);
    }

    public function isSupplierOutflow(): bool
    {
        return ! $this->isInflow() && in_array($this->category, self::SupplierCategories, true);
    }

    public function isCapex(): bool
    {
        return ! $this->isInflow() && $this->category === 'capex';
    }

    public function isDebtService(): bool
    {
        return ! $this->isInflow() && in_array($this->category, self::DebtCategories, true);
    }
}
