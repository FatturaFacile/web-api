<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Modules\Payroll\Calculation\Dec;

/**
 * Parses the official ESTV withholding tax tariff files (fixed-length records, "Recordformate Quellensteuer-Tarife"):
 * record 06 = tariff row (canton 5-6, code 7-16, valid from 17-24, income from 25-33, step 34-42, minimum tax 46-54,
 * rate 55-59; amounts and rate with two implied decimals).
 */
final class WithholdingTaxTariffParser
{
    /**
     * @return list<WithholdingTaxTariffData> the tariff rows; deletion records are skipped
     *
     * @throws InvalidArgumentException when the file contains no tariff records
     */
    public static function parse(string $content): array
    {
        $rows = [];

        foreach (explode("\n", $content) as $rawLine) {
            $line = rtrim($rawLine, "\r");

            if (strlen($line) < 59 || ! str_starts_with($line, '06') || substr($line, 2, 2) === '03') {
                continue;
            }

            $rows[] = new WithholdingTaxTariffData(
                canton: strtoupper(trim(substr($line, 4, 2))),
                tariffCode: strtoupper(trim(substr($line, 6, 10))),
                validFrom: self::date(substr($line, 16, 8)),
                incomeFrom: self::implied(substr($line, 24, 9)),
                incomeStep: self::implied(substr($line, 33, 9)),
                minimumTax: self::implied(substr($line, 45, 9)),
                ratePercent: self::implied(substr($line, 54, 5)),
            );
        }

        return $rows === []
            ? throw new InvalidArgumentException('Il file non contiene record di tariffa ESTV (record 06).')
            : $rows;
    }

    private static function date(string $digits): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Ymd', $digits, new DateTimeZone('UTC'));

        if ($date === false || $date->format('Ymd') !== $digits) {
            throw new InvalidArgumentException("Data non valida nel file tariffario: [{$digits}].");
        }

        return $date;
    }

    private static function implied(string $digits): string
    {
        $trimmed = trim($digits);

        if ($trimmed === '') {
            return '0';
        }

        if (preg_match('/^[+-]?\d+$/', $trimmed) !== 1) {
            throw new InvalidArgumentException("Importo non valido nel file tariffario: [{$trimmed}].");
        }

        return Dec::div(Dec::of($trimmed), '100');
    }
}
