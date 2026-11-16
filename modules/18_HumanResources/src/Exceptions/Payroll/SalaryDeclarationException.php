<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions\Payroll;

final class SalaryDeclarationException extends PayrollException
{
    /**
     * @param  array<string, list<string>>  $invalidParams
     */
    public static function invalid(string $detail, array $invalidParams = []): self
    {
        return new self($detail, $invalidParams, title: 'Salary Declaration Not Possible');
    }
}
