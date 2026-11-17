<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Modules\SubscriptionBilling\Models\Subscription;

/**
 * Issues the electronic invoice (Module 05) for a subscription billing cycle.
 * Lines are net amounts: [description, quantity, unit_price, (tax_rate)].
 */
final readonly class SubscriptionInvoiceIssuer
{
    public function __construct(
        private TenantContext $tenantContext,
        private DocumentNumberingService $numbering,
        private DocumentTotalsCalculator $totals,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function issue(Subscription $subscription, array $lines, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?Invoice
    {
        $tenant = Tenant::query()->find($subscription->tenant_id);
        $contact = Contact::withoutGlobalScopes()->find($subscription->customer_id);

        // Without a CRM customer there is no addressee: the schedule is still recorded by the caller.
        if ($tenant === null || $contact === null) {
            return null;
        }

        return $this->tenantContext->runWithTenant($tenant, function () use ($subscription, $lines, $periodStart, $periodEnd, $contact): Invoice {
            $taxRate = $this->resolveTaxRate($subscription, $contact);
            $computed = $this->totals->calculate(array_map(
                fn (array $line): array => $line + ['tax_rate' => $taxRate, 'discount_percentage' => 0],
                $lines
            ));

            $date = now()->toDateString();
            $number = $this->numbering->next('invoice', (int) date('Y', strtotime($date)));
            $terms = (int) $subscription->payment_terms_days;

            $invoice = Invoice::create([
                'tenant_id' => $subscription->tenant_id,
                'contact_id' => $contact->id,
                'invoice_type' => 'standard',
                'invoice_number' => $number['number'],
                'fiscal_year' => $number['fiscal_year'],
                'sequential_number' => $number['sequential_number'],
                'date' => $date,
                'due_date' => now()->addDays($terms)->toDateString(),
                'currency' => $subscription->currency ?? 'EUR',
                'total_net' => $computed['total_net'],
                'total_tax' => $computed['total_tax'],
                'total_gross' => $computed['total_gross'],
                'payment_status' => 'unpaid',
                'payment_method' => $subscription->payment_method,
                'notes' => sprintf('%s (%s - %s)', $subscription->subscription_number, $periodStart->toDateString(), $periodEnd->toDateString()),
            ]);

            $invoice->items()->createMany($computed['items']);

            return $invoice;
        });
    }

    private function resolveTaxRate(Subscription $subscription, Contact $contact): float
    {
        $configured = $subscription->metadata['tax_rate'] ?? $subscription->plan?->metadata['tax_rate'] ?? null;

        if ($configured !== null) {
            return (float) $configured;
        }

        return strtoupper((string) $contact->country) === 'CH' ? 8.1 : 22.0;
    }
}
