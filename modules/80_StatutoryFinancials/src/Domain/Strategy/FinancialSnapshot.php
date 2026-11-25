<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Strategy;

use Modules\StatutoryFinancials\Support\Money;

/**
 * The figures the ratios and crisis indicators are computed from (all rounded statement amounts, plus the cash flow and the
 * debt-service inputs read from the subledgers). Missing figures are zero.
 */
final readonly class FinancialSnapshot
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(public array $values, public string $locale = 'it') {}

    public function get(string $key): string
    {
        return Money::from($this->values[$key] ?? '0');
    }

    /**
     * @param  array<string, string>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->values, $overrides), $this->locale);
    }
}
