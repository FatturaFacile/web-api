<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Actions\Sales\RecordPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\Associations\Enums\PaymentMethod;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Models\Receipt;
use Modules\Associations\Support\Money;
use Modules\GeneralLedger\Services\PaymentLedgerService;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Takes a fee payment to the accounts through the flow every sale uses: a posted sales document, numbered with the
 * receipt and outside the invoice series, then RecordPayment. Revenue, receivable and collection are all posted by the
 * invoicing and ledger modules: nothing here writes a journal entry.
 */
final class FeeLedgerBridge
{
    /** Number of the credit note that reverses a receipt: the receipt number with this prefix, outside every series. */
    public const string CreditNotePrefix = 'NC-';

    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly RecordPayment $recordPayment,
        private readonly TenantContext $tenantContext,
        private readonly PaymentLedgerService $payments,
    ) {}

    /**
     * @return array{invoice_id: string, payment_id: string}
     */
    public function post(FeeAssignment $assignment, Receipt $receipt, string $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference, ?string $actorId): array
    {
        $tenant = $this->tenantContext->getTenant();
        $settings = AssociationSetting::current();
        $plan = $assignment->plan;
        $taxRate = Money::normalize((string) ($plan?->tax_rate ?? '0'));

        $invoice = $this->invoicing->createInvoice([
            'tenant_id' => $tenant->id,
            'contact_id' => $assignment->payer_contact_id,
            'invoice_type' => 'standard',
            'sdi_excluded' => true,
            'invoice_number' => $receipt->number,
            'date' => $paidOn->toDateString(),
            'due_date' => $paidOn->toDateString(),
            'currency' => $tenant->default_currency ?: 'EUR',
            'jurisdiction' => strtoupper((string) $tenant->country) === 'CH' ? 'CH' : 'IT',
            'payment_method' => $method->ledgerCode(),
            'notes' => "Association receipt {$receipt->number}",
        ], [[
            'description' => (string) $assignment->description,
            'quantity' => 1,
            'unit_price' => $this->netMatching($amount, $taxRate),
            'tax_rate' => $taxRate,
            'revenue_account_id' => $plan?->revenue_account_id ?? $settings->default_revenue_account_id,
            'cost_center_id' => $assignment->course?->cost_center_id,
        ]]);

        $posted = $this->invoicing->postInvoice($invoice, (string) $actorId);

        $payment = $this->recordPayment->execute(Invoice::query()->findOrFail($posted->id), [
            'amount' => $amount,
            'paid_at' => $paidOn->toDateString(),
            'payment_method' => $method->ledgerCode(),
            'reference' => $reference,
            'notes' => "Association receipt {$receipt->number}",
        ]);

        return ['invoice_id' => (string) $posted->id, 'payment_id' => (string) $payment->id];
    }

    /**
     * Undoes the accounting of a fee payment with the flows every sale uses: the collection entry is reversed and the
     * payment removed, then a credit note on the internal receipt document (outside SDI, like the document itself) takes
     * back revenue and VAT and closes the document. Returns the credit note.
     */
    public function reverse(FeePayment $feePayment, Receipt $receipt, CarbonImmutable $reversedOn, ?string $actorId): string
    {
        $invoice = SalesInvoice::query()->with('items')->lockForUpdate()->findOrFail($feePayment->invoice_id);
        $payment = $feePayment->payment_id === null ? null : Payment::query()->find($feePayment->payment_id);

        if ($payment !== null) {
            $this->payments->reverse(PaymentLedgerService::SOURCE_CUSTOMER, (string) $payment->id, (string) $payment->tenant_id, CarbonImmutable::parse($payment->paid_at));
            $payment->delete();
            $this->recordPayment->recalculate(Invoice::query()->findOrFail($invoice->id));
            $invoice->refresh();
        }

        $creditNote = $this->invoicing->issueCreditNote($invoice, [
            'invoice_number' => self::CreditNotePrefix.$receipt->number,
            'date' => $reversedOn->toDateString(),
            'sdi_excluded' => true,
            'created_by' => $actorId,
            'notes' => "Reversal of association receipt {$receipt->number}",
        ], $invoice->items->map(static fn ($item): array => [
            'description' => (string) $item->description,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'tax_rate' => (string) $item->tax_rate,
            'discount_percentage' => (string) $item->discount_percentage,
            'revenue_account_id' => $item->revenue_account_id,
            'cost_center_id' => $item->cost_center_id,
        ])->all());

        $outstanding = Money::normalize($invoice->outstandingAmount());
        $due = Money::normalize($creditNote->amountDue());
        $credited = Money::compare($outstanding, $due) <= 0 ? $outstanding : $due;

        if (Money::isPositive($credited)) {
            $this->invoicing->applyCreditNote($creditNote, $invoice, $credited, (string) $actorId);
        }

        return (string) $creditNote->id;
    }

    /**
     * The net amount that, with the VAT the invoice calculates, gives exactly the amount paid.
     */
    private function netMatching(string $gross, string $taxRate): string
    {
        $net = Money::netOfVat($gross, $taxRate);

        foreach (['0.00', '0.01', '-0.01'] as $step) {
            $candidate = Money::add($net, $step);

            if (Money::compare(Money::add($candidate, Money::percentage($candidate, $taxRate)), $gross) === 0) {
                return $candidate;
            }
        }

        throw new AssociationException(__('associations.vat_not_reconcilable'));
    }
}
