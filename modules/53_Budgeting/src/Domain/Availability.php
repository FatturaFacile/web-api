<?php

declare(strict_types=1);

namespace Modules\Budgeting\Domain;

/**
 * The budget of a scope over a fiscal year against what was spent and committed:
 * available = budget - (actual + open commitments). The budget is the one of the approved original budget, or of its
 * latest approved revision when there is one.
 */
final readonly class Availability
{
    public function __construct(
        public ?string $versionId,
        public int $fiscalYear,
        public bool $hasBudget,
        public string $currency,
        public string $budget,
        public string $actual,
        public string $committed,
    ) {}

    /**
     * The availability of a spend that no rule controls: not computed.
     */
    public static function unchecked(int $fiscalYear): self
    {
        return new self(null, $fiscalYear, false, '', '0.00', '0.00', '0.00');
    }

    public function available(): string
    {
        return bcsub($this->budget, bcadd($this->actual, $this->committed, 2), 2);
    }

    /**
     * Actual plus commitments as a percentage of the budget; null when there is no budget to compare with.
     */
    public function utilizationPercent(): ?string
    {
        return bccomp($this->budget, '0', 2) <= 0 ? null : bcround(bcmul(bcdiv(bcadd($this->actual, $this->committed, 2), $this->budget, 8), '100', 8), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version_id' => $this->versionId,
            'fiscal_year' => $this->fiscalYear,
            'has_budget' => $this->hasBudget,
            'currency' => $this->currency,
            'budget' => $this->budget,
            'actual' => $this->actual,
            'committed' => $this->committed,
            'available' => $this->available(),
            'utilization_percent' => $this->utilizationPercent(),
        ];
    }
}
