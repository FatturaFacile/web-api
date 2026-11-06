<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the VAT documents of a period from the registered journal entries.
 *
 * Entries are selected by posting date, because a VAT period can span several fiscal periods. Reversed entries stay in
 * the reading next to their reversal: within one period the two cancel out, across periods the reversal corrects the
 * period it falls in and the settled one is left untouched. Settlement entries never count as VAT documents.
 */
final class VatLedgerReader
{
    public const string TREATMENT_SPLIT_PAYMENT = 'split_payment';

    public const string TREATMENT_REVERSE_CHARGE = 'reverse_charge';

    public const string SOURCE_SETTLEMENT = 'vat_settlement';

    public const string SOURCE_SETTLEMENT_PAYMENT = 'vat_settlement_payment';

    private const array SALES_SOURCES = ['sales_invoice', 'credit_note'];

    private const array PURCHASE_SOURCES = ['vendor_invoice'];

    public function read(string $tenantId, string $country, CarbonImmutable $from, CarbonImmutable $to, VatAccountSet $accounts): VatLedgerSnapshot
    {
        $linesByEntry = $this->linesOfPeriod($tenantId, $from, $to, $accounts)->groupBy('journal_entry_id');

        $sales = [];
        $purchases = [];
        $saleLines = [];
        $purchaseLines = [];
        $outputByAccount = [];
        $inputByAccount = [];
        $warnings = [];

        foreach ($linesByEntry as $lines) {
            $entry = $lines->first();

            if ($this->isSalesDocument($entry, $lines, $accounts)) {
                $this->readSale($lines, $country, $accounts, $sales, $saleLines, $outputByAccount, $warnings);
            }

            if ($this->isPurchaseDocument($entry, $lines, $accounts)) {
                $this->readPurchase($lines, $accounts, $purchases, $purchaseLines, $inputByAccount, $warnings);
            }
        }

        return new VatLedgerSnapshot($sales, $purchases, $outputByAccount, $inputByAccount, $saleLines, $purchaseLines, $warnings);
    }

    /**
     * @return Collection<int, object>
     */
    private function linesOfPeriod(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, VatAccountSet $accounts): Collection
    {
        $vatAccountIds = array_merge($accounts->outputAccountIds, $accounts->inputAccountIds);

        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'jl.account_id', '=', 'coa.id')
            ->leftJoin('vat_codes as vc', 'jl.vat_code_id', '=', 'vc.id')
            ->where('je.tenant_id', $tenantId)
            ->whereNull('je.deleted_at')
            ->whereNull('jl.deleted_at')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.ledger_type', 'statutory')
            ->where('je.journal_type', '<>', 'closing')
            ->whereBetween('je.posting_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query): void {
                $query->whereNull('je.source_document_type')
                    ->orWhereNotIn('je.source_document_type', [self::SOURCE_SETTLEMENT, self::SOURCE_SETTLEMENT_PAYMENT]);
            })
            ->where(function ($query) use ($vatAccountIds): void {
                $query->whereIn('je.source_document_type', [...self::SALES_SOURCES, ...self::PURCHASE_SOURCES])
                    ->orWhereExists(function ($exists) use ($vatAccountIds): void {
                        $exists->selectRaw('1')
                            ->from('journal_lines as vat_line')
                            ->whereColumn('vat_line.journal_entry_id', 'je.id')
                            ->whereNull('vat_line.deleted_at')
                            ->whereIn('vat_line.account_id', $vatAccountIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $vatAccountIds);
                    });
            })
            ->orderBy('je.posting_date')
            ->orderBy('je.entry_number')
            ->orderBy('jl.line_number')
            ->get([
                'jl.journal_entry_id', 'jl.account_id', 'jl.partner_type', 'jl.partner_id', 'jl.debit_amount', 'jl.credit_amount',
                'jl.exchange_rate', 'jl.vat_base_amount', 'jl.vat_tax_amount', 'jl.vat_rate_percent', 'jl.vat_treatment',
                'je.entry_number', 'je.posting_date', 'je.document_date', 'je.document_number', 'je.source_document_type',
                'coa.account_type', 'coa.account_class', 'coa.type as account_legacy_type',
                'vc.tax_rate_percent as vat_code_rate', 'vc.is_split_payment', 'vc.is_reverse_charge',
            ]);
    }

    /**
     * @param  Collection<int, object>  $lines
     */
    private function isSalesDocument(object $entry, Collection $lines, VatAccountSet $accounts): bool
    {
        return in_array($entry->source_document_type, self::SALES_SOURCES, true)
            || $lines->contains(fn (object $line): bool => in_array((string) $line->account_id, $accounts->outputAccountIds, true));
    }

    /**
     * @param  Collection<int, object>  $lines
     */
    private function isPurchaseDocument(object $entry, Collection $lines, VatAccountSet $accounts): bool
    {
        return in_array($entry->source_document_type, self::PURCHASE_SOURCES, true)
            || $lines->contains(fn (object $line): bool => in_array((string) $line->account_id, $accounts->inputAccountIds, true));
    }

    /**
     * @param  Collection<int, object>  $lines
     * @param  list<VatSaleDocument>  $sales
     * @param  list<VatRegisterLine>  $registerLines
     * @param  array<string, string>  $outputByAccount
     * @param  list<string>  $warnings
     */
    private function readSale(Collection $lines, string $country, VatAccountSet $accounts, array &$sales, array &$registerLines, array &$outputByAccount, array &$warnings): void
    {
        $entry = $lines->first();
        $revenue = '0';
        $groups = [];
        $unrated = ['tax' => '0', 'count' => 0];

        foreach ($lines as $line) {
            $signed = $this->creditMinusDebit($line);

            if ($this->isRevenue($line)) {
                $revenue = Decimal::add($revenue, $signed);
            }

            $treatment = $this->treatmentOf($line);
            $isOutputAccount = in_array((string) $line->account_id, $accounts->outputAccountIds, true);

            if (! $isOutputAccount && $treatment === null) {
                continue;
            }

            $tax = Decimal::round2($signed);
            $rate = $line->vat_rate_percent ?? $line->vat_code_rate;

            if ($isOutputAccount && ! ($country === 'IT' && $treatment !== null)) {
                $accountId = (string) $line->account_id;
                $outputByAccount[$accountId] = Decimal::round2(Decimal::add($outputByAccount[$accountId] ?? '0', $tax));
            }

            if ($rate === null) {
                $unrated['tax'] = Decimal::add($unrated['tax'], $tax);
                $unrated['count']++;

                continue;
            }

            $key = Decimal::formatRate((string) $rate).'|'.($treatment ?? '');
            $base = $this->signedBase($line, $tax);
            $current = $groups[$key] ?? ['rate' => Decimal::formatRate((string) $rate), 'treatment' => $treatment, 'base' => '0', 'tax' => '0'];
            $groups[$key] = [...$current, 'base' => Decimal::add($current['base'], $base), 'tax' => Decimal::add($current['tax'], $tax)];
        }

        $revenue = Decimal::round2($revenue);

        if ($unrated['count'] > 0) {
            $warnings[] = "Entry {$entry->entry_number}: VAT line without rate, the effective rate was derived from the entry.";
            $groups['unrated'] = $this->effectiveRateGroup($revenue, $groups, $unrated['tax']);
        }

        if ($groups === []) {
            $groups[] = ['rate' => '0', 'treatment' => null, 'base' => $revenue, 'tax' => '0'];
        }

        $isFirst = true;

        foreach ($groups as $group) {
            $sales[] = new VatSaleDocument(
                totalNet: $isFirst ? $revenue : '0',
                items: [new VatSaleItem(Decimal::round2($group['base']), $group['rate'], Decimal::round2($group['tax']))],
                splitPayment: $group['treatment'] === self::TREATMENT_SPLIT_PAYMENT,
                reverseCharge: $group['treatment'] === self::TREATMENT_REVERSE_CHARGE,
            );
            $isFirst = false;
            $registerLines[] = $this->registerLine($lines, $group['base'], $group['rate'], $group['tax'], $group['treatment']);
        }
    }

    /**
     * @param  Collection<int, object>  $lines
     * @param  list<VatPurchaseDocument>  $purchases
     * @param  list<VatRegisterLine>  $registerLines
     * @param  array<string, string>  $inputByAccount
     * @param  list<string>  $warnings
     */
    private function readPurchase(Collection $lines, VatAccountSet $accounts, array &$purchases, array &$registerLines, array &$inputByAccount, array &$warnings): void
    {
        $entry = $lines->first();
        $deductible = '0';
        $supplierGross = '0';
        $groups = [];

        foreach ($lines as $line) {
            if ($line->partner_type === 'supplier') {
                $supplierGross = Decimal::add($supplierGross, $this->creditMinusDebit($line));
            }

            if (! in_array((string) $line->account_id, $accounts->inputAccountIds, true)) {
                continue;
            }

            $tax = Decimal::round2(Decimal::neg($this->creditMinusDebit($line)));
            $accountId = (string) $line->account_id;
            $inputByAccount[$accountId] = Decimal::round2(Decimal::add($inputByAccount[$accountId] ?? '0', $tax));
            $deductible = Decimal::add($deductible, $tax);

            $rate = $line->vat_rate_percent ?? $line->vat_code_rate;

            if ($rate === null) {
                $warnings[] = "Entry {$entry->entry_number}: VAT line without rate.";
            }

            $key = $rate === null ? 'unrated' : Decimal::formatRate((string) $rate);
            $current = $groups[$key] ?? ['rate' => $rate === null ? '0' : Decimal::formatRate((string) $rate), 'base' => '0', 'tax' => '0'];
            $groups[$key] = [...$current, 'base' => Decimal::add($current['base'], $this->signedBase($line, $tax)), 'tax' => Decimal::add($current['tax'], $tax)];
        }

        $base = array_reduce($groups, static fn (string $total, array $group): string => Decimal::add($total, $group['base']), '0');
        $net = Decimal::isZero($base) ? Decimal::sub($supplierGross, $deductible) : $base;

        if ($groups === []) {
            $groups[] = ['rate' => '0', 'base' => $net, 'tax' => '0'];
        } elseif (count($groups) === 1 && Decimal::isZero($base)) {
            $groups[array_key_first($groups)]['base'] = $net;
        }

        $purchases[] = new VatPurchaseDocument(Decimal::round2($deductible), Decimal::round2($net));

        foreach ($groups as $group) {
            $registerLines[] = $this->registerLine($lines, $group['base'], $group['rate'], $group['tax'], null);
        }
    }

    /**
     * Groups VAT lines without a rate: their taxable is what the rated lines leave of the revenue of the entry.
     *
     * @param  array<string, array{rate: string, treatment: ?string, base: string, tax: string}>  $groups
     * @return array{rate: string, treatment: ?string, base: string, tax: string}
     */
    private function effectiveRateGroup(string $revenue, array $groups, string $tax): array
    {
        $base = array_reduce($groups, static fn (string $left, array $group): string => Decimal::sub($left, $group['base']), $revenue);
        $rate = Decimal::isZero($base) ? '0' : Decimal::formatRate(Decimal::div(Decimal::mul($tax, '100'), $base));

        return ['rate' => $rate, 'treatment' => null, 'base' => $base, 'tax' => $tax];
    }

    /**
     * @param  Collection<int, object>  $lines
     */
    private function registerLine(Collection $lines, string $base, string $rate, string $tax, ?string $treatment): VatRegisterLine
    {
        $entry = $lines->first();
        $partner = $lines->first(fn (object $line): bool => $line->partner_id !== null);

        return new VatRegisterLine(
            journalEntryId: (string) $entry->journal_entry_id,
            entryNumber: (string) $entry->entry_number,
            postingDate: substr((string) $entry->posting_date, 0, 10),
            documentDate: substr((string) $entry->document_date, 0, 10),
            documentNumber: $entry->document_number,
            sourceDocumentType: $entry->source_document_type,
            partnerId: $partner?->partner_id,
            taxable: Decimal::round2($base),
            rate: $rate,
            tax: Decimal::round2($tax),
            treatment: $treatment,
        );
    }

    /**
     * The amount of the line in the accounting currency, positive on the credit side.
     */
    private function creditMinusDebit(object $line): string
    {
        return Decimal::mul(Decimal::sub((string) $line->credit_amount, (string) $line->debit_amount), (string) $line->exchange_rate);
    }

    /**
     * The taxable of a VAT line takes the sign of its tax. A line with no tax has no side to read the sign from, so it
     * follows the document type.
     */
    private function signedBase(object $line, string $tax): string
    {
        $base = Decimal::abs(Decimal::mul((string) $line->vat_base_amount, (string) $line->exchange_rate));

        if (Decimal::isNegative($tax) || (Decimal::isZero($tax) && $line->source_document_type === 'credit_note')) {
            return Decimal::neg($base);
        }

        return $base;
    }

    private function isRevenue(object $line): bool
    {
        return in_array('revenue', [$line->account_type, $line->account_class, $line->account_legacy_type], true);
    }

    private function treatmentOf(object $line): ?string
    {
        if ($line->vat_treatment !== null) {
            return (string) $line->vat_treatment;
        }

        if ((bool) $line->is_split_payment) {
            return self::TREATMENT_SPLIT_PAYMENT;
        }

        return (bool) $line->is_reverse_charge ? self::TREATMENT_REVERSE_CHARGE : null;
    }
}
