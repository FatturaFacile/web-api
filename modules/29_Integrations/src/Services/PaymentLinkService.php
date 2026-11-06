<?php

declare(strict_types=1);

namespace Modules\Integrations\Services;

use App\Actions\Sales\RecordPayment;
use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\DTOs\PaymentEvent;
use Modules\Integrations\Exceptions\PaymentGatewayException;
use Modules\Integrations\Gateways\LocalPaymentLinkGateway;
use Modules\Integrations\Gateways\StripePaymentLinkGateway;
use Modules\Integrations\Models\PaymentGatewaySetting;
use Modules\Integrations\Models\PaymentLink;
use Modules\Integrations\Models\PaymentWebhookEvent;

/**
 * Payment links of invoices. A confirmed payment goes through RecordPayment, so the balance of the invoice and the
 * ledger follow the same path as any other collection.
 */
final readonly class PaymentLinkService
{
    public function __construct(
        private RecordPayment $payments,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @throws ValidationException when the invoice has nothing to collect or the provider refuses
     */
    public function create(Invoice $invoice): PaymentLink
    {
        $amount = bcsub((string) $invoice->total_gross, (string) $invoice->total_paid, 2);

        if ($invoice->invoice_type === 'proforma' || bccomp($amount, '0', 2) <= 0 || ! in_array($invoice->currency, ['EUR', 'CHF'], true)) {
            throw ValidationException::withMessages(['invoice_id' => __('sales.payment_link_not_payable')]);
        }

        $settings = $this->settings();
        $gateway = $this->gateway($settings?->provider ?? 'local');

        $link = PaymentLink::create([
            'invoice_id' => $invoice->id,
            'provider' => $gateway->name(),
            'amount' => $amount,
            'currency' => $invoice->currency,
            'status' => 'pending',
        ]);

        try {
            $created = $gateway->createLink($link, __('sales.payment_link_description', ['number' => $invoice->invoice_number]), $settings->credentials ?? []);
        } catch (PaymentGatewayException $e) {
            $link->update(['status' => 'failed']);

            throw ValidationException::withMessages(['gateway' => $e->getMessage()]);
        }

        $link->update(['provider_reference' => $created['reference'], 'url' => $created['url'], 'status' => 'open']);

        return $link;
    }

    /**
     * @throws PaymentGatewayException when the call is not authentic
     */
    public function handleWebhook(Request $request): void
    {
        $settings = $this->settings();
        $gateway = $this->gateway($settings?->provider ?? 'local');
        $event = $gateway->parseWebhook($request, $settings->credentials ?? []);

        if ($event === null) {
            return;
        }

        DB::transaction(function () use ($event, $gateway): void {
            $fresh = PaymentWebhookEvent::firstOrCreate(['tenant_id' => $this->tenantContext->getTenantId(), 'provider' => $gateway->name(), 'event_id' => $event->eventId]);

            if ($fresh->wasRecentlyCreated) {
                $this->register($event, $gateway->name());
            }
        });
    }

    /**
     * A payment that does not match the link, or that the invoice cannot take any more, is kept for a person to look at.
     */
    private function register(PaymentEvent $event, string $provider): void
    {
        $link = PaymentLink::where('provider', $provider)->where('provider_reference', $event->reference)->lockForUpdate()->first();

        if ($link === null || $link->status === 'paid') {
            return;
        }

        if (bccomp($event->amount, (string) $link->amount, 2) !== 0 || $event->currency !== $link->currency) {
            $link->update(['status' => 'review', 'review_reason' => "Paid {$event->amount} {$event->currency}, expected {$link->amount} {$link->currency}."]);

            return;
        }

        try {
            $payment = $this->payments->execute($link->invoice, [
                'amount' => (string) $link->amount,
                'paid_at' => now()->toDateString(),
                'payment_method' => $provider,
                'reference' => mb_substr($event->reference, 0, 100),
            ]);
        } catch (ValidationException $e) {
            $link->update(['status' => 'review', 'review_reason' => $e->getMessage()]);

            return;
        }

        $link->update(['status' => 'paid', 'payment_id' => $payment->id, 'paid_at' => now()]);
    }

    private function settings(): ?PaymentGatewaySetting
    {
        return PaymentGatewaySetting::where('tenant_id', $this->tenantContext->getTenantId())->first();
    }

    private function gateway(string $provider): PaymentLinkGateway
    {
        return app($provider === 'stripe' ? StripePaymentLinkGateway::class : LocalPaymentLinkGateway::class);
    }
}
