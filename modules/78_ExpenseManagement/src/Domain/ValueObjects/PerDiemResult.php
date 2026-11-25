<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

final readonly class PerDiemResult
{
    /**
     * @param  list<array{code: string, params: array<string, mixed>}>  $warnings
     */
    public function __construct(
        public string $ratePerDay,
        public string $grossAmount,
        public string $dailyTaxFreeLimit,
        public string $taxFreeAmount,
        public string $taxableExcessAmount,
        public string $reductions,
        public array $warnings,
    ) {}
}
