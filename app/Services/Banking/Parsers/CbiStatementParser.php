<?php

declare(strict_types=1);

namespace App\Services\Banking\Parsers;

use App\Contracts\Banking\BankStatementParserInterface;
use App\DTOs\Banking\ParsedStatement;
use App\DTOs\Banking\ParsedStatementLine;
use App\Exceptions\BankStatementException;
use Carbon\CarbonImmutable;

/**
 * Italian CBI statement: fixed length records of exactly 120 characters, read line by line.
 *
 * | Record     | Content (0-based offset, length)                                                                  |
 * |------------|---------------------------------------------------------------------------------------------------|
 * | `RH`/`PC`  | header: flow id (2,15), account IBAN (17,27), currency (44,3), creation date (47,6), statement   |
 * |            | date (53,6), opening balance in cents (59,13) and its sign `C`/`D` (72,1); dates are `YYMMDD`    |
 * | `61`       | movement: progressive (2,6), booking date (8,6), value date (14,6), sign `C`/`D` (20,1), net      |
 * |            | amount in cents (21,12), ABI cause (33,3), counterpart IBAN (36,27), explicit fees in cents (63,12)|
 * | `62`       | description of the movement (8,*)                                                                 |
 * | `63`       | notes: `CRO`/`TRN` reference and `SPESE` fees in cents (8,*)                                      |
 * | `EF`       | trailer: flow id (2,15), closing balance in cents (17,12) and its sign (29,1), record count (30,20)|
 */
class CbiStatementParser implements BankStatementParserInterface
{
    public const RECORD_LENGTH = 120;

    public function parse(string $content): ParsedStatement
    {
        $content = ltrim($content, "\xEF\xBB\xBF");

        if (trim($content) === '') {
            throw BankStatementException::because(BankStatementException::EMPTY_FILE);
        }

        $header = null;
        $trailer = null;
        $movements = [];
        $current = null;
        $records = 0;
        $number = 0;

        for ($line = strtok($content, "\n"); $line !== false; $line = strtok("\n")) {
            $line = rtrim($line, "\r");

            if ($line === '') {
                continue;
            }

            $number++;

            if (strlen($line) !== self::RECORD_LENGTH) {
                throw BankStatementException::because(BankStatementException::INVALID_LINE_LENGTH, ['line' => $number, 'length' => strlen($line), 'expected' => self::RECORD_LENGTH]);
            }

            $type = substr($line, 0, 2);

            if ($trailer !== null) {
                throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => $type]);
            }

            if ($header === null && ! in_array($type, ['RH', 'PC'], true)) {
                throw BankStatementException::because(BankStatementException::MISSING_HEADER);
            }

            switch ($type) {
                case 'RH':
                case 'PC':
                    if ($header !== null) {
                        throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => $type]);
                    }

                    $header = $this->header($line, $number);
                    $records++;

                    break;
                case '61':
                    $this->closeMovement($current, $movements);
                    $current = $this->movement($line, $number);
                    $records++;

                    break;
                case '62':
                    $current = $this->description($current, $line, $number);
                    $records++;

                    break;
                case '63':
                    $current = $this->notes($current, $line, $number);
                    $records++;

                    break;
                case 'EF':
                    $this->closeMovement($current, $movements);
                    $trailer = $this->trailer($line, $number);

                    if ($trailer['record_count'] !== $records) {
                        throw BankStatementException::because(BankStatementException::RECORD_COUNT_MISMATCH, ['expected' => $trailer['record_count'], 'actual' => $records]);
                    }

                    break;
                default:
                    throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => $type]);
            }
        }

        if ($header === null) {
            throw BankStatementException::because(BankStatementException::MISSING_HEADER);
        }

        if ($trailer === null) {
            throw BankStatementException::because(BankStatementException::MISSING_TRAILER);
        }

        return new ParsedStatement(
            sourceFormat: ParsedStatement::FORMAT_CBI,
            accountIban: $header['iban'],
            statementDate: $header['statement_date'],
            openingBalance: $header['opening_balance'],
            closingBalance: $trailer['closing_balance'],
            currency: $header['currency'],
            lines: $movements,
        );
    }

    /**
     * @return array{iban: string, currency: string, statement_date: CarbonImmutable, opening_balance: float}
     */
    private function header(string $line, int $number): array
    {
        $iban = strtoupper(trim(substr($line, 17, 27)));
        $currency = strtoupper(substr($line, 44, 3));

        if ($iban === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => substr($line, 0, 2)]);
        }

        return [
            'iban' => $iban,
            'currency' => $currency,
            'statement_date' => $this->date(substr($line, 53, 6), $number),
            'opening_balance' => $this->signedAmount(substr($line, 59, 13), substr($line, 72, 1), $number),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movement(string $line, int $number): array
    {
        $sign = substr($line, 20, 1);

        if (! in_array($sign, ['C', 'D'], true)) {
            throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => '61']);
        }

        $iban = strtoupper(trim(substr($line, 36, 27)));

        return [
            'booking_date' => $this->date(substr($line, 8, 6), $number),
            'value_date' => $this->date(substr($line, 14, 6), $number),
            'direction' => $sign === 'C' ? ParsedStatementLine::CREDIT : ParsedStatementLine::DEBIT,
            'amount' => $this->cents(substr($line, 21, 12), $number),
            'abi_code' => trim(substr($line, 33, 3)) ?: null,
            'counterpart_iban' => $iban === '' ? null : $iban,
            'fee_amount' => trim(substr($line, 63, 12)) === '' ? 0.0 : $this->cents(substr($line, 63, 12), $number),
            'description' => '',
            'reference' => null,
            'qr_reference' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function description(?array $current, string $line, int $number): array
    {
        $current = $this->requireMovement($current, '62', $number);
        $current['description'] = trim($current['description'].' '.trim(substr($line, 8)));

        return $current;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function notes(?array $current, string $line, int $number): array
    {
        $current = $this->requireMovement($current, '63', $number);
        $text = trim(substr($line, 8));

        if (preg_match('/\b(?:CRO|TRN)\s+([A-Z0-9]+)/i', $text, $reference) === 1) {
            $current['reference'] = $reference[1];
        }

        if (preg_match('/\bSPESE\s+(\d+)\b/i', $text, $fees) === 1 && $current['fee_amount'] == 0.0) {
            $current['fee_amount'] = $this->cents($fees[1], $number);
        }

        if (preg_match('/(?<!\d)(\d{27})(?!\d)/', $text, $qr) === 1) {
            $current['qr_reference'] = $qr[1];
        }

        return $current;
    }

    /**
     * @return array{closing_balance: float, record_count: int}
     */
    private function trailer(string $line, int $number): array
    {
        $count = substr($line, 30, 20);

        if (! ctype_digit($count)) {
            throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => 'EF']);
        }

        return [
            'closing_balance' => $this->signedAmount(substr($line, 17, 12), substr($line, 29, 1), $number),
            'record_count' => (int) $count,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<int, ParsedStatementLine>  $movements
     */
    private function closeMovement(?array $current, array &$movements): void
    {
        if ($current === null) {
            return;
        }

        $movements[] = new ParsedStatementLine(
            bookingDate: $current['booking_date'],
            valueDate: $current['value_date'],
            amount: $current['amount'],
            feeAmount: $current['fee_amount'],
            direction: $current['direction'],
            abiCode: $current['abi_code'],
            reference: $current['reference'],
            qrReference: $current['qr_reference'],
            description: $current['description'] === '' ? null : $current['description'],
            counterpartName: null,
            counterpartIban: $current['counterpart_iban'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function requireMovement(?array $current, string $record, int $number): array
    {
        return $current ?? throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => $record]);
    }

    private function cents(string $digits, int $number): float
    {
        $digits = trim($digits);

        if ($digits === '' || ! ctype_digit($digits)) {
            throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => 'amount']);
        }

        return round(((int) $digits) / 100, 2);
    }

    private function signedAmount(string $digits, string $sign, int $number): float
    {
        return $this->cents($digits, $number) * ($sign === 'D' ? -1 : 1);
    }

    /**
     * A `YYMMDD` date.
     */
    private function date(string $value, int $number): CarbonImmutable
    {
        $date = preg_match('/^\d{6}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!ymd', $value) : false;

        if ($date === false || $date->format('ymd') !== $value) {
            throw BankStatementException::because(BankStatementException::INVALID_RECORD, ['line' => $number, 'record' => 'date']);
        }

        return $date;
    }
}
