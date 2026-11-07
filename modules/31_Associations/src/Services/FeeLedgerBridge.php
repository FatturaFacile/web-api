<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Actions\Sales\RecordPayment;
use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\Associations\Enums\PaymentMethod;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\Receipt;
use Modules\Associations\Support\Money;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;

/**
 * Takes a fee payment to the accounts through the flow every sale uses: a posted sales document, numbered with the
 * receipt and outside the invoice series, then RecordPayment. Revenue, receivable and collection are all posted by the
 * invoicing and ledger modules: nothing here writes a journal entry.
 */
final class FeeLedgerBridge
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly RecordPayment $recordPayment,
        private readonly TenantContext $tenantContext,
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
