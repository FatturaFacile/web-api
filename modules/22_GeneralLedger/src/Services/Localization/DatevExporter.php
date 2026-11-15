<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Localization;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * DATEV export of the posted journal entries of a period: the "Buchungsstapel" of the DATEV format (EXTF, version 700,
 * format category 21 "Buchungsstapel", format version 13).
 *
 * File layout, as documented by DATEV:
 *  - line 1, the header: 31 semicolon separated fields (identifier "EXTF", version 700, category 21, name
 *    "Buchungsstapel", version 13, creation timestamp, origin, consultant number, client number, start of the fiscal
 *    year, account length, period from and to, label, currency "EUR", ...);
 *  - line 2, the column headings, and then one line per booking with the first 14 columns of the format: amount
 *    without sign (decimal comma), debit/credit flag S, currency, rate, base amount, base currency, account (Konto),
 *    offsetting account (Gegenkonto), tax key (BU-Schluessel), document date (DDMM), document field 1 (Belegfeld 1),
 *    document field 2, cash discount, booking text. DATEV accepts rows shorter than the 125 columns of the format;
 *  - text fields are enclosed in double quotes (doubled inside), numbers are not; lines end with CRLF; the file is
 *    encoded in Windows-1252.
 *
 * A DATEV booking has two accounts, a journal entry has any number of lines: the debit and credit lines of an entry are
 * paired in order, splitting an amount where one line covers several of the other side, so every row is one amount
 * between one debit and one credit account and the rows of an entry add up to the entry. The tax key is left empty:
 * the VAT of an entry is posted on its own VAT account lines, which DATEV imports as ordinary bookings. Amounts are
 * those of the accounting currency of the tenant. Accounts must be numeric (DATEV accounts are numbers).
 */
final class DatevExporter
{
    public const string Version = '700';

    /** @var list<string> */
    public const array Columns = [
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz', 'WKZ Basis-Umsatz', 'Konto',
        'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext',
    ];

    public function fileName(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return "EXTF_Buchungsstapel_{$from->format('Ymd')}_{$to->format('Ymd')}.csv";
    }

    /**
     * @return array{content: string, rows: int}
     *
     * @throws ValidationException when a booked account is not numeric
     */
    public function build(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, int $consultantNumber = 1, int $clientNumber = 1, int $accountLength = 4, ?Carbon $createdAt = null): array
    {
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereDate('posting_date', '>=', $from->toDateString())
            ->whereDate('posting_date', '<=', $to->toDateString())
            ->with(['lines' => fn ($query) => $query->withoutGlobalScopes()->with(['account' => fn ($account) => $account->withoutGlobalScopes()])->orderBy('line_number')])
            ->orderBy('posting_date')
            ->orderBy('journal_number')
            ->orderBy('entry_number')
            ->get();

        $currency = TenantAccountingCurrency::of($tenantId);
        $rows = [];
        $invalidAccounts = [];

        foreach ($entries as $entry) {
            foreach ($this->pairs($entry, $invalidAccounts) as $pair) {
                $rows[] = $this->row($entry, $pair, $currency);
            }
        }

        if ($invalidAccounts !== []) {
            throw ValidationException::withMessages(['from' => [__('validation.datev_non_numeric_accounts', ['accounts' => implode(', ', array_keys($invalidAccounts))])]]);
        }

        $lines = [$this->header($from, $to, $consultantNumber, $clientNumber, $accountLength, $createdAt ?? now(), $currency)];
        $lines[] = implode(';', array_map(fn (string $column): string => $this->text($column), self::Columns));

        foreach ($rows as $row) {
            $lines[] = implode(';', $row);
        }

        return ['content' => mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'Windows-1252', 'UTF-8'), 'rows' => count($rows)];
    }

    private function header(CarbonImmutable $from, CarbonImmutable $to, int $consultantNumber, int $clientNumber, int $accountLength, Carbon $createdAt, string $currency): string
    {
        $fields = [
            $this->text('EXTF'), self::Version, '21', $this->text('Buchungsstapel'), '13', $createdAt->format('YmdHis').'000', '',
            $this->text('XX'), $this->text(''), $this->text(''), (string) $consultantNumber, (string) $clientNumber,
            $from->startOfYear()->format('Ymd'), (string) $accountLength, $from->format('Ymd'), $to->format('Ymd'),
            $this->text('Journal entries'), $this->text(''), '1', '0', '0', $this->text($currency),
            '', '', '', '', '', '', '', '', '',
        ];

        return implode(';', $fields);
    }

    /**
     * @param  array{debit: string, credit: string, amount: string}  $pair
     * @return list<string>
     */
    private function row(JournalEntry $entry, array $pair, string $currency): array
    {
        return [
            str_replace('.', ',', $pair['amount']),
            $this->text('S'),
            $this->text($currency),
            '',
            '',
            '',
            $pair['debit'],
            $pair['credit'],
            '',
            Carbon::parse($entry->document_date ?? $entry->posting_date)->format('dm'),
            $this->text(mb_substr((string) ($entry->getAttribute('document_number') ?: $entry->entry_number), 0, 36)),
            '',
            '',
            $this->text(mb_substr((string) ($pair['text'] ?? $entry->description), 0, 60)),
        ];
    }

    /**
     * @param  array<string, true>  $invalidAccounts  receives the non-numeric account codes
     * @return list<array{debit: string, credit: string, amount: string, text: string}>
     */
    private function pairs(JournalEntry $entry, array &$invalidAccounts): array
    {
        $debits = [];
        $credits = [];

        /** @var JournalLine $line */
        foreach ($entry->lines as $line) {
            $code = (string) $line->account?->account_code;

            if (preg_match('/^\d+$/', $code) !== 1) {
                $invalidAccounts[$code] = true;

                continue;
            }

            $net = Decimal::sub((string) ($line->functional_debit ?? $line->debit_amount), (string) ($line->functional_credit ?? $line->credit_amount));
            $text = (string) ($line->description ?: $entry->description);

            if (Decimal::isPositive($net)) {
                $debits[] = ['account' => $code, 'amount' => $net, 'text' => $text];
            } elseif (Decimal::isNegative($net)) {
                $credits[] = ['account' => $code, 'amount' => Decimal::neg($net), 'text' => $text];
            }
        }

        $pairs = [];

        while ($debits !== [] && $credits !== []) {
            $take = Decimal::compare($debits[0]['amount'], $credits[0]['amount']) <= 0 ? $debits[0]['amount'] : $credits[0]['amount'];
            $amount = Decimal::round2($take);

            if (! Decimal::isZero($amount)) {
                $pairs[] = ['debit' => $debits[0]['account'], 'credit' => $credits[0]['account'], 'amount' => $amount, 'text' => $debits[0]['text']];
            }

            $debits[0]['amount'] = Decimal::sub($debits[0]['amount'], $take);
            $credits[0]['amount'] = Decimal::sub($credits[0]['amount'], $take);

            if (Decimal::isZero(Decimal::round2($debits[0]['amount']))) {
                array_shift($debits);
            }

            if (Decimal::isZero(Decimal::round2($credits[0]['amount'] ?? '0'))) {
                array_shift($credits);
            }
        }

        return $pairs;
    }

    private function text(string $value): string
    {
        return '"'.str_replace('"', '""', trim(preg_replace('/[\r\n;]+/', ' ', $value) ?? '')).'"';
    }
}
