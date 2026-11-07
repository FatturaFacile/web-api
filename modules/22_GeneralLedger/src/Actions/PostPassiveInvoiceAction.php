<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\PaymentLedgerService;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Invoicing\Services\DeclarationOfIntentService;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\SDI\Enums\TipoRitenuta;

/**
 * Registers a received supplier invoice in the ledger: the debt to the supplier and the deductible VAT. TD04 documents
 * are supplier credit notes and post the opposite entry.
 */
final readonly class PostPassiveInvoiceAction
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private PaymentLedgerService $payments,
        private DeclarationOfIntentService $declarations,
    ) {}

    /**
     * Stores a received document and posts it, both or neither.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(array $attributes): PassiveInvoice
    {
        return DB::transaction(function () use ($attributes): PassiveInvoice {
            $invoice = PassiveInvoice::create($attributes);
            $this->execute($invoice);

            return $invoice;
        });
    }

    /**
     * @throws LedgerPostingException when the invoice is in a foreign currency and states no exchange rate
     */
    public function execute(PassiveInvoice $invoice): void
    {
        $tenantId = (string) $invoice->tenant_id;
        $isCreditNote = $invoice->document_type === 'TD04';
        $sourceType = $isCreditNote ? 'vendor_credit_note' : 'vendor_invoice';

        $alreadyPosted = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $invoice->id)
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $currency = (string) $invoice->currency;
        $accountingCurrency = TenantAccountingCurrency::of($tenantId);

        if ($currency !== $accountingCurrency && $invoice->exchange_rate === null) {
            throw new LedgerPostingException("Supplier invoice {$invoice->invoice_number} is in {$currency}: state the exchange rate to {$accountingCurrency} to register it.");
        }

        $gross = abs((float) $invoice->total_amount);
        $tax = abs((float) $invoice->total_tax);
        $net = $invoice->total_net !== null ? abs((float) $invoice->total_net) : round($gross - $tax, 2);
        ['tax' => $withholdingTax, 'contributions' => $contributions] = $this->withheld($invoice);

        $event = new PurchaseLiabilityReadyEvent(
            matchLogId: (string) $invoice->id,
            purchaseOrderId: '',
            supplierId: (string) $invoice->supplier_id,
            netAmount: $net,
            taxAmount: $tax,
            grossAmount: $gross,
            currency: $currency,
            vendorInvoiceNumber: (string) $invoice->invoice_number,
            tenantId: $tenantId,
            companyId: $tenantId,
            documentDate: CarbonImmutable::parse($invoice->date)->toDateString(),
            dueDate: $invoice->due_date !== null ? CarbonImmutable::parse($invoice->due_date)->toDateString() : null,
            isCreditNote: $isCreditNote,
            exchangeRate: (float) ($invoice->exchange_rate ?? 1.0),
            withholdingTaxAmount: $withholdingTax,
            contributionAmount: $contributions,
            costAllocations: $this->costAllocations($invoice, $net),
        );

        $this->dispatcher->publish($event);
        $this->dispatcher->dispatch($event);

        $invoice->refresh();
        $this->declarations->trackPurchase($invoice);
        $this->payments->postWaitingSupplierPayments((string) $invoice->id);
    }

    /**
     * What the document says is kept from the supplier: withholding tax on one side, contributions on the other.
     *
     * @return array{tax: float, contributions: float}
     */
    private function withheld(PassiveInvoice $invoice): array
    {
        $tax = 0.0;
        $contributions = 0.0;

        foreach ($invoice->withholdings ?? [] as $withholding) {
            if (TipoRitenuta::tryFrom((string) $withholding['type'])?->isTax() === true) {
                $tax += (float) $withholding['amount'];
            } else {
                $contributions += (float) $withholding['amount'];
            }
        }

        return ['tax' => round($tax, 2), 'contributions' => round($contributions, 2)];
    }

    /**
     * The net amount of the document by cost account, from the lines and from the account of the document. Whatever
     * lines and accounts do not cover stays on the default purchases account.
     *
     * @return list<array{account_id: string, amount: float}>
     */
    private function costAllocations(PassiveInvoice $invoice, float $net): array
    {
        $byAccount = [];
        $allocated = '0.00';

        foreach ($invoice->lines()->whereNotNull('cost_account_id')->get() as $line) {
            $account = (string) $line->cost_account_id;
            $amount = bcadd((string) abs((float) $line->line_total), '0', 2);
            $byAccount[$account] = bcadd($byAccount[$account] ?? '0.00', $amount, 2);
            $allocated = bcadd($allocated, $amount, 2);
        }

        $remaining = bcsub(number_format($net, 2, '.', ''), $allocated, 2);

        if ($invoice->cost_account_id !== null && bccomp($remaining, '0', 2) === 1) {
            $account = (string) $invoice->cost_account_id;
            $byAccount[$account] = bcadd($byAccount[$account] ?? '0.00', $remaining, 2);
            $remaining = '0.00';
        }

        if (bccomp($remaining, '0', 2) === -1) {
            return [];
        }

        return array_map(
            static fn (string $account, string $amount): array => ['account_id' => $account, 'amount' => (float) $amount],
            array_keys($byAccount),
            array_values($byAccount),
        );
    }
}
