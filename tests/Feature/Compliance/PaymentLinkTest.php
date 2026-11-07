<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Models\PaymentLink;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function linkInvoice(Tenant $tenant): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-'.Str::random(4), 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

function stripeEvent(string $sessionId, int $amount = 12200, string $eventId = 'evt_1', string $type = 'checkout.session.completed'): string
{
    return (string) json_encode(['id' => $eventId, 'type' => $type, 'data' => ['object' => ['id' => $sessionId, 'amount_total' => $amount, 'currency' => 'eur', 'payment_status' => 'paid']]]);
}

function stripeWebhook(string $tenantId, string $payload, ?string $signature = null, ?int $time = null)
{
    $time ??= time();
    $signature ??= hash_hmac('sha256', "{$time}.{$payload}", 'whsec_test');

    return test()->call('POST', "/api/v1/webhooks/payment-links/{$tenantId}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature},v0=ignored"], $payload);
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    openPeriod($this->tenant);
    actingAsMember($this->tenant);
    $this->invoice = linkInvoice($this->tenant);
    Http::preventStrayRequests();
});

function configureStripe(): void
{
    test()->putJson('/api/v1/integrations/payment-gateway', ['provider' => 'stripe', 'credentials' => ['secret_key' => 'sk_test_1', 'webhook_secret' => 'whsec_test']])->assertOk();
}

function stripeLink(): array
{
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
    configureStripe();

    return test()->postJson('/api/v1/payment-links', ['invoice_id' => test()->invoice->id])->assertCreated()->json('data');
}

test('the local driver makes a link for the open balance without calling anyone', function (): void {
    $link = $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertCreated()->json('data');

    expect($link)->toMatchArray(['provider' => 'local', 'amount' => '122.00', 'status' => 'open']);
    Http::assertNothingSent();
});

test('the Stripe driver creates a Checkout Session in cents and stores no card data', function (): void {
    $link = stripeLink();

    expect($link)->toMatchArray(['provider' => 'stripe', 'provider_reference' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
        && $request['mode'] === 'payment'
        && $request['line_items'][0]['price_data']['unit_amount'] === 12200
        && $request['line_items'][0]['price_data']['currency'] === 'eur'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_1:')));
});

test('a signed webhook registers the payment through the ordinary flow, once', function (): void {
    stripeLink();
    $payload = stripeEvent('cs_test_1');

    stripeWebhook($this->tenant->id, $payload)->assertOk();
    stripeWebhook($this->tenant->id, $payload)->assertOk();

    expect(Payment::withoutGlobalScopes()->where('invoice_id', $this->invoice->id)->count())->toBe(1)
        ->and($this->invoice->fresh()->payment_status)->toBe('paid')
        ->and(PaymentLink::withoutGlobalScopes()->first()->status)->toBe('paid');
});

test('forged, stale and unsigned webhooks change nothing', function (): void {
    stripeLink();
    $payload = stripeEvent('cs_test_1');

    stripeWebhook($this->tenant->id, $payload, 'deadbeef')->assertStatus(400);
    stripeWebhook($this->tenant->id, $payload, null, time() - 3600)->assertStatus(400);
    $this->call('POST', "/api/v1/webhooks/payment-links/{$this->tenant->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);

    expect(Payment::withoutGlobalScopes()->count())->toBe(0);
});

test('a payment of another amount waits for a person', function (): void {
    stripeLink();

    stripeWebhook($this->tenant->id, stripeEvent('cs_test_1', 5000))->assertOk();

    expect(PaymentLink::withoutGlobalScopes()->first()->status)->toBe('review')
        ->and(Payment::withoutGlobalScopes()->count())->toBe(0);
});

test('other events are acknowledged and ignored', function (): void {
    stripeLink();

    stripeWebhook($this->tenant->id, stripeEvent('cs_test_1', 12200, 'evt_2', 'payment_intent.created'))->assertOk();

    expect(PaymentLink::withoutGlobalScopes()->first()->status)->toBe('open');
});

test('the signature of one tenant does not open another', function (): void {
    stripeLink();
    $other = complianceTenant('Beta');
    actingAsMember($other);
    test()->putJson('/api/v1/integrations/payment-gateway', ['provider' => 'stripe', 'credentials' => ['secret_key' => 'sk_2', 'webhook_secret' => 'whsec_other']])->assertOk();

    stripeWebhook($other->id, stripeEvent('cs_test_1'))->assertStatus(400);

    expect(Payment::withoutGlobalScopes()->count())->toBe(0);
});

test('a refused provider call leaves a failed link and a 422', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);
    configureStripe();

    $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertUnprocessable()->assertJsonValidationErrors('gateway');
});

test('settled invoices have no link, secrets stay hidden, and tenants and roles are enforced', function (): void {
    configureStripe();
    expect($this->getJson('/api/v1/integrations/payment-gateway')->assertOk()->getContent())->not->toContain('whsec_test')
        ->and(DB::table('payment_gateway_settings')->value('credentials'))->not->toContain('whsec_test');

    $this->invoice->update(['total_paid' => 122, 'payment_status' => 'paid']);
    $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertUnprocessable();

    actingAsMember(complianceTenant('Beta'));
    $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertNotFound();

    actingAsMember($this->tenant, 'member');
    $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertForbidden();
    $this->getJson('/api/v1/integrations/payment-gateway')->assertForbidden();
});
