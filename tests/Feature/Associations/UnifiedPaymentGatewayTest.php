<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\FeePayment;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\PaymentLink;
use Modules\Associations\Services\FeeService;
use Modules\Associations\Services\PaymentLinkService;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\Gateways\LocalPaymentLinkGateway;
use Modules\Integrations\Gateways\StripePaymentLinkGateway;
use Modules\Integrations\Services\PaymentGatewayResolver;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
    Mail::fake();
    Http::preventStrayRequests();
});

afterEach(fn () => associationsTearDown());

function unifiedGatewayInstalment(string $amount): FeeInstalment
{
    $plan = FeePlan::factory()->create(['frequency' => FeeFrequency::OneOff, 'amount' => $amount]);

    return app(FeeService::class)->assign(Member::factory()->create(), $plan, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'])->instalments->first();
}

test('invoices and fees share one contract and one set of drivers', function (): void {
    $resolver = app(PaymentGatewayResolver::class);

    expect(PaymentGatewayResolver::providers())->toBe(['local', 'stripe'])
        ->and($resolver->driver('local'))->toBeInstanceOf(LocalPaymentLinkGateway::class)
        ->and($resolver->driver('stripe'))->toBeInstanceOf(StripePaymentLinkGateway::class)
        ->and($resolver->driver('stripe'))->toBeInstanceOf(PaymentLinkGateway::class)
        ->and(interface_exists('Modules\Associations\Contracts\FeePaymentGateway'))->toBeFalse()
        ->and(class_exists('Modules\Associations\Gateways\LocalFeePaymentGateway'))->toBeFalse()
        ->and(fn () => $resolver->driver('paypal'))->toThrow(InvalidArgumentException::class);
});

test('a fee is paid online through the Stripe driver of the invoices', function (): void {
    openFiscalPeriod($this->tenant);
    config(['associations.payment_driver' => 'stripe', 'associations.payment_credentials' => ['secret_key' => 'sk_test_fees', 'webhook_secret' => 'whsec_fees']]);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_fee_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_fee_1'])]);
    $instalment = unifiedGatewayInstalment('45.50');

    $link = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payment-link")->assertCreated()->json('data');

    expect($link['reference'])->toBe('cs_fee_1')->and($link['url'])->toBe('https://checkout.stripe.com/c/pay/cs_fee_1');
    Http::assertSent(fn (Request $request): bool => $request['line_items'][0]['price_data']['unit_amount'] === 4550
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_fees:')));

    $payload = (string) json_encode(['id' => 'evt_fee_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_fee_1', 'amount_total' => 4550, 'currency' => 'eur', 'payment_status' => 'paid']]]);
    $time = time();
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", 'whsec_fees')];

    $this->call('POST', '/api/v1/association-payments/callback', [], [], [], [...$headers, 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=forged"], $payload)->assertForbidden();
    $this->call('POST', '/api/v1/association-payments/callback', [], [], [], $headers, $payload)->assertOk()->assertJsonPath('data.status', 'paid');

    expect(FeePayment::query()->count())->toBe(1)
        ->and($instalment->refresh()->status)->toBe(InstalmentStatus::Paid)
        ->and(PaymentLink::query()->firstOrFail()->provider)->toBe('stripe');
});

test('a failed payment reported by the local driver closes the link without a payment', function (): void {
    $instalment = unifiedGatewayInstalment('20.00');
    $link = $this->postJson("/api/v1/associations/instalments/{$instalment->id}/payment-link")->assertCreated()->json('data');
    $body = ['reference' => $link['reference'], 'amount' => '20.00', 'status' => 'failed'];
    $signature = LocalPaymentLinkGateway::sign((string) json_encode($body), PaymentLinkService::credentials()['webhook_secret']);

    $this->postJson('/api/v1/association-payments/callback', $body, [LocalPaymentLinkGateway::SignatureHeader => $signature])->assertOk()->assertJsonPath('data.status', 'failed');

    expect(FeePayment::query()->count())->toBe(0)
        ->and($instalment->refresh()->status)->toBe(InstalmentStatus::Open);
});
