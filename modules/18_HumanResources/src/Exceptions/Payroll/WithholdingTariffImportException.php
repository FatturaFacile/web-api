<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions\Payroll;

final class WithholdingTariffImportException extends PayrollException
{
    /**
     * @param  array<string, list<string>>  $invalidParams
     */
    public static function invalid(string $detail, array $invalidParams = []): self
    {
        return new self($detail, $invalidParams, title: 'Withholding Tariff Import Failed');
    }
}
