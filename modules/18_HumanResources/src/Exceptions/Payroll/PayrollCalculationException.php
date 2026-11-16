<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions\Payroll;

/**
 * The payroll could not be calculated from the available data (422). Nothing is persisted.
 */
class PayrollCalculationException extends PayrollException
{
    /**
     * @param  array<string, list<string>>  $invalidParams
     */
    public static function invalid(string $detail, array $invalidParams = []): self
    {
        return new self($detail, $invalidParams, title: 'Payroll Calculation Failed');
    }
}
