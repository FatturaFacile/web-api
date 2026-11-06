<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use InvalidArgumentException;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\PaymentLedgerService;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;

/**
 * Registers a received supplier invoice in the ledger: the debt to the supplier and the deductible VAT. TD04 documents
 * are supplier credit notes and post the opposite entry.
 */
final readonly class PostPassiveInvoiceAction
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private PaymentLedgerService $payments,
    ) {}

    /**
     * @throws InvalidArgumentException when the invoice is in a foreign currency and states no exchange rate
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
            throw new InvalidArgumentException("Supplier invoice {$invoice->invoice_number} is in {$currency}: state the exchange rate to {$accountingCurrency} to register it.");
        }

        $gross = abs((float) $invoice->total_amount);
        $tax = abs((float) $invoice->total_tax);
        $net = $invoice->total_net !== null ? abs((float) $invoice->total_net) : round($gross - $tax, 2);

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
        );

        $this->dispatcher->publish($event);
        $this->dispatcher->dispatch($event);

        $invoice->refresh();
        $this->payments->postWaitingSupplierPayments((string) $invoice->id);
    }
}
