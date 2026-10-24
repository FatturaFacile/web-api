<?php

declare(strict_types=1);

use App\Contracts\SdiProvider;
use App\Exceptions\SdiProviderException;
use App\Jobs\SendPaymentReminderEmail;
use App\Mail\PaymentReminderMail;
use App\Mail\SubscriptionRenewalMail;
use App\Models\Contact;
use App\Models\EiInboundLog;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->user = User::create([
        'name' => 'Leonardo',
        'email' => 'leo@fatturafacile.com',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Milan Customer SpA',
        'country' => 'IT',
        'vat_number' => '01234567890',
        'sdi_code' => '0000000',
        'pec' => 'customer@pec.example.com',
        'email' => 'customer@example.com',
    ]);
});

function sdiApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

function sdiInvoice(array $attributes = []): Invoice
{
    $invoice = Invoice::create(array_merge([
        'tenant_id' => test()->tenant->id,
        'contact_id' => test()->contact->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01',
        'due_date' => '2026-03-31',
        'currency' => 'EUR',
        'total_net' => 100,
        'total_tax' => 22,
        'total_gross' => 122,
        'jurisdiction' => 'IT',
    ], $attributes));
    $invoice->items()->create(['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22, 'line_total' => 100]);

    return $invoice;
}

// ------------------------------------------------------------ Preferences

test('tenant preferences are stored per group, validated, and cleared with null', function (): void {
    expect(sdiApi()->getJson('/api/v1/settings/preferences')->assertOk()->json('data'))
        ->toEqual(['invoice' => [], 'email' => [], 'sdi' => [], 'work_orders' => [], 'company' => [], 'labels' => [], 'inventory' => [], 'banking' => [], 'accounting' => []]);

    sdiApi()->putJson('/api/v1/settings/preferences/invoice', ['footer_text' => 'Thank you', 'reminder_interval_days' => 7])
        ->assertOk()->assertJsonPath('data.reminder_interval_days', 7);
    sdiApi()->putJson('/api/v1/settings/preferences/invoice', ['footer_text' => null])->assertOk();

    expect(sdiApi()->getJson('/api/v1/settings/preferences')->json('data.invoice'))->toEqual(['reminder_interval_days' => 7]);

    sdiApi()->putJson('/api/v1/settings/preferences/invoice', ['reminder_max_level' => 9])
        ->assertUnprocessable()->assertJsonValidationErrors('reminder_max_level');
    sdiApi()->putJson('/api/v1/settings/preferences/email', ['reply_to' => 'not-an-email'])
        ->assertUnprocessable()->assertJsonValidationErrors('reply_to');
    sdiApi()->putJson('/api/v1/settings/preferences/unknown', ['x' => 1])->assertNotFound();
});

test('unrelated tenant settings survive a preferences update', function (): void {
    $this->tenant->update(['settings' => ['regime_fiscale' => 'RF19']]);

    sdiApi()->putJson('/api/v1/settings/preferences/sdi', ['send_enabled' => true])->assertOk();

    expect($this->tenant->refresh()->settings['regime_fiscale'])->toBe('RF19')
        ->and($this->tenant->settings['preferences']['sdi']['send_enabled'])->toBeTrue();
});

test('reminders honour the tenant interval preference', function (): void {
    Queue::fake();
    sdiInvoice(['due_date' => '2026-03-01']);
    sdiApi()->putJson('/api/v1/settings/preferences/invoice', ['reminder_interval_days' => 1])->assertOk();

    $this->artisan('invoices:send-reminders')->assertSuccessful();
    $this->travelTo('2026-03-17 10:00:00'); // default interval is 15 days, tenant override is 1
    $this->artisan('invoices:send-reminders')->assertSuccessful();

    Queue::assertPushed(SendPaymentReminderEmail::class, 2);
});

// ------------------------------------------------------------ Sending to SDI

test('invoice is transmitted to SDI, logged and linked by UUID', function (): void {
    $invoice = sdiInvoice();

    $response = sdiApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/send-sdi")->assertStatus(202);
    $uuid = $response->json('data.sdi_identifier');

    expect($response->json('data.sdi_status'))->toBe('sending')
        ->and($response->json('data'))->not->toHaveKey('xml_content')
        ->and($response->json('data.file_name'))->toMatch('/^IT00811720580_[0-9A-Z]{5}\.xml$/')
        ->and(DB::table('sdi_uuid_links')->where('sdi_uuid', $uuid)->where('invoice_id', $invoice->id)->exists())->toBeTrue();

    $logs = sdiApi()->getJson("/api/v1/sales/invoices/{$invoice->id}/sdi-logs")->assertOk()->json('data');
    expect($logs['outbound'])->toHaveCount(1)->and($logs['outbound'][0]['status'])->toBe('success')->and($logs['inbound'])->toBeEmpty();

    sdiApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/send-sdi")->assertUnprocessable();
});

test('a provider failure is logged, flagged on the invoice and can be retried', function (): void {
    $invoice = sdiInvoice();

    // Controllers are cached per route inside a test, so the provider is a stateful fake rather than a rebind.
    $provider = new class implements SdiProvider
    {
        public bool $failing = true;

        public function name(): string
        {
            return 'flaky';
        }

        public function send(string $fileName, string $xml): array
        {
            if ($this->failing) {
                throw new SdiProviderException('Gateway timeout');
            }

            return ['uuid' => 'flaky-uuid-1', 'response' => ['accepted' => true]];
        }
    };
    $this->app->instance(SdiProvider::class, $provider);
    sdiApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/send-sdi")->assertStatus(502);

    expect($invoice->sdiMetadata()->first()->sdi_status)->toBe('failed')
        ->and(DB::table('ei_outbound_logs')->where('status', 'failed')->value('error'))->toBe('Gateway timeout');

    $provider->failing = false;
    sdiApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/send-sdi")->assertStatus(202);
});

test('ineligible invoices and disabled tenants cannot send to SDI', function (): void {
    $swiss = sdiInvoice(['invoice_number' => 'CH-1', 'jurisdiction' => 'CH', 'invoice_type' => 'ch_qr_bill']);
    $proforma = sdiInvoice(['invoice_number' => 'PF-1', 'invoice_type' => 'proforma']);
    $ok = sdiInvoice(['invoice_number' => 'INV-2']);

    sdiApi()->postJson("/api/v1/sales/invoices/{$swiss->id}/send-sdi")->assertUnprocessable();
    sdiApi()->postJson("/api/v1/sales/invoices/{$proforma->id}/send-sdi")->assertUnprocessable();

    sdiApi()->putJson('/api/v1/settings/preferences/sdi', ['send_enabled' => false])->assertOk();
    sdiApi()->postJson("/api/v1/sales/invoices/{$ok->id}/send-sdi")->assertUnprocessable();
});

// ------------------------------------------------------------ Webhook

function webhook(array $payload, ?string $secret = 'topsecret')
{
    config(['services.sdi.webhook_secret' => 'topsecret']);

    return test()->withHeaders($secret === null ? [] : ['X-Webhook-Secret' => $secret])->postJson('/api/v1/webhooks/sdi', $payload);
}

test('webhook rejects missing or wrong secrets', function (): void {
    $payload = ['provider' => 'local', 'uuid' => 'abc', 'event_type' => 'RC'];

    webhook($payload, null)->assertUnauthorized();
    webhook($payload, 'wrong')->assertUnauthorized();

    config(['services.sdi.webhook_secret' => null]);
    $this->withHeaders(['X-Webhook-Secret' => ''])->postJson('/api/v1/webhooks/sdi', $payload)->assertUnauthorized();
    expect(EiInboundLog::count())->toBe(0);
});

test('webhook updates the invoice SDI status idempotently and ignores unknown UUIDs', function (): void {
    $invoice = sdiInvoice();
    $uuid = sdiApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/send-sdi")->json('data.sdi_identifier');
    $payload = ['provider' => 'local', 'uuid' => $uuid, 'event_type' => 'RC', 'message' => 'Delivered'];

    webhook($payload)->assertOk()->assertJsonPath('data.status', 'processed')->assertJsonPath('data.invoice_id', $invoice->id);
    webhook($payload)->assertOk(); // duplicate delivery

    $metadata = $invoice->sdiMetadata()->first();
    expect($metadata->sdi_status)->toBe('delivered')->and($metadata->delivered_at)->not->toBeNull()
        ->and(EiInboundLog::count())->toBe(1)
        ->and(DB::table('document_events')->where('eventable_id', $invoice->id)->where('event', 'sdi_notification')->count())->toBe(1);

    webhook(['provider' => 'local', 'uuid' => 'does-not-exist', 'event_type' => 'RC'])
        ->assertOk()->assertJsonPath('data.status', 'ignored');
    webhook(['provider' => 'local', 'uuid' => $uuid, 'event_type' => 'XYZ'])
        ->assertOk()->assertJsonPath('data.status', 'ignored');
});

test('final customer outcomes are not overwritten by later notifications', function (): void {
    $invoice = sdiInvoice();
    $uuid = sdiApi()->postJson("/api/v1/sales/invoices/{$invoice->id}/send-sdi")->json('data.sdi_identifier');

    webhook(['provider' => 'local', 'uuid' => $uuid, 'event_type' => 'NEC1'])->assertOk()->assertJsonPath('data.status', 'processed');
    webhook(['provider' => 'local', 'uuid' => $uuid, 'event_type' => 'MC'])->assertOk()->assertJsonPath('data.status', 'ignored');

    expect($invoice->sdiMetadata()->first()->sdi_status)->toBe('accepted');
});

// ------------------------------------------------------------ English email content

test('reminder and renewal emails render English text from translation keys', function (): void {
    $invoice = sdiInvoice();
    $reminder = PaymentReminder::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'level' => 2,
        'recipient' => 'customer@example.com', 'amount_due' => 122,
    ]);

    $mail = new PaymentReminderMail($reminder->load('invoice.contact'));
    expect($mail->envelope()->subject)->toBe('Overdue payment notice - invoice INV-2026-0001');
    $mail->assertSeeInHtml('Dear Milan Customer SpA,')
        ->assertSeeInHtml('Outstanding amount: <strong>122.00 EUR</strong>', false)
        ->assertSeeInHtml('Kind regards');

    $subscription = Subscription::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $this->contact->id, 'subscription_number' => 'SUB-2026-0001',
        'name' => 'Hosting', 'interval' => 'monthly', 'currency' => 'EUR', 'start_date' => '2026-01-01', 'end_date' => '2026-04-01',
        'auto_renew' => true,
    ]);
    $renewal = new SubscriptionRenewalMail($subscription->load('contact'));
    expect($renewal->envelope()->subject)->toBe('Subscription renewal SUB-2026-0001');
    $renewal->assertSeeInHtml('It will be renewed automatically.');
});
