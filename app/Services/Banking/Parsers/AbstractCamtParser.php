<?php

declare(strict_types=1);

namespace App\Services\Banking\Parsers;

use App\Contracts\Banking\BankStatementParserInterface;
use App\DTOs\Banking\ParsedStatement;
use App\DTOs\Banking\ParsedStatementLine;
use App\Exceptions\BankStatementException;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use XMLReader;

/**
 * Shared ISO 20022 reading for CAMT.053 statements and CAMT.054 notifications. The document is streamed with
 * `XMLReader`: only the account, the balances and one entry at a time are held in memory.
 *
 * The amount of an entry is the net amount booked; charges (`Chrgs`) become the fee withheld by the bank. The creditor
 * reference of a structured remittance (a 27 digit Swiss QR reference or an ISO 11649 `RF` reference) is extracted so
 * payments can be matched to invoices deterministically.
 */
abstract class AbstractCamtParser implements BankStatementParserInterface
{
    /**
     * Entry statuses that are not booked yet.
     */
    private const NOT_BOOKED = ['PDNG', 'INFO', 'FUTR'];

    /**
     * One of the `ParsedStatement::FORMAT_*` constants.
     */
    abstract protected function format(): string;

    /**
     * The element that holds the account, balances and entries: `Stmt` or `Ntfctn`.
     */
    abstract protected function containerElement(): string;

    public function parse(string $content): ParsedStatement
    {
        $content = ltrim($content, "\xEF\xBB\xBF \t\r\n");

        if ($content === '') {
            throw BankStatementException::because(BankStatementException::EMPTY_FILE);
        }

        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $content) === 1) {
            throw BankStatementException::because(BankStatementException::UNSAFE_XML);
        }

        $reader = new XMLReader;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (! $reader->XML($content, null, LIBXML_NONET | LIBXML_NOCDATA)) {
                throw BankStatementException::because(BankStatementException::INVALID_XML);
            }

            $statement = $this->read($reader);

            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw BankStatementException::because(BankStatementException::INVALID_XML);
                }
            }

            return $statement;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function read(XMLReader $reader): ParsedStatement
    {
        $container = $this->containerElement();
        $inside = false;
        $found = false;
        $iban = null;
        $currency = null;
        $created = null;
        $opening = null;
        $closing = null;
        $closingDate = null;
        $lines = [];

        $ok = $reader->read();

        while ($ok) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                $name = $reader->localName;

                if ($name === $container) {
                    if ($found) {
                        // Only the first statement of a file is read.
                        $ok = $reader->next();

                        continue;
                    }

                    $found = $inside = true;
                    $ok = $reader->read();

                    continue;
                }

                if ($inside && in_array($name, ['Acct', 'Bal', 'Ntry'], true)) {
                    $node = $this->fragment($reader);
                    if ($name === 'Ntry') {
                        $currency ??= $this->nullable((string) $node->Amt['Ccy']);
                    }

                    match ($name) {
                        'Acct' => [$iban, $currency] = [$iban ?? $this->accountIban($node), $currency ?? $this->nullable((string) $node->Ccy)],
                        'Bal' => $this->readBalance($node, $opening, $closing, $closingDate, $currency),
                        default => array_push($lines, ...$this->entryLines($node, $currency ?? 'EUR')),
                    };

                    $ok = $reader->next();

                    continue;
                }

                if ($inside && $name === 'CreDtTm' && $created === null) {
                    $created = $this->date($reader->readString());
                }
            }

            $ok = $reader->read();
        }

        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                throw BankStatementException::because(BankStatementException::INVALID_XML);
            }
        }

        if (! $found) {
            throw BankStatementException::because(BankStatementException::NO_STATEMENT);
        }

        if ($iban === null || $iban === '') {
            throw BankStatementException::because(BankStatementException::MISSING_HEADER);
        }

        $latestBooking = $lines === [] ? null : max(array_map(fn (ParsedStatementLine $line): CarbonImmutable => $line->bookingDate, $lines));

        return new ParsedStatement(
            sourceFormat: $this->format(),
            accountIban: $iban,
            statementDate: $closingDate ?? $latestBooking ?? $created ?? CarbonImmutable::today(),
            openingBalance: $opening,
            closingBalance: $closing,
            currency: $currency ?? 'EUR',
            lines: $lines,
        );
    }

    /**
     * The element the reader stands on as a namespace free `SimpleXMLElement`.
     */
    private function fragment(XMLReader $reader): SimpleXMLElement
    {
        $xml = (string) $reader->readOuterXml();
        $xml = (string) preg_replace(['/\sxmlns(:\w+)?="[^"]*"/', '/\s\w+:\w+="[^"]*"/', '/<(\/?)\w+:/'], ['', '', '<$1'], $xml);

        $node = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        if ($node === false) {
            throw BankStatementException::because(BankStatementException::INVALID_XML);
        }

        return $node;
    }

    private function accountIban(SimpleXMLElement $account): ?string
    {
        $iban = (string) ($account->Id->IBAN ?? '') ?: (string) ($account->Id->Othr->Id ?? '');

        return $iban === '' ? null : strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /**
     * Opening (`OPBD`, `PRCD`) and closing (`CLBD`) booked balances; debit balances are negative.
     */
    private function readBalance(SimpleXMLElement $balance, ?float &$opening, ?float &$closing, ?CarbonImmutable &$closingDate, ?string &$currency): void
    {
        $code = (string) ($balance->Tp->CdOrPrtry->Cd ?? '');
        $amount = round((float) $balance->Amt * ((string) $balance->CdtDbtInd === 'DBIT' ? -1 : 1), 2);
        $currency ??= $this->nullable((string) $balance->Amt['Ccy']);

        if (in_array($code, ['OPBD', 'PRCD'], true)) {
            $opening ??= $amount;
        } elseif ($code === 'CLBD') {
            $closing = $amount;
            $closingDate = $this->date((string) ($balance->Dt->Dt ?? $balance->Dt->DtTm ?? ''));
        }
    }

    /**
     * The lines of one entry: one per transaction detail when an entry bundles several, otherwise a single line.
     *
     * @return array<int, ParsedStatementLine>
     */
    private function entryLines(SimpleXMLElement $entry, string $defaultCurrency): array
    {
        $status = (string) ($entry->Sts->Cd ?? $entry->Sts ?? '');

        if (in_array(strtoupper($status), self::NOT_BOOKED, true) || strtolower((string) $entry->RvslInd) === 'true') {
            return [];
        }

        $direction = (string) $entry->CdtDbtInd === 'DBIT' ? ParsedStatementLine::DEBIT : ParsedStatementLine::CREDIT;
        $bookingDate = $this->date((string) ($entry->BookgDt->Dt ?? $entry->BookgDt->DtTm ?? '')) ?? $this->date((string) ($entry->ValDt->Dt ?? $entry->ValDt->DtTm ?? '')) ?? CarbonImmutable::today();
        $valueDate = $this->date((string) ($entry->ValDt->Dt ?? $entry->ValDt->DtTm ?? '')) ?? $bookingDate;
        $transactions = $entry->xpath('NtryDtls/TxDtls') ?: [];
        $split = count($transactions) > 1;
        $fee = $this->fee($entry);
        $lines = [];

        foreach ($split ? $transactions : [$transactions[0] ?? null] as $index => $transaction) {
            $amount = $split ? (float) ($transaction->AmtDtls->TxAmt->Amt ?? $transaction->Amt ?? 0) : (float) $entry->Amt;

            $lines[] = new ParsedStatementLine(
                bookingDate: $bookingDate,
                valueDate: $valueDate,
                amount: round(abs($amount), 2),
                feeAmount: $index === 0 ? $fee : 0.0,
                direction: $direction,
                abiCode: $this->transactionCode($transaction),
                reference: $this->reference($entry, $transaction),
                qrReference: $this->creditorReference($entry, $transaction),
                description: $this->description($entry, $transaction),
                counterpartName: $this->counterpart($transaction, $direction, 'Nm'),
                counterpartIban: $this->counterpartIban($transaction, $direction),
            );
        }

        return $lines;
    }

    /**
     * Charges taken by the bank, from the entry or, failing that, from its transaction details.
     */
    private function fee(SimpleXMLElement $entry): float
    {
        foreach (['Chrgs/Amt', 'Chrgs/TtlChrgsAndTaxAmt', 'Chrgs/Rcrd/Amt', 'NtryDtls/TxDtls/Chrgs/Amt', 'NtryDtls/TxDtls/Chrgs/TtlChrgsAndTaxAmt', 'NtryDtls/TxDtls/Chrgs/Rcrd/Amt'] as $path) {
            $nodes = $entry->xpath($path) ?: [];

            if ($nodes !== []) {
                return round(array_sum(array_map(fn (SimpleXMLElement $node): float => abs((float) $node), $nodes)), 2);
            }
        }

        return 0.0;
    }

    /**
     * The purpose code of the transaction (SALA for salaries, TAXS for taxes, ...), kept like the ABI cause of CBI.
     */
    private function transactionCode(?SimpleXMLElement $transaction): ?string
    {
        $code = (string) ($transaction?->Purp->Cd ?? '') ?: (string) ($transaction?->Purp->Prtry ?? '');

        return $this->nullable(strtoupper($code));
    }

    private function reference(SimpleXMLElement $entry, ?SimpleXMLElement $transaction): ?string
    {
        $candidates = [
            (string) ($transaction?->Refs->EndToEndId ?? ''),
            (string) ($transaction?->Refs->AcctSvcrRef ?? ''),
            (string) ($transaction?->Refs->TxId ?? ''),
            (string) ($entry->AcctSvcrRef ?? ''),
            (string) ($entry->NtryRef ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && strtoupper($candidate) !== 'NOTPROVIDED') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The creditor reference of a structured remittance: a 27 digit QR reference or an ISO 11649 reference (`RF...`).
     */
    private function creditorReference(SimpleXMLElement $entry, ?SimpleXMLElement $transaction): ?string
    {
        $nodes = ($transaction?->xpath('RmtInf/Strd/CdtrRefInf/Ref') ?: []) ?: ($entry->xpath('NtryDtls/TxDtls/RmtInf/Strd/CdtrRefInf/Ref') ?: []);

        foreach ($nodes as $node) {
            $reference = strtoupper((string) preg_replace('/\s+/', '', (string) $node));

            if (preg_match('/^\d{27}$/', $reference) === 1 || preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $reference) === 1) {
                return $reference;
            }
        }

        return null;
    }

    private function description(SimpleXMLElement $entry, ?SimpleXMLElement $transaction): ?string
    {
        $parts = [];

        foreach ($transaction?->xpath('RmtInf/Ustrd') ?: [] as $text) {
            $parts[] = (string) $text;
        }

        foreach ($transaction?->xpath('RmtInf/Strd/AddtlRmtInf') ?: [] as $text) {
            $parts[] = (string) $text;
        }

        if ($parts === []) {
            $parts[] = (string) ($transaction?->AddtlTxInf ?? '') ?: (string) ($entry->AddtlNtryInf ?? '');
        }

        $description = trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));

        return $description === '' ? null : $description;
    }

    private function counterpart(?SimpleXMLElement $transaction, string $direction, string $field): ?string
    {
        $party = $direction === ParsedStatementLine::CREDIT ? 'Dbtr' : 'Cdtr';
        $value = (string) ($transaction?->RltdPties->{$party}->{$field} ?? '') ?: (string) ($transaction?->RltdPties->{$party}->Pty->{$field} ?? '');

        return $this->nullable($value);
    }

    private function counterpartIban(?SimpleXMLElement $transaction, string $direction): ?string
    {
        $account = $direction === ParsedStatementLine::CREDIT ? 'DbtrAcct' : 'CdtrAcct';
        $iban = (string) ($transaction?->RltdPties->{$account}->Id->IBAN ?? '');

        return $iban === '' ? null : strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value)->startOfDay() : null;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
