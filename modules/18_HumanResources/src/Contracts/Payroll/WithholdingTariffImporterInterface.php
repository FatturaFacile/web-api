<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts\Payroll;

use Modules\HumanResources\Exceptions\Payroll\WithholdingTariffImportException;

interface WithholdingTariffImporterInterface
{
    /**
     * Imports withholding tax tariffs from the internal CSV format. Existing tariffs with the same owner,
     * canton, code and start date get their brackets replaced.
     *
     * @return array{tariffs: int, brackets: int}
     *
     * @throws WithholdingTariffImportException
     */
    public function import(string $csvContents, ?string $tenantId = null, string $source = 'csv_import'): array;

    /**
     * Same as import() for rows already split into columns (canton, tariff_code, valid_from, valid_to,
     * income_from, income_to, rate_percent, fixed_amount), e.g. from a JSON API request.
     *
     * @param  list<array<string, string|int|float|null>>  $rows
     * @return array{tariffs: int, brackets: int}
     *
     * @throws WithholdingTariffImportException
     */
    public function importRows(array $rows, ?string $tenantId = null, string $source = 'manual'): array;
}
