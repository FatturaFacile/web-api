<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SDI\Enums\TipoCassa;

final class CreditNoteAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly LedgerAccountResolver $accounts,
        private readonly ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(CreditNoteIssuedEvent $event): void
    {
        // 1. Idempotency Check: Prevent duplicate GL postings
        $existingEntry = JournalEntry::where('tenant_id', $event->tenantId)
            ->where('source_document_type', 'credit_note')
            ->where('source_document_id', $event->creditNoteId)
            ->first();

        if ($existingEntry !== null) {
            return;
        }

        // 2. The period containing the date of the document: failing here stops the emission
        $creditNote = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $event->tenantId)
            ->whereKey($event->creditNoteId)
            ->first();
        $documentDate = $creditNote?->date !== null ? CarbonImmutable::parse($creditNote->date)->toDateString() : $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $documentDate);

        // 3. Resolve Master General Ledger Accounts
        $revenueReversalAcc = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '4090',
            name: 'Sales Returns & Allowances (Resi su Vendite)',
            type: 'revenue',
            normalBalance: 'debit'
        );

        $vatReversalAcc = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '2200',
            name: 'IVA c/Vendite (Sales Output VAT)',
            type: 'liability',
            normalBalance: 'credit'
        );

        $arAccount = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '1100',
            name: 'Accounts Receivable (Crediti v/Clienti)',
            type: 'asset',
            normalBalance: 'debit'
        );
        $arAccount->update(['is_reconciliation_account' => true, 'reconciliation_type' => 'customer']);

        // A split-payment invoice left only the net on the receivable: its credit note takes back the same amount.
        $isSplitPayment = (bool) $creditNote?->split_payment;

        if ($isSplitPayment) {
            $vatReversalAcc = $this->resolveAccount(
                tenantId: $event->tenantId,
                code: '2205',
                name: 'IVA split payment (Split Payment VAT)',
                type: 'liability',
                normalBalance: 'credit'
            );
        }

        $currency = ! empty($creditNote?->currency) ? (string) $creditNote->currency : TenantAccountingCurrency::of($event->tenantId);
        $exchangeRate = (float) ($creditNote?->exchange_rate ?: 1.0);
        $creditedGross = number_format($event->creditedAmount, 2, '.', '');

        // 4. Split the credited amount by the real rate of its lines, once the pension fund share and its VAT are set apart
        $fund = $this->fundShare($creditNote);
        $linesGross = Decimal::round2(Decimal::sub($creditedGross, Decimal::add($fund['amount'], $fund['vat'])));
        $vatGroups = $this->creditedVatGroups($event, $creditNote, $linesGross);
        $netCredited = '0';
        $vatCredited = $fund['vat'];

        foreach ($vatGroups as $group) {
            $netCredited = Decimal::add($netCredited, $group['net']);
            $vatCredited = Decimal::add($vatCredited, $group['tax']);
        }

        $netCredited = (float) Decimal::round2($netCredited);
        $vatCredited = (float) Decimal::round2($vatCredited);
        $creditedGross = (float) $creditedGross;

        // 5. Construct Balanced Reversal Journal Lines
        // Line 1: Debit Revenue Reversal
        $lines = [
            [
                'account_id' => $revenueReversalAcc->id,
                'debit_amount' => $netCredited,
                'credit_amount' => 0.0,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'cost_center_id' => $this->commonCostCentre($creditNote),
                'description' => "Revenue Reversal: Credit Note {$event->creditNoteNumber}",
            ],
        ];

        // Lines 2+: Debit Output VAT Reversal, one for each rate
        foreach ($vatGroups as $group) {
            if (! Decimal::isPositive($group['tax'])) {
                continue;
            }

            $lines[] = [
                'account_id' => $vatReversalAcc->id,
                'debit_amount' => (float) $group['tax'],
                'credit_amount' => 0.0,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'vat_tax_amount' => (float) $group['tax'],
                'vat_base_amount' => (float) $group['net'],
                'vat_rate_percent' => $group['rate'],
                'vat_treatment' => $isSplitPayment ? VatLedgerReader::TREATMENT_SPLIT_PAYMENT : null,
                'description' => "VAT Reversal: Credit Note {$event->creditNoteNumber}",
            ];
        }

        // The pension fund share leaves the account the invoice credited, and its VAT leaves the output VAT
        if (Decimal::isPositive($fund['amount'])) {
            $lines[] = [
                'account_id' => $fund['account_id'],
                'debit_amount' => (float) $fund['amount'],
                'credit_amount' => 0.0,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'description' => "Pension fund contribution reversed: Credit Note {$event->creditNoteNumber}",
            ];
        }

        if (Decimal::isPositive($fund['vat'])) {
            $lines[] = [
                'account_id' => $vatReversalAcc->id,
                'debit_amount' => (float) $fund['vat'],
                'credit_amount' => 0.0,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'vat_tax_amount' => (float) $fund['vat'],
                'vat_base_amount' => (float) $fund['amount'],
                'vat_rate_percent' => $fund['rate'],
                'vat_treatment' => $isSplitPayment ? VatLedgerReader::TREATMENT_SPLIT_PAYMENT : null,
                'description' => "Pension fund VAT reversal: Credit Note {$event->creditNoteNumber}",
            ];
        }

        // Line 3: Credit Accounts Receivable, net of the withholding and the Enasarco the customer never paid
        $withheld = $this->withheldShare($creditNote);
        $receivableCredit = (float) Decimal::round2(Decimal::sub($isSplitPayment ? Decimal::sub((string) $creditedGross, (string) $vatCredited) : (string) $creditedGross, Decimal::add($withheld['withholding'], $withheld['enasarco'])));
        $lines[] = [
            'account_id' => $arAccount->id,
            'debit_amount' => 0.0,
            'credit_amount' => $receivableCredit,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'partner_type' => 'customer',
            'partner_id' => $event->customerId,
            'description' => "AR Credit Offset: Credit Note {$event->creditNoteNumber}",
        ];

        if ($isSplitPayment && $vatCredited > 0) {
            $lines[] = [
                'account_id' => $vatReversalAcc->id,
                'debit_amount' => 0.0,
                'credit_amount' => $vatCredited,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'description' => "Split payment VAT: Credit Note {$event->creditNoteNumber}",
            ];
        }

        if (Decimal::isPositive($withheld['withholding'])) {
            $lines[] = [
                'account_id' => $this->accounts->withholdingReceivable($event->tenantId)->id,
                'debit_amount' => 0.0,
                'credit_amount' => (float) $withheld['withholding'],
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'description' => "Withholding reversed: Credit Note {$event->creditNoteNumber}",
            ];
        }

        if (Decimal::isPositive($withheld['enasarco'])) {
            $lines[] = [
                'account_id' => $this->accounts->enasarcoExpense($event->tenantId)->id,
                'debit_amount' => 0.0,
                'credit_amount' => (float) $withheld['enasarco'],
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'description' => "Enasarco reversed: Credit Note {$event->creditNoteNumber}",
            ];
        }

        // 6. Post Double-Entry Journal Entry
        $entryNumber = 'CN-GL-'.strtoupper(Str::random(6));
        $journalEntry = $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => $entryNumber,
            'source_document_type' => 'credit_note',
            'source_document_id' => $event->creditNoteId,
            'document_number' => $event->creditNoteNumber,
            'posting_date' => $documentDate,
            'document_date' => $documentDate,
            'journal_type' => 'standard',
            'description' => "Credit Note GL Posting: {$event->creditNoteNumber}",
            'lines' => $lines,
        ]);

        // 7. Link GL Journal Entry back to Credit Note
        SalesInvoice::withoutGlobalScope(TenantScope::class)->where('id', $event->creditNoteId)->update([
            'gl_journal_entry_id' => (string) $journalEntry->id,
        ]);

        // 8. Explicit Accounting Audit Entry
        $this->auditLedger?->record(
            action: 'SALES_CREDIT_NOTE_GL_POSTED',
            model: $journalEntry,
            payloadBefore: null,
            payloadAfter: [
                'credit_note_id' => $event->creditNoteId,
                'credit_note_number' => $event->creditNoteNumber,
                'original_invoice_id' => $event->originalInvoiceId,
                'customer_id' => $event->customerId,
                'journal_entry_id' => (string) $journalEntry->id,
                'credited_gross' => $creditedGross,
                'net_reversed' => $netCredited,
                'vat_reversed' => $vatCredited,
            ],
            moduleCode: 'MODULE_22_GENERAL_LEDGER'
        );
    }

    /**
     * Splits the credited gross by VAT rate: from the lines of the event, else from the lines and totals stored on the
     * credit note, else with the rate its original invoice expresses. No rate is ever assumed.
     *
     * @return list<array{rate: string, net: string, tax: string}>
     *
     * @throws InvalidArgumentException when none of these sources states the VAT of the credit note
     */
    private function creditedVatGroups(CreditNoteIssuedEvent $event, ?SalesInvoice $creditNote, string $creditedGross): array
    {
        $groups = $this->groupsFromLines($event->adjustmentLines);

        if ($groups === [] && $creditNote !== null) {
            $groups = $this->groupsFromLines($creditNote->items()->withoutGlobalScope(TenantScope::class)->get()->map(
                static fn ($item): array => ['quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'tax_rate' => $item->tax_rate, 'discount_percentage' => $item->discount_percentage]
            )->all());
        }

        if ($groups !== []) {
            return $this->alignToGross($groups, $creditedGross);
        }

        foreach ([$creditNote, $this->originalInvoiceOf($event)] as $document) {
            $net = (string) ($document?->total_net ?? '0');

            if (Decimal::isPositive($net)) {
                $rate = Decimal::round2(Decimal::div(Decimal::mul((string) $document->total_tax, '100'), $net));
                $creditedNet = Decimal::round2(Decimal::div(Decimal::mul($creditedGross, '100'), Decimal::add('100', $rate)));

                return [['rate' => $rate, 'net' => $creditedNet, 'tax' => Decimal::round2(Decimal::sub($creditedGross, $creditedNet))]];
            }
        }

        throw new InvalidArgumentException("Credit note {$event->creditNoteNumber} does not state its VAT rate.");
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{rate: string, net: string, tax: string}>
     */
    private function groupsFromLines(array $lines): array
    {
        $byRate = [];

        foreach ($lines as $line) {
            if (! isset($line['tax_rate'])) {
                return [];
            }

            $rate = Decimal::round2(number_format((float) $line['tax_rate'], 2, '.', ''));
            $quantity = number_format((float) ($line['quantity'] ?? 1.0), 4, '.', '');
            $unitPrice = number_format((float) ($line['unit_price'] ?? 0.0), 4, '.', '');
            $discount = number_format((float) ($line['discount_percentage'] ?? 0.0), 2, '.', '');
            $net = Decimal::round2(Decimal::div(Decimal::mul(Decimal::mul($quantity, $unitPrice), Decimal::sub('100', $discount)), '100'));
            $tax = Decimal::round2(Decimal::div(Decimal::mul($net, $rate), '100'));
            $current = $byRate[$rate] ?? ['rate' => $rate, 'net' => '0', 'tax' => '0'];

            $byRate[$rate] = ['rate' => $rate, 'net' => Decimal::add($current['net'], $net), 'tax' => Decimal::add($current['tax'], $tax)];
        }

        $groups = array_values($byRate);
        $total = array_reduce($groups, static fn (string $sum, array $group): string => Decimal::add($sum, $group['net']), '0');

        return Decimal::isPositive($total) ? $groups : [];
    }

    /**
     * The lines can differ from the credited gross by the rounding of the document: the difference goes to the largest
     * VAT line, or to the net when no line carries VAT.
     *
     * @param  list<array{rate: string, net: string, tax: string}>  $groups
     * @return list<array{rate: string, net: string, tax: string}>
     */
    private function alignToGross(array $groups, string $creditedGross): array
    {
        $difference = $creditedGross;
        $largest = 0;

        foreach ($groups as $index => $group) {
            $difference = Decimal::sub(Decimal::sub($difference, $group['net']), $group['tax']);
            $largest = Decimal::compare($group['tax'], $groups[$largest]['tax']) > 0 ? $index : $largest;
        }

        $field = Decimal::isPositive($groups[$largest]['tax']) ? 'tax' : 'net';
        $groups[$largest][$field] = Decimal::add($groups[$largest][$field], $difference);

        return array_map(static fn (array $group): array => [
            'rate' => $group['rate'],
            'net' => Decimal::round2($group['net']),
            'tax' => Decimal::round2($group['tax']),
        ], $groups);
    }

    /**
     * The share of the withholding and of the Enasarco of the invoice that the credit note takes back.
     *
     * @return array{withholding: string, enasarco: string}
     */
    private function withheldShare(?SalesInvoice $creditNote): array
    {
        return [
            'withholding' => $creditNote !== null && $creditNote->withholding_tax_enabled ? Decimal::round2((string) ($creditNote->withholding_tax_amount ?? '0')) : '0',
            'enasarco' => $creditNote !== null && $creditNote->enasarco_enabled ? Decimal::round2((string) ($creditNote->enasarco_amount ?? '0')) : '0',
        ];
    }

    /**
     * The cost centre of the revenue reversal: the one every line of the credit note states, else none.
     */
    private function commonCostCentre(?SalesInvoice $creditNote): ?string
    {
        if ($creditNote === null) {
            return null;
        }

        $centres = $creditNote->items()->withoutGlobalScope(TenantScope::class)->pluck('cost_center_id')->unique()->values();

        return $centres->count() === 1 && $centres->first() !== null ? (string) $centres->first() : null;
    }

    /**
     * The pension fund contribution the credit note takes back, with its VAT and the account the invoice credited it to:
     * the INPS rivalsa is income, the other funds a payable.
     *
     * @return array{amount: string, vat: string, rate: string, account_id: ?string}
     */
    private function fundShare(?SalesInvoice $creditNote): array
    {
        if ($creditNote === null || ! $creditNote->fund_enabled || ! Decimal::isPositive((string) $creditNote->fund_amount)) {
            return ['amount' => '0', 'vat' => '0', 'rate' => '0', 'account_id' => null];
        }

        $tenantId = (string) $creditNote->tenant_id;
        $account = TipoCassa::tryFrom((string) $creditNote->fund_type)?->isRivalsaInps()
            ? $this->accounts->pensionFundRecharge($tenantId)
            : $this->accounts->pensionFundPayable($tenantId);

        return [
            'amount' => Decimal::round2((string) $creditNote->fund_amount),
            'vat' => Decimal::round2((string) ($creditNote->fund_vat_amount ?? '0')),
            'rate' => Decimal::round2((string) ($creditNote->fund_vat_rate ?? '0')),
            'account_id' => (string) $account->id,
        ];
    }

    private function originalInvoiceOf(CreditNoteIssuedEvent $event): ?SalesInvoice
    {
        if (empty($event->originalInvoiceId)) {
            return null;
        }

        return SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $event->tenantId)
            ->whereKey($event->originalInvoiceId)
            ->first();
    }

    private function resolveAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }
}
