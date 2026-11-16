<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions\Payroll;

final class MissingRateSetException extends PayrollCalculationException
{
    public static function forPeriod(string $periodCode): self
    {
        return new self(
            detail: "No rate set for period {$periodCode}.",
            invalidParams: ['period' => ["No active Swiss payroll rate set is valid for {$periodCode}."]],
            title: 'No rate set for period',
        );
    }
}
