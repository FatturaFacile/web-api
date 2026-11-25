<?php

declare(strict_types=1);

namespace App\Services\Banking\Parsers;

use App\Contracts\Banking\BankStatementParserInterface;
use App\DTOs\Banking\CsvStatementMapping;
use App\DTOs\Banking\ParsedStatement;
use App\DTOs\Banking\ParsedStatementLine;
use App\Exceptions\BankStatementException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Bank CSV export read through an explicit {@see CsvStatementMapping}. Nothing is guessed: a mapped column that is
 * missing, an unreadable date or amount refuses the whole file with the row number. Amounts are parsed as exact decimal
 * strings and rounded to cents (never through float arithmetic on the text).
 */
class CsvStatementParser implements BankStatementParserInterface
{
    private ?CsvStatementMapping $mapping = null;

    public function withMapping(CsvStatementMapping $mapping): static
    {
        $clone = clone $this;
        $clone->mapping = $mapping;

        return $clone;
    }

    public function parse(string $content): ParsedStatement
    {
        if ($this->mapping === null) {
            throw BankStatementException::because(BankStatementException::UNSUPPORTED_FORMAT);
        }

        return $this->parseWith($content, $this->mapping);
    }

    public function parseWith(string $content, CsvStatementMapping $mapping): ParsedStatement
    {
        $content = ltrim($content, "\xEF\xBB\xBF");

        if (trim($content) === '') {
            throw BankStatementException::because(BankStatementException::EMPTY_FILE);
        }

        if ($mapping->accountIban === '') {
            throw BankStatementException::because(BankStatementException::MISSING_HEADER);
        }

        $rows = $this->rows($content, $mapping->delimiter);
        $header = $mapping->hasHeader ? array_shift($rows) : null;
        $positions = $this->positions($mapping, $header);
        $lines = [];
        $number = $mapping->hasHeader ? 1 : 0;

        foreach ($rows as $row) {
            $number++;

            if (trim(implode('', array_map(static fn (mixed $cell): string => (string) $cell, $row))) === '') {
                continue;
            }

            try {
                $lines[] = $this->line($row, $positions, $mapping);
            } catch (InvalidArgumentException $e) {
                throw BankStatementException::because(BankStatementException::CSV_INVALID_ROW, ['line' => $number, 'reason' => $e->getMessage()]);
            }
        }

        if ($lines === []) {
            throw BankStatementException::because(BankStatementException::CSV_NO_ROWS);
        }

        $latest = $lines[0]->bookingDate;

        foreach ($lines as $line) {
            if ($line->bookingDate->greaterThan($latest)) {
                $latest = $line->bookingDate;
            }
        }

        return new ParsedStatement(ParsedStatement::FORMAT_CSV, $mapping->accountIban, $latest, null, null, $mapping->currency, $lines);
    }

    /**
     * @return list<list<string|null>>
     */
    private function rows(string $content, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, str_replace(["\r\n", "\r"], "\n", $content));
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /**
     * @param  list<string|null>|null  $header
     * @return array<string, int> field => zero-based index
     */
    private function positions(CsvStatementMapping $mapping, ?array $header): array
    {
        $normalized = $header === null ? [] : array_map(static fn (?string $name): string => mb_strtolower(trim((string) $name)), $header);
        $positions = [];

        foreach ($mapping->columns as $field => $column) {
            if (! in_array($field, CsvStatementMapping::FIELDS, true)) {
                continue;
            }

            if ($mapping->hasHeader) {
                $index = array_search(mb_strtolower(trim((string) $column)), $normalized, true);

                if ($index === false) {
                    throw BankStatementException::because(BankStatementException::CSV_INVALID_MAPPING, ['column' => (string) $column]);
                }

                $positions[$field] = (int) $index;
            } else {
                $positions[$field] = max(0, (int) $column - 1);
            }
        }

        if (! isset($positions['booking_date'])) {
            throw BankStatementException::because(BankStatementException::CSV_INVALID_MAPPING, ['column' => 'booking_date']);
        }

        if (! isset($positions['amount']) && ! isset($positions['debit']) && ! isset($positions['credit'])) {
            throw BankStatementException::because(BankStatementException::CSV_INVALID_MAPPING, ['column' => 'amount']);
        }

        return $positions;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $positions
     */
    private function line(array $row, array $positions, CsvStatementMapping $mapping): ParsedStatementLine
    {
        $cell = static function (string $field) use ($row, $positions): ?string {
            $value = isset($positions[$field]) ? trim((string) ($row[$positions[$field]] ?? '')) : '';

            return $value === '' ? null : $value;
        };

        $booking = $this->date((string) $cell('booking_date'), $mapping->dateFormat);
        $value = $cell('value_date') !== null ? $this->date((string) $cell('value_date'), $mapping->dateFormat) : $booking;
        [$direction, $amount] = $this->amount($cell('amount'), $cell('debit'), $cell('credit'), $mapping);
        $fee = $cell('fee') !== null ? ltrim($this->decimal((string) $cell('fee'), $mapping), '-') : '0.00';
        $qr = $cell('qr_reference');
        $iban = $cell('counterpart_iban');

        return new ParsedStatementLine(
            bookingDate: $booking,
            valueDate: $value,
            amount: (float) $amount,
            feeAmount: (float) $fee,
            direction: $direction,
            reference: $cell('reference'),
            qrReference: $qr !== null ? strtoupper((string) preg_replace('/\s+/', '', $qr)) : null,
            description: $cell('description'),
            counterpartName: $cell('counterpart_name'),
            counterpartIban: $iban !== null ? strtoupper((string) preg_replace('/\s+/', '', $iban)) : null,
            entryReference: $cell('entry_reference'),
        );
    }

    private function date(string $value, string $format): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!'.$format, $value);

        if (! $date instanceof CarbonImmutable || $date->format($format) !== $value) {
            throw new InvalidArgumentException("unreadable date '{$value}'");
        }

        return $date;
    }

    /**
     * @return array{0: string, 1: string} direction and positive amount as exact decimal text
     */
    private function amount(?string $amount, ?string $debit, ?string $credit, CsvStatementMapping $mapping): array
    {
        if ($amount !== null) {
            $decimal = $this->decimal($amount, $mapping);

            return bccomp($decimal, '0', 2) < 0
                ? [ParsedStatementLine::DEBIT, ltrim($decimal, '-')]
                : [ParsedStatementLine::CREDIT, $decimal];
        }

        if ($credit !== null && bccomp($this->decimal($credit, $mapping), '0', 2) !== 0) {
            return [ParsedStatementLine::CREDIT, ltrim($this->decimal($credit, $mapping), '-')];
        }

        if ($debit !== null && bccomp($this->decimal($debit, $mapping), '0', 2) !== 0) {
            return [ParsedStatementLine::DEBIT, ltrim($this->decimal($debit, $mapping), '-')];
        }

        throw new InvalidArgumentException('no amount');
    }

    private function decimal(string $value, CsvStatementMapping $mapping): string
    {
        $clean = str_replace([' ', "\u{00A0}", '+'], '', $value);

        if ($mapping->thousandsSeparator !== null) {
            $clean = str_replace($mapping->thousandsSeparator, '', $clean);
        }

        $clean = str_replace($mapping->decimalSeparator, '.', $clean);

        if (preg_match('/^-?\d+(\.\d+)?$/', $clean) !== 1) {
            throw new InvalidArgumentException("unreadable amount '{$value}'");
        }

        return bcadd($clean, '0', 2);
    }
}
