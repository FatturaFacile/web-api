<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\Models\ApiKey;
use Modules\Integrations\Models\PaymentLink;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function rotationInvoice(Tenant $tenant): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-'.Str::random(4), 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    openPeriod($this->tenant);
    actingAsMember($this->tenant);
    $this->invoice = rotationInvoice($this->tenant);
    Http::preventStrayRequests();
});

test('rotating a key revokes the old one and keeps name, scopes and expiry', function (): void {
    $old = $this->postJson('/api/v1/integrations/api-keys', ['name' => 'Power BI', 'scopes' => ['reporting.read'], 'expires_at' => now()->addYear()->toDateTimeString()])->assertCreated()->json('data');

    $new = $this->postJson("/api/v1/integrations/api-keys/{$old['id']}/rotate")->assertCreated()->json('data');

    expect($new['token'])->toStartWith('ffk_')->and($new['token'])->not->toBe($old['token'])
        ->and($new['name'])->toBe('Power BI')->and($new['scopes'])->toBe(['reporting.read'])
        ->and($new['replaces'])->toBe($old['id'])
        ->and($new)->not->toHaveKey('key_hash')
        ->and(DB::table('api_keys')->where('id', $old['id'])->value('revoked_at'))->not->toBeNull()
        ->and(DB::table('api_keys')->where('id', $new['id'])->value('key_hash'))->toBe(hash('sha256', $new['token']));

    // A revoked key cannot be rotated again.
    $this->postJson("/api/v1/integrations/api-keys/{$old['id']}/rotate")->assertUnprocessable()->assertJsonValidationErrors('id');
});

test('a key is not rotated by a person who no longer holds one of its scopes', function (): void {
    $key = ApiKey::create(['tenant_id' => $this->tenant->id, 'name' => 'Old', 'prefix' => 'ffk_aaaaaaaa', 'key_hash' => hash('sha256', 'x'), 'scopes' => ['invoices.write']]);
    actingAsMember($this->tenant, 'member');

    // The role "member" has no access to the integrations module at all.
    $this->postJson("/api/v1/integrations/api-keys/{$key->id}/rotate")->assertForbidden();

    actingAsMember($this->tenant, 'admin');
    $key->forceFill(['scopes' => ['nonexistent_module.write']])->save();
    $this->postJson("/api/v1/integrations/api-keys/{$key->id}/rotate")->assertUnprocessable()->assertJsonValidationErrors('scopes');
    expect(ApiKey::query()->whereNull('revoked_at')->count())->toBe(1);
});

test('rotation answers 404 for the key of another tenant', function (): void {
    $other = complianceTenant('Beta');
    $key = ApiKey::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'prefix' => 'ffk_bbbbbbbb', 'key_hash' => hash('sha256', 'y'), 'scopes' => ['reporting.read']]);

    $this->postJson("/api/v1/integrations/api-keys/{$key->id}/rotate")->assertNotFound();
    $this->postJson('/api/v1/integrations/api-keys/'.Str::uuid().'/rotate')->assertNotFound();
    expect(ApiKey::withoutGlobalScopes()->whereKey($key->id)->value('revoked_at'))->toBeNull();
});

test('only one link per invoice can be open, a link is shown and can be cancelled', function (): void {
    $link = $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertCreated()->json('data');

    $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
    expect($this->getJson("/api/v1/payment-links/{$link['id']}")->assertOk()->json('data.status'))->toBe('open');

    $this->postJson("/api/v1/payment-links/{$link['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/payment-links/{$link['id']}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');

    // Once cancelled, the invoice can have a new link.
    $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertCreated();
    expect(PaymentLink::query()->count())->toBe(2);
});

test('a payment that arrives on a cancelled link is not booked, it waits for a person', function (): void {
    $this->putJson('/api/v1/integrations/payment-gateway', ['provider' => 'stripe', 'credentials' => ['secret_key' => 'sk_test_1', 'webhook_secret' => 'whsec_test']])->assertOk();
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_late', 'url' => 'https://checkout.stripe.com/c/pay/cs_late'])]);
    $link = $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertCreated()->json('data');
    $this->postJson("/api/v1/payment-links/{$link['id']}/cancel")->assertOk();

    $payload = (string) json_encode(['id' => 'evt_late', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_late', 'amount_total' => 12200, 'currency' => 'eur', 'payment_status' => 'paid']]]);
    $time = time();
    $this->call('POST', "/api/v1/webhooks/payment-links/{$this->tenant->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$payload}", 'whsec_test')], $payload)->assertOk();

    $saved = PaymentLink::query()->findOrFail($link['id']);
    expect($saved->status)->toBe('review')->and($saved->review_reason)->toContain('after the link was cancelled')
        ->and(Payment::withoutGlobalScopes()->count())->toBe(0);
});

test('payment links of another tenant are invisible and the routes are closed to read-only roles', function (): void {
    $link = $this->postJson('/api/v1/payment-links', ['invoice_id' => $this->invoice->id])->assertCreated()->json('data');

    actingAsMember(complianceTenant('Beta'));
    $this->getJson("/api/v1/payment-links/{$link['id']}")->assertNotFound();
    $this->postJson("/api/v1/payment-links/{$link['id']}/cancel")->assertNotFound();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->postJson("/api/v1/payment-links/{$link['id']}/cancel")->assertForbidden();
    $this->getJson("/api/v1/payment-links/{$link['id']}")->assertOk();
});
