<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\F24;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Treasury\Models\F24Delegation;
use Modules\Treasury\Models\F24DelegationLine;

/**
 * Fixed-width (200 characters, CRLF) telematic flow of a Modello F24 delegation, one record per section line:
 *
 * | Record | Content                                                                                            |
 * |--------|----------------------------------------------------------------------------------------------------|
 * | `HD`   | flow header: flow id (8), creation date `YYYYMMDD` (8), layout version (4)                         |
 * | `TX`   | taxpayer: fiscal code (16), surname / company (60), first name (30), birth date `DDMMYYYY` (8),    |
 * |        | birth place (40), province (2), gender (1), payment date `DDMMYYYY` (8)                           |
 * | `ER` `IN` `RG` `IM` `IL` | Erario / INPS / Regioni / IMU-tributi locali / INAIL line: progressive (4), tributo or  |
 * |        | causale (8), institution code (10), reference number (17), installment (4), period `MMYYYY` (6),   |
 * |        | year (4), debit in cents (15), credit in cents (15)                                                |
 * | `TT`   | totals: debit (15), credit compensated (15), payable (15) in cents, number of lines (4)            |
 * | `EF`   | trailer: number of records including header and trailer (6)                                        |
 *
 * Numeric fields are zero padded on the left, text fields upper-case ASCII padded on the right. The flow is produced
 * deterministically from the validated delegation; submission through the channel of the bank or Entratel is a step
 * outside this application (the layout is the one agreed with the CBI / Agenzia delle Entrate gateway of the tenant).
 */
final class F24TelematicFileBuilder
{
    public const int RECORD_LENGTH = 200;

    private const array RECORD_TYPES = [
        F24DelegationLine::SECTION_ERARIO => 'ER',
        F24DelegationLine::SECTION_INPS => 'IN',
        F24DelegationLine::SECTION_REGIONI => 'RG',
        F24DelegationLine::SECTION_IMU => 'IM',
        F24DelegationLine::SECTION_INAIL => 'IL',
    ];

    /**
     * @param  Collection<int, F24DelegationLine>  $lines
     */
    public function build(F24Delegation $delegation, Collection $lines, CarbonImmutable $createdAt): string
    {
        $records = [];
        $records[] = $this->record('HD', [
            [substr(strtoupper(str_replace('-', '', (string) $delegation->id)), 0, 8), 8, false],
            [$createdAt->format('Ymd'), 8, true],
            ['0100', 4, true],
        ]);
        $records[] = $this->record('TX', [
            [(string) $delegation->taxpayer_fiscal_code, 16, false],
            [(string) $delegation->taxpayer_name, 60, false],
            [(string) $delegation->taxpayer_first_name, 30, false],
            [$delegation->taxpayer_birth_date?->format('dmY') ?? '', 8, true],
            [(string) $delegation->taxpayer_birth_place, 40, false],
            [(string) $delegation->taxpayer_birth_province, 2, false],
            [(string) $delegation->taxpayer_gender, 1, false],
            [$delegation->payment_date->format('dmY'), 8, true],
        ]);

        $sorted = $lines->sortBy(fn (F24DelegationLine $line): string => sprintf('%d-%05d', array_search($line->section, F24DelegationLine::SECTIONS, true), $line->position))->values();
        $debit = '0.00';
        $credit = '0.00';

        foreach ($sorted as $index => $line) {
            $debit = bcadd($debit, (string) $line->debit_amount, 2);
            $credit = bcadd($credit, (string) $line->credit_amount, 2);
            $records[] = $this->record(self::RECORD_TYPES[$line->section], [
                [(string) ($index + 1), 4, true],
                [(string) $line->tax_code, 8, false],
                [(string) $line->institution_code, 10, false],
                [(string) $line->reference_number, 17, false],
                [(string) $line->installment, 4, false],
                [(string) $line->reference_period, 6, false],
                [(string) $line->reference_year, 4, false],
                [$this->cents((string) $line->debit_amount), 15, true],
                [$this->cents((string) $line->credit_amount), 15, true],
            ]);
        }

        $records[] = $this->record('TT', [
            [$this->cents($debit), 15, true],
            [$this->cents($credit), 15, true],
            [$this->cents(bcsub($debit, $credit, 2)), 15, true],
            [(string) $sorted->count(), 4, true],
        ]);
        $records[] = $this->record('EF', [[(string) (count($records) + 1), 6, true]]);

        return implode("\r\n", $records)."\r\n";
    }

    /**
     * @param  list<array{0: string, 1: int, 2: bool}>  $fields  value, length, numeric
     */
    private function record(string $type, array $fields): string
    {
        $line = $type;

        foreach ($fields as [$value, $length, $numeric]) {
            $line .= $numeric
                ? str_pad(substr(preg_replace('/\D/', '', $value) ?? '', -$length), $length, '0', STR_PAD_LEFT)
                : str_pad(substr($this->ascii($value), 0, $length), $length);
        }

        return str_pad($line, self::RECORD_LENGTH);
    }

    private function cents(string $amount): string
    {
        return ltrim(bcmul(bcadd($amount, '0', 2), '100', 0), '-') ?: '0';
    }

    private function ascii(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper(trim($value))) ?: '';

        return (string) preg_replace('/[^A-Z0-9 .,\'\/\-]/', '', strtoupper($folded));
    }
}
