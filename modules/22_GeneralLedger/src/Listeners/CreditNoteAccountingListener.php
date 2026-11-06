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
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Models\SalesInvoice;

final class CreditNoteAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
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

        // 4. Split the credited amount by the real rate of its lines
        $vatGroups = $this->creditedVatGroups($event, $creditNote, $creditedGross);
        $netCredited = '0';
        $vatCredited = '0';

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

        // Line 3: Credit Accounts Receivable (Gross Amount)
        $lines[] = [
            'account_id' => $arAccount->id,
            'debit_amount' => 0.0,
            'credit_amount' => $isSplitPayment ? round($creditedGross - $vatCredited, 2) : $creditedGross,
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
