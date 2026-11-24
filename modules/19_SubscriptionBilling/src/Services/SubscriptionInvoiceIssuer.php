<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Models\Subscription as LegacySubscription;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Support\Money;

/**
 * The only door of the subscription engine to the invoicing module. Invoices are created as drafts by the invoicing
 * service and, unless the company keeps them for review (subscription_billing.auto_post_invoices = false), issued by
 * it: the fiscal number comes from its gapless sequence at emission, and the General Ledger posting follows from
 * its InvoicePostedEvent. Every line credits the deferred revenue account, released monthly by the recognition.
 */
final readonly class SubscriptionInvoiceIssuer
{
    /** Payment methods of the subscriptions mapped to the FatturaPA codes of the invoice. */
    private const array PAYMENT_METHODS = [
        'sepa_direct_debit' => 'MP19',
        'bank_transfer' => 'MP05',
        'credit_card' => 'MP08',
        'cash' => 'MP01',
    ];

    public function __construct(
        private InvoicingServiceInterface $invoicing,
        private SubscriptionAccounts $accounts,
    ) {}

    /**
     * Creates (and by default issues) the invoice of a subscription.
     *
     * @param  list<array{description: string, quantity: string, unit_price: string, tax_rate: string, discount_percentage?: string, product_id?: string|null}>  $lines
     */
    public function invoice(Subscription $subscription, array $lines, CarbonInterface $date, string $notes, ?string $actorUserId): SalesInvoice
    {
        $tenantId = (string) $subscription->tenant_id;
        $deferredAccount = (string) $this->accounts->deferredRevenue($tenantId, $subscription->plan)->id;

        $invoice = $this->invoicing->createInvoice([
            'tenant_id' => $tenantId,
            'contact_id' => (string) $subscription->customer_id,
            'invoice_type' => 'standard',
            'date' => $date->toDateString(),
            'due_date' => $date->copy()->addDays((int) $subscription->payment_terms_days)->toDateString(),
            'currency' => (string) $subscription->currency,
            'payment_method' => self::PAYMENT_METHODS[(string) $subscription->payment_method] ?? 'MP05',
            'notes' => $notes,
            'created_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
        ], array_map(fn (array $line): array => [
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'tax_rate' => $line['tax_rate'],
            'discount_percentage' => $line['discount_percentage'] ?? '0',
            'product_id' => $line['product_id'] ?? null,
            'revenue_account_id' => $deferredAccount,
        ], $lines));

        // A subscription copied from the legacy table keeps its id there: its invoices keep the link they always had.
        if (LegacySubscription::withoutGlobalScopes()->whereKey($subscription->id)->exists()) {
            SalesInvoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['subscription_id' => $subscription->id]);
            $invoice->setAttribute('subscription_id', $subscription->id)->syncOriginalAttribute('subscription_id');
        }

        if ((bool) config('subscription_billing.auto_post_invoices', true)) {
            $invoice = $this->invoicing->postInvoice($invoice, $actorUserId ?? 'system');
        }

        return $invoice;
    }

    /**
     * Issues a credit note on an issued invoice of the subscription (refund of an unused period).
     *
     * @param  list<array{description: string, quantity: string, unit_price: string, tax_rate: string}>  $lines
     */
    public function creditNote(SalesInvoice $original, array $lines, CarbonInterface $date, string $notes, ?string $actorUserId): SalesInvoice
    {
        return $this->invoicing->issueCreditNote($original, [
            'date' => $date->toDateString(),
            'notes' => $notes,
            'created_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
        ], $lines);
    }

    /**
     * VAT rate of an item: its own, then its plan's, then the standard rate of the country of the company.
     */
    public function taxRate(Subscription $subscription, ?SubscriptionItem $item = null, ?SubscriptionPlan $plan = null): string
    {
        $plan ??= $subscription->plan;
        $rate = $item !== null && $item->tax_rate !== null ? $item->tax_rate : ($plan !== null ? $plan->tax_rate : null);

        if ($rate !== null && $rate !== '') {
            return Money::round2($rate);
        }

        $country = Tenant::query()->whereKey($subscription->tenant_id)->value('country');

        return in_array($country, ['CH', 'LI'], true) ? '8.10' : '22.00';
    }
}
