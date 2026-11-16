<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\HumanResources\Contracts\Payroll\WithholdingTariffImporterInterface;
use Modules\HumanResources\Enums\Payroll\SwissCanton;
use Modules\HumanResources\Exceptions\Payroll\WithholdingTariffImportException;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Modules\HumanResources\Models\Payroll\WithholdingTariffBracket;

/**
 * Imports withholding tax tariffs from the stable internal CSV format (comma or semicolon separated):
 *
 * ```
 * canton,tariff_code,valid_from,valid_to,income_from,income_to,rate_percent,fixed_amount
 * TI,A0N,2026-01-01,,0.00,2500.00,0.00,0.00
 * TI,A0N,2026-01-01,,2500.05,,1.50,0.00
 * ```
 *
 * Amounts are monthly CHF, `income_to` is inclusive and may be empty for the open top bracket. A parser for
 * the official ESTV fixed-width tariff files can later convert into this format.
 */
final class WithholdingTariffImporter implements WithholdingTariffImporterInterface
{
    /**
     * @var list<string>
     */
    private const REQUIRED_COLUMNS = ['canton', 'tariff_code', 'valid_from', 'valid_to', 'income_from', 'income_to', 'rate_percent', 'fixed_amount'];

    public function import(string $csvContents, ?string $tenantId = null, string $source = 'csv_import'): array
    {
        return $this->persist($this->group($this->parseCsv($csvContents)), $tenantId, $source);
    }

    public function importRows(array $rows, ?string $tenantId = null, string $source = 'manual'): array
    {
        $indexedRows = [];

        foreach (array_values($rows) as $index => $row) {
            $indexedRows['brackets.'.$index] = array_map(static fn (mixed $value): string => trim((string) $value), $row);
        }

        return $this->persist($this->group($indexedRows), $tenantId, $source);
    }

    /**
     * @param  list<array{canton: string, tariff_code: string, valid_from: string, valid_to: string|null, brackets: list<array{income_from_rappen: int, income_to_rappen: int|null, rate_ppm: int, fixed_amount_rappen: int}>}>  $groups
     * @return array{tariffs: int, brackets: int}
     */
    private function persist(array $groups, ?string $tenantId, string $source): array
    {
        return DB::transaction(function () use ($groups, $tenantId, $source): array {
            $bracketCount = 0;

            foreach ($groups as $group) {
                $tariff = WithholdingTariff::query()
                    ->when(
                        $tenantId !== null,
                        fn ($query) => $query->where('tenant_id', $tenantId),
                        fn ($query) => $query->whereNull('tenant_id'),
                    )
                    ->where('canton', $group['canton'])
                    ->where('tariff_code', $group['tariff_code'])
                    ->whereDate('valid_from', $group['valid_from'])
                    ->first();

                if ($tariff === null) {
                    $tariff = WithholdingTariff::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'canton' => $group['canton'],
                        'tariff_code' => $group['tariff_code'],
                        'valid_from' => $group['valid_from'],
                        'valid_to' => $group['valid_to'],
                        'source' => $source,
                        'description' => "Imported tariff {$group['canton']} {$group['tariff_code']}",
                    ]);
                } else {
                    $tariff->update(['valid_to' => $group['valid_to'], 'source' => $source]);
                    WithholdingTariffBracket::withTrashed()->where('withholding_tariff_id', $tariff->id)->forceDelete();
                }

                foreach ($group['brackets'] as $bracket) {
                    WithholdingTariffBracket::create([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tariff->tenant_id,
                        'withholding_tariff_id' => $tariff->id,
                        'income_from_rappen' => $bracket['income_from_rappen'],
                        'income_to_rappen' => $bracket['income_to_rappen'],
                        'rate_ppm' => $bracket['rate_ppm'],
                        'fixed_amount_rappen' => $bracket['fixed_amount_rappen'],
                    ]);
                    $bracketCount++;
                }
            }

            return ['tariffs' => count($groups), 'brackets' => $bracketCount];
        });
    }

    /**
     * Reads the CSV into rows keyed by "rows.<line number>" for error reporting.
     *
     * @return array<string, array<string, string>>
     */
    private function parseCsv(string $csvContents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContents)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if (count($lines) < 2) {
            throw WithholdingTariffImportException::invalid('The tariff file is empty.', ['file' => ['The file must contain a header and at least one bracket row.']]);
        }

        $headerLine = (string) preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
        $delimiter = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
        $header = array_map(static fn (?string $column): string => strtolower(trim((string) $column)), str_getcsv($headerLine, $delimiter, '"', ''));
        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $header);

        if ($missingColumns !== []) {
            throw WithholdingTariffImportException::invalid(
                'The tariff file header is incomplete.',
                ['file' => ['Missing columns: '.implode(', ', $missingColumns).'.']],
            );
        }

        $rows = [];
        $problems = [];

        foreach (array_slice($lines, 1) as $index => $line) {
            $rowKey = 'rows.'.($index + 2);
            $values = str_getcsv($line, $delimiter, '"', '');

            if (count($values) !== count($header)) {
                $problems[$rowKey] = ['The row does not have the same number of columns as the header.'];

                continue;
            }

            $rows[$rowKey] = array_combine($header, array_map(static fn (?string $value): string => trim((string) $value), $values));
        }

        if ($problems !== []) {
            throw WithholdingTariffImportException::invalid('The tariff file contains invalid rows; nothing was imported.', $problems);
        }

        return $rows;
    }

    /**
     * Validates the rows and groups them into tariffs (canton + code + start date) with sorted brackets.
     *
     * @param  array<string, array<string, string>>  $rows
     * @return list<array{canton: string, tariff_code: string, valid_from: string, valid_to: string|null, brackets: list<array{income_from_rappen: int, income_to_rappen: int|null, rate_ppm: int, fixed_amount_rappen: int}>}>
     */
    private function group(array $rows): array
    {
        if ($rows === []) {
            throw WithholdingTariffImportException::invalid('No tariff brackets were provided.', ['brackets' => ['At least one bracket is required.']]);
        }

        $groups = [];
        $problems = [];

        foreach ($rows as $rowKey => $row) {
            try {
                $parsed = $this->parseRow($row);
            } catch (InvalidArgumentException $exception) {
                $problems[$rowKey] = [$exception->getMessage()];

                continue;
            }

            $key = $parsed['canton'].'|'.$parsed['tariff_code'].'|'.$parsed['valid_from'];
            $groups[$key] ??= [
                'canton' => $parsed['canton'],
                'tariff_code' => $parsed['tariff_code'],
                'valid_from' => $parsed['valid_from'],
                'valid_to' => $parsed['valid_to'],
                'brackets' => [],
            ];
            $groups[$key]['brackets'][] = $parsed['bracket'];
        }

        if ($problems !== []) {
            throw WithholdingTariffImportException::invalid('The tariff data contains invalid rows; nothing was imported.', $problems);
        }

        foreach ($groups as $key => $group) {
            usort($group['brackets'], static fn (array $left, array $right): int => $left['income_from_rappen'] <=> $right['income_from_rappen']);
            $previousUpperBound = -1;
            $lastIndex = count($group['brackets']) - 1;

            foreach ($group['brackets'] as $index => $bracket) {
                $openEnded = $bracket['income_to_rappen'] === null;

                if ($bracket['income_from_rappen'] <= $previousUpperBound || ($openEnded && $index !== $lastIndex)) {
                    $problems["{$group['canton']}.{$group['tariff_code']}.{$group['valid_from']}"] = ['The income brackets overlap or an open-ended bracket is not the last one.'];

                    break;
                }

                $previousUpperBound = $bracket['income_to_rappen'] ?? PHP_INT_MAX;
            }

            $groups[$key] = $group;
        }

        if ($problems !== []) {
            throw WithholdingTariffImportException::invalid('The tariff data contains overlapping brackets; nothing was imported.', $problems);
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{canton: string, tariff_code: string, valid_from: string, valid_to: string|null, bracket: array{income_from_rappen: int, income_to_rappen: int|null, rate_ppm: int, fixed_amount_rappen: int}}
     */
    private function parseRow(array $row): array
    {
        foreach (self::REQUIRED_COLUMNS as $column) {
            $row[$column] ??= '';
        }

        $canton = strtoupper($row['canton']);

        if (! in_array($canton, SwissCanton::values(), true)) {
            throw new InvalidArgumentException("Unknown canton '{$row['canton']}'.");
        }

        $tariffCode = strtoupper($row['tariff_code']);

        if (preg_match('/^[A-Z]\d{1,2}[YN]$/', $tariffCode) !== 1) {
            throw new InvalidArgumentException("Invalid tariff code '{$row['tariff_code']}' (expected e.g. A0N).");
        }

        $validFrom = $this->date($row['valid_from'], 'valid_from');
        $validTo = $row['valid_to'] === '' ? null : $this->date($row['valid_to'], 'valid_to');

        if ($validTo !== null && $validTo < $validFrom) {
            throw new InvalidArgumentException('valid_to must not be before valid_from.');
        }

        $incomeFrom = Rappen::fromDecimal($row['income_from']);
        $incomeTo = $row['income_to'] === '' ? null : Rappen::fromDecimal($row['income_to']);
        $ratePpm = Rappen::percentToPpm($row['rate_percent']);
        $fixedAmount = $row['fixed_amount'] === '' ? 0 : Rappen::fromDecimal($row['fixed_amount']);

        if ($incomeFrom < 0 || ($incomeTo !== null && $incomeTo < $incomeFrom)) {
            throw new InvalidArgumentException('The income range is invalid.');
        }

        if ($ratePpm < 0 || $ratePpm > Rappen::PPM_PER_UNIT || $fixedAmount < 0) {
            throw new InvalidArgumentException('rate_percent must be between 0 and 100 and fixed_amount must not be negative.');
        }

        return [
            'canton' => $canton,
            'tariff_code' => $tariffCode,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'bracket' => [
                'income_from_rappen' => $incomeFrom,
                'income_to_rappen' => $incomeTo,
                'rate_ppm' => $ratePpm,
                'fixed_amount_rappen' => $fixedAmount,
            ],
        ];
    }

    private function date(string $value, string $column): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$column} must be a date in the format YYYY-MM-DD.");
        }

        return $value;
    }
}
