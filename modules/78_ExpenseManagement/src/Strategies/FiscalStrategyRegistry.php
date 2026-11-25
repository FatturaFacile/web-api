<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Strategies;

use Closure;
use Modules\ExpenseManagement\Contracts\ExpenseFiscalComplianceStrategyInterface;

/**
 * Resolves the fiscal strategy of a jurisdiction. Italy is the fallback; another country registers with extend().
 */
final class FiscalStrategyRegistry
{
    /** @var array<string, Closure(): ExpenseFiscalComplianceStrategyInterface> */
    private array $factories = [];

    public function __construct()
    {
        $this->extend('CH', static fn (): ExpenseFiscalComplianceStrategyInterface => app(SwissSpesenStrategy::class));
        $this->extend('IT', static fn (): ExpenseFiscalComplianceStrategyInterface => app(ItalianNoteSpeseStrategy::class));
    }

    /**
     * @param  Closure(): ExpenseFiscalComplianceStrategyInterface  $factory
     */
    public function extend(string $jurisdiction, Closure $factory): void
    {
        $this->factories[strtoupper($jurisdiction)] = $factory;
    }

    public function for(?string $jurisdiction): ExpenseFiscalComplianceStrategyInterface
    {
        $key = strtoupper((string) $jurisdiction);

        return ($this->factories[$key] ?? $this->factories['IT'])();
    }

    /**
     * The jurisdiction of a company: Switzerland for Swiss tenants, Italy otherwise.
     */
    public static function jurisdictionOfCountry(?string $country): string
    {
        return strtoupper((string) $country) === 'CH' ? 'CH' : 'IT';
    }
}
