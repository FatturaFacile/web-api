<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\DTOs\Accounting\LedgerEntry;
use App\DTOs\Accounting\LedgerLine;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\SupplierPayment;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the general ledger (prima nota) of a period as balanced double-entry postings, from the operational records of
 * the current tenant:
 *
 * - sales invoices and credit notes: Dr receivables / Cr revenue and output VAT;
 * - supplier invoices (and credit notes): Dr costs and input VAT / Cr payables;
 * - customer payments: Dr bank, bank fees and write-offs / Cr receivables;
 * - supplier payments: Dr payables and bank charges / Cr bank (and rounding income for forgiven debt);
 * - categorised bank lines (F24 / taxes, payroll, bank expenses, other expenses, internal transfers).
 *
 * Amounts are handled in integer cents, so every entry balances exactly; a difference between the total of a document
 * and its net plus tax (rounding, stamp duty, ...) is posted on the rounding account.
 */
class GeneralLedgerExportService
{
    public const string SOURCE_ALL = 'all';

    public const string SOURCE_INVOICES = 'invoices';

    public const string SOURCE_PAYMENTS = 'payments';

    public const string SOURCE_BANKING = 'banking';

    public const string DATE_ISO = 'YYYY-MM-DD';

    public const string DATE_EUROPEAN = 'DD/MM/YYYY';

    public const array HEADER = ['entry_number', 'entry_date', 'document_type', 'document_number', 'document_date', 'counterpart_name', 'tax_id', 'ledger_account_code', 'ledger_account_name', 'description', 'debit', 'credit', 'currency', 'vat_code'];

    private const array SOURCE_RANK = [self::SOURCE_INVOICES => 1, self::SOURCE_PAYMENTS => 2, self::SOURCE_BANKING => 3];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The numbered entries of the period, in date order. `$accountCode` keeps the entries that touch that account.
     *
     * @return array<int, LedgerEntry>
     */
    public function entries(CarbonImmutable $from, CarbonImmutable $to, string $source = self::SOURCE_ALL, ?string $accountCode = null): array
    {
        $accounts = LedgerAccounts::forTenant((string) $this->tenantContext->getTenantId());
        $entries = [];

        if (in_array($source, [self::SOURCE_ALL, self::SOURCE_INVOICES], true)) {
            array_push($entries, ...$this->salesInvoices($from, $to, $accounts), ...$this->supplierInvoices($from, $to, $accounts));
        }

        if (in_array($source, [self::SOURCE_ALL, self::SOURCE_PAYMENTS], true)) {
            array_push($entries, ...$this->customerPayments($from, $to, $accounts), ...$this->supplierPayments($from, $to, $accounts));
        }

        if (in_array($source, [self::SOURCE_ALL, self::SOURCE_BANKING], true)) {
            array_push($entries, ...$this->bankLines($from, $to, $accounts));
        }

        usort($entries, fn (LedgerEntry $a, LedgerEntry $b): int => [$a->date->toDateString(), self::SOURCE_RANK[$a->source], $a->sortKey] <=> [$b->date->toDateString(), self::SOURCE_RANK[$b->source], $b->sortKey]);

        foreach ($entries as $index => $entry) {
            $entry->number = sprintf('%06d', $index + 1);
        }

        if ($accountCode !== null && $accountCode !== '') {
            $entries = array_values(array_filter($entries, fn (LedgerEntry $entry): bool => collect($entry->lines)->contains(fn (LedgerLine $line): bool => $line->accountCode === $accountCode)));
        }

        return $entries;
    }

    /**
     * Totals of a set of entries, with the balance check of the whole period and of every entry.
     *
     * @param  array<int, LedgerEntry>  $entries
     * @return array{entries: int, lines: int, total_debit: float, total_credit: float, balanced: bool, unbalanced_entries: array<int, string>}
     */
    public function totals(array $entries): array
    {
        $debit = array_sum(array_map(fn (LedgerEntry $entry): int => $entry->totalDebit(), $entries));
        $credit = array_sum(array_map(fn (LedgerEntry $entry): int => $entry->totalCredit(), $entries));

        return [
            'entries' => count($entries),
            'lines' => array_sum(array_map(fn (LedgerEntry $entry): int => count($entry->lines), $entries)),
            'total_debit' => $debit / 100,
            'total_credit' => $credit / 100,
            'balanced' => $debit === $credit,
            'unbalanced_entries' => array_values(array_map(fn (LedgerEntry $entry): string => $entry->number, array_filter($entries, fn (LedgerEntry $entry): bool => ! $entry->isBalanced()))),
        ];
    }

    /**
     * One entry as an array, with its lines.
     *
     * @return array<string, mixed>
     */
    public function present(LedgerEntry $entry, string $dateFormat = self::DATE_ISO): array
    {
        return [
            'entry_number' => $entry->number,
            'entry_date' => $this->formatDate($entry->date, $dateFormat),
            'document_type' => $entry->documentType,
            'document_number' => $entry->documentNumber,
            'document_date' => $entry->documentDate === null ? null : $this->formatDate($entry->documentDate, $dateFormat),
            'counterpart_name' => $entry->counterpartName,
            'tax_id' => $entry->taxId,
            'currency' => $entry->currency,
            'total_debit' => $entry->totalDebit() / 100,
            'total_credit' => $entry->totalCredit() / 100,
            'lines' => array_map(fn (LedgerLine $line): array => [
                'ledger_account_code' => $line->accountCode,
                'ledger_account_name' => $line->accountName,
                'description' => $line->description,
                'debit' => $line->debit / 100,
                'credit' => $line->credit / 100,
                'vat_code' => $line->vatCode,
            ], $entry->lines),
        ];
    }

    /**
     * Write the CSV of the entries through `$write`: UTF-8 with a byte order mark (so Excel opens it correctly),
     * `;` as delimiter, `,` as decimal separator with two decimals, and the standard header.
     *
     * @param  array<int, LedgerEntry>  $entries
     * @param  callable(string): void  $write
     */
    public function writeCsv(array $entries, string $dateFormat, callable $write): void
    {
        $write("\xEF\xBB\xBF".implode(';', self::HEADER)."\r\n");

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $write(implode(';', [
                    $this->text($entry->number),
                    $this->formatDate($entry->date, $dateFormat),
                    $this->text($entry->documentType),
                    $this->text($entry->documentNumber),
                    $entry->documentDate === null ? '' : $this->formatDate($entry->documentDate, $dateFormat),
                    $this->text((string) $entry->counterpartName),
                    $this->text((string) $entry->taxId),
                    $this->text($line->accountCode),
                    $this->text($line->accountName),
                    $this->text($line->description),
                    $this->amount($line->debit),
                    $this->amount($line->credit),
                    $this->text($entry->currency),
                    $this->text((string) $line->vatCode),
                ])."\r\n");
            }
        }
    }

    private function formatDate(CarbonImmutable $date, string $format): string
    {
        return $date->format($format === self::DATE_EUROPEAN ? 'd/m/Y' : 'Y-m-d');
    }

    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    /**
     * A text cell: quoted when it holds a delimiter, quote or line break, and neutralised when a spreadsheet could
     * read it as a formula.
     */
    private function text(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        return preg_match('/[;"\r\n]/', $value) === 1 ? '"'.str_replace('"', '""', $value).'"' : $value;
    }

    /**
     * @return array<int, LedgerEntry>
     */
    private function salesInvoices(CarbonImmutable $from, CarbonImmutable $to, LedgerAccounts $accounts): array
    {
        $invoices = Invoice::query()->with('contact')->where('invoice_type', '!=', 'proforma')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('invoice_number')->get();
        $rates = $this->vatCodes('invoice_items', 'invoice_id', $invoices->pluck('id')->all());
        $entries = [];

        foreach ($invoices as $invoice) {
            $net = $this->cents($invoice->total_net);
            $tax = $this->cents($invoice->total_tax);
            $gross = $this->cents($invoice->total_gross);

            if ($gross === 0 && $net === 0) {
                continue;
            }

            $credit = $invoice->invoice_type === 'credit_note';
            $number = (string) $invoice->invoice_number;
            $vat = $rates[$invoice->id] ?? null;
            $lines = [
                $this->line($accounts, 'receivables', __('accounting.descriptions.receivable', ['number' => $number]), $gross, true),
                $this->line($accounts, 'revenue', __('accounting.descriptions.revenue', ['number' => $number]), $net, false, $vat),
            ];

            if ($tax !== 0) {
                $lines[] = $this->line($accounts, 'output_vat', __('accounting.descriptions.output_vat', ['number' => $number]), $tax, false, $vat);
            }

            $entries[] = $this->entry(
                $invoice->date, self::SOURCE_INVOICES, "{$number}|{$invoice->id}", $credit ? 'credit_note' : 'invoice', $number, $invoice->date,
                $this->contactName($invoice->contact), $this->taxId($invoice->contact), (string) $invoice->currency,
                $this->balanced($accounts, $lines, $credit),
            );
        }

        return $entries;
    }

    /**
     * @return array<int, LedgerEntry>
     */
    private function supplierInvoices(CarbonImmutable $from, CarbonImmutable $to, LedgerAccounts $accounts): array
    {
        $invoices = PassiveInvoice::query()->with('supplier')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('invoice_number')->get();
        $entries = [];

        foreach ($invoices as $invoice) {
            $gross = $this->cents($invoice->total_amount);
            $tax = $this->cents($invoice->total_tax);
            $net = $this->cents($invoice->total_net) ?: $gross - $tax;

            if ($gross === 0 && $net === 0) {
                continue;
            }

            $credit = $invoice->document_type === 'TD04';
            $number = (string) $invoice->invoice_number;
            $lines = [
                $this->line($accounts, 'cost', __('accounting.descriptions.cost', ['number' => $number]), $net, true),
            ];

            if ($tax !== 0) {
                $lines[] = $this->line($accounts, 'input_vat', __('accounting.descriptions.input_vat', ['number' => $number]), $tax, true);
            }

            $lines[] = $this->line($accounts, 'payables', __('accounting.descriptions.payable', ['number' => $number]), $gross, false);

            $entries[] = $this->entry(
                $invoice->date, self::SOURCE_INVOICES, "{$number}|{$invoice->id}", $credit ? 'supplier_credit_note' : 'supplier_invoice', $number, $invoice->date,
                $this->contactName($invoice->supplier), $this->taxId($invoice->supplier), (string) $invoice->currency,
                $this->balanced($accounts, $lines, $credit),
            );
        }

        return $entries;
    }

    /**
     * Receipts: the payments of one bank line (net amount, bank fee, write-off) form one entry.
     *
     * @return array<int, LedgerEntry>
     */
    private function customerPayments(CarbonImmutable $from, CarbonImmutable $to, LedgerAccounts $accounts): array
    {
        $payments = Payment::query()->with('invoice.contact')->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])->orderBy('paid_at')->orderBy('created_at')->get();
        $ibans = $this->ibansByBankLine($payments->pluck('bank_statement_line_id')->filter()->unique()->all());
        $entries = [];

        foreach ($payments->groupBy(fn (Payment $payment): string => $payment->bank_statement_line_id ?? 'payment-'.$payment->id) as $group) {
            /** @var Payment $first */
            $first = $group->first();
            $invoice = $first->invoice;
            $number = (string) $invoice?->invoice_number;
            $byType = $this->sumByType($group);
            $total = array_sum($byType);

            if ($total === 0) {
                continue;
            }

            $lines = [];
            $description = __('accounting.descriptions.receipt', ['number' => $number]);

            if ($byType['payment'] > 0) {
                $lines[] = $this->bankLine($accounts, $ibans[$first->bank_statement_line_id] ?? null, $description, $byType['payment'], true);
            }

            if ($byType['bank_fee'] > 0) {
                $lines[] = $this->line($accounts, 'bank_expense', __('accounting.descriptions.bank_fee', ['number' => $number]), $byType['bank_fee'], true);
            }

            if ($byType['adjustment'] > 0) {
                $lines[] = $this->line($accounts, 'rounding', __('accounting.descriptions.write_off', ['number' => $number]), $byType['adjustment'], true);
            }

            $lines[] = $this->line($accounts, 'receivables', $description, $total, false);

            $entries[] = $this->entry(
                CarbonImmutable::parse($first->paid_at), self::SOURCE_PAYMENTS, "customer|{$number}|{$first->id}", 'payment', $number, $invoice?->date,
                $this->contactName($invoice?->contact), $this->taxId($invoice?->contact), (string) ($invoice?->currency ?? 'EUR'), $lines,
            );
        }

        return $entries;
    }

    /**
     * Supplier payments: Dr payables for the cash paid and the debt forgiven, Dr bank charges for the fee, Cr bank for
     * everything that left the account and Cr rounding for the forgiven debt.
     *
     * @return array<int, LedgerEntry>
     */
    private function supplierPayments(CarbonImmutable $from, CarbonImmutable $to, LedgerAccounts $accounts): array
    {
        $payments = SupplierPayment::query()->with('passiveInvoice.supplier')->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])->orderBy('paid_at')->orderBy('created_at')->get();
        $ibans = $this->ibansByBankLine($payments->pluck('bank_statement_line_id')->filter()->unique()->all());
        $entries = [];

        foreach ($payments->groupBy(fn (SupplierPayment $payment): string => $payment->bank_statement_line_id ?? 'payment-'.$payment->id) as $group) {
            /** @var SupplierPayment $first */
            $first = $group->first();
            $invoice = $first->passiveInvoice;
            $number = (string) $invoice?->invoice_number;
            $byType = $this->sumByType($group);

            if (array_sum($byType) === 0) {
                continue;
            }

            $description = __('accounting.descriptions.supplier_payment', ['number' => $number]);
            $lines = [$this->line($accounts, 'payables', $description, $byType['payment'] + $byType['adjustment'], true)];

            if ($byType['bank_fee'] > 0) {
                $lines[] = $this->line($accounts, 'bank_expense', __('accounting.descriptions.bank_fee', ['number' => $number]), $byType['bank_fee'], true);
            }

            $lines[] = $this->bankLine($accounts, $ibans[$first->bank_statement_line_id] ?? null, $description, $byType['payment'] + $byType['bank_fee'], false);

            if ($byType['adjustment'] > 0) {
                $lines[] = $this->line($accounts, 'rounding', __('accounting.descriptions.write_off', ['number' => $number]), $byType['adjustment'], false);
            }

            $entries[] = $this->entry(
                CarbonImmutable::parse($first->paid_at), self::SOURCE_PAYMENTS, "supplier|{$number}|{$first->id}", 'supplier_payment', $number, $invoice?->date,
                $this->contactName($invoice?->supplier), $this->taxId($invoice?->supplier), (string) ($invoice?->currency ?? 'EUR'), $lines,
            );
        }

        return $entries;
    }

    /**
     * Categorised bank lines (taxes, payroll, bank expenses, other expenses, internal transfers). Lines matched with an
     * invoice are not repeated: their postings come from the payments.
     *
     * @return array<int, LedgerEntry>
     */
    private function bankLines(CarbonImmutable $from, CarbonImmutable $to, LedgerAccounts $accounts): array
    {
        $lines = BankStatementLine::query()->with('statement')
            ->whereIn('reconciliation_type', [BankStatementLine::AUTO_CATEGORIZATION, BankStatementLine::MANUAL_CATEGORIZATION])
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])->orderBy('booking_date')->orderBy('line_number')->get();
        $entries = [];

        foreach ($lines as $line) {
            $amount = $this->cents($line->amount);
            $fee = $this->cents($line->fee_amount);
            $category = (string) $line->reconciliation_category;
            $label = __("accounting.categories.{$category}");
            $description = trim($label.': '.(string) $line->description, ': ');
            $bank = $accounts->bankCode($line->statement?->account_iban);
            $counterAccount = $line->ledger_account_code ?: $accounts->code($this->defaultRole($category));
            $postings = [];

            if ($category === BankStatementLine::CATEGORY_INTERNAL_TRANSFER) {
                $postings = $line->isCredit()
                    ? [$this->raw($accounts, $bank, $description, $amount, true), $this->raw($accounts, $counterAccount, $description, $amount, false)]
                    : [$this->raw($accounts, $counterAccount, $description, $amount, true), $this->raw($accounts, $bank, $description, $amount, false)];
            } else {
                $postings = [$this->raw($accounts, $counterAccount, $description, $amount, true), $this->raw($accounts, $bank, $description, $amount, false)];

                if ($fee > 0) {
                    $postings[] = $this->line($accounts, 'bank_expense', __('accounting.descriptions.bank_fee_line'), $fee, true);
                    $postings[] = $this->raw($accounts, $bank, __('accounting.descriptions.bank_fee_line'), $fee, false);
                }
            }

            $entries[] = $this->entry(
                CarbonImmutable::parse($line->booking_date), self::SOURCE_BANKING, str_pad((string) $line->line_number, 8, '0', STR_PAD_LEFT)."|{$line->id}", $category,
                (string) ($line->reference ?: $line->statement?->account_iban), null, $line->counterpart_name, null, (string) ($line->statement?->currency ?? 'EUR'), $postings,
            );
        }

        return $entries;
    }

    /**
     * A posting on the bank account the money moved through: the ledger account mapped to the IBAN of the statement of the
     * bank line, or the default bank account when there is no line or the IBAN is not mapped.
     */
    private function bankLine(LedgerAccounts $accounts, ?string $iban, string $description, int $cents, bool $debit): LedgerLine
    {
        return $this->raw($accounts, $accounts->bankCode($iban), $description, $cents, $debit);
    }

    /**
     * The IBAN of the bank account of each bank statement line (line id => IBAN).
     *
     * @param  array<int, string>  $lineIds
     * @return array<string, string>
     */
    private function ibansByBankLine(array $lineIds): array
    {
        $ibans = [];

        foreach (array_chunk($lineIds, 500) as $chunk) {
            $ibans += DB::table('bank_statement_lines as line')
                ->join('bank_statements as statement', 'statement.id', '=', 'line.bank_statement_id')
                ->where('line.tenant_id', $this->tenantContext->getTenantId())
                ->whereIn('line.id', $chunk)
                ->pluck('statement.account_iban', 'line.id')
                ->all();
        }

        return $ibans;
    }

    private function defaultRole(string $category): string
    {
        return match ($category) {
            BankStatementLine::CATEGORY_TAX_PAYMENT => 'tax_payment',
            BankStatementLine::CATEGORY_PAYROLL => 'payroll',
            BankStatementLine::CATEGORY_BANK_EXPENSE => 'bank_expense',
            BankStatementLine::CATEGORY_INTERNAL_TRANSFER => 'transfer_clearing',
            default => 'generic_expense',
        };
    }

    /**
     * @param  Collection<int, Payment|SupplierPayment>  $payments
     * @return array{payment: int, bank_fee: int, adjustment: int}
     */
    private function sumByType(Collection $payments): array
    {
        $sums = ['payment' => 0, 'bank_fee' => 0, 'adjustment' => 0];

        foreach ($payments as $payment) {
            $sums[array_key_exists($payment->type, $sums) ? $payment->type : 'payment'] += $this->cents($payment->amount);
        }

        return $sums;
    }

    /**
     * The tax rates used by the lines of each document, as a vat code such as `22` or `22/10`.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function vatCodes(string $table, string $column, array $ids): array
    {
        $codes = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($table)->whereIn($column, $chunk)->select([$column, 'tax_rate'])->distinct()->orderBy('tax_rate', 'desc')->get() as $row) {
                $rate = rtrim(rtrim(number_format((float) $row->tax_rate, 2, '.', ''), '0'), '.');
                $codes[$row->{$column}] = isset($codes[$row->{$column}]) ? $codes[$row->{$column}].'/'.$rate : $rate;
            }
        }

        return $codes;
    }

    /**
     * Put the sides of the lines right for a credit note and post any difference to the rounding account, so the entry
     * balances.
     *
     * @param  array<int, LedgerLine>  $lines
     * @return array<int, LedgerLine>
     */
    private function balanced(LedgerAccounts $accounts, array $lines, bool $reverse): array
    {
        if ($reverse) {
            $lines = array_map(fn (LedgerLine $line): LedgerLine => new LedgerLine($line->accountCode, $line->accountName, $line->description, $line->credit, $line->debit, $line->vatCode), $lines);
        }

        $difference = array_sum(array_map(fn (LedgerLine $line): int => $line->debit - $line->credit, $lines));

        if ($difference !== 0) {
            $lines[] = $this->line($accounts, 'rounding', __('accounting.descriptions.rounding'), abs($difference), $difference < 0);
        }

        return $lines;
    }

    private function line(LedgerAccounts $accounts, string $role, string $description, int $cents, bool $debit, ?string $vatCode = null): LedgerLine
    {
        return $this->raw($accounts, $accounts->code($role), $description, $cents, $debit, $vatCode);
    }

    private function raw(LedgerAccounts $accounts, string $code, string $description, int $cents, bool $debit, ?string $vatCode = null): LedgerLine
    {
        return new LedgerLine($code, $accounts->name($code), $description, $debit ? $cents : 0, $debit ? 0 : $cents, $vatCode);
    }

    /**
     * @param  array<int, LedgerLine>  $lines
     */
    private function entry(CarbonImmutable|\DateTimeInterface $date, string $source, string $sortKey, string $documentType, string $documentNumber, CarbonImmutable|\DateTimeInterface|null $documentDate, ?string $counterpart, ?string $taxId, string $currency, array $lines): LedgerEntry
    {
        return new LedgerEntry(
            CarbonImmutable::instance($date)->startOfDay(),
            $source,
            $sortKey,
            $documentType,
            $documentNumber,
            $documentDate === null ? null : CarbonImmutable::instance($documentDate)->startOfDay(),
            $counterpart,
            $taxId,
            $currency,
            $lines,
        );
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function contactName(?Contact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        return $contact->company_name ?: (trim($contact->first_name.' '.$contact->last_name) ?: null);
    }

    private function taxId(?Contact $contact): ?string
    {
        return $contact?->vat_number ?: ($contact?->fiscal_code ?: null);
    }
}
