<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Security\OutboundUrlGuard;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Communication\Exceptions\DeliveryFailedException;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\CommunicationTemplate;
use Modules\Communication\Models\DeliveryLog;
use Modules\Communication\Services\SmtpTransportFactory;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function templatePayload(array $overrides = []): array
{
    return ['code' => 'payment.reminder', 'channel' => 'email', 'locale' => 'en', 'subject' => 'Reminder {{ number }}', 'body' => 'Dear {{ name }}, invoice {{ number }} is overdue.', ...$overrides];
}

/**
 * @param  array<string, mixed>  $credentials
 * @return array<string, mixed>
 */
function smtpPayload(array $credentials = []): array
{
    return ['driver' => 'smtp', 'credentials' => ['host' => '93.184.216.34', 'port' => 587, 'encryption' => 'tls', 'username' => 'billing', 'password' => 'hunter2-secret', 'from_address' => 'billing@company.example', 'from_name' => 'Company Billing', ...$credentials]];
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    $this->admin = actingAsMember($this->tenant);
    Http::preventStrayRequests();
});

test('templates are created per code, channel and language, listed and shown with their placeholders', function (): void {
    $id = $this->postJson('/api/v1/communication/templates', templatePayload())->assertCreated()->assertJsonPath('data.placeholders', ['number', 'name'])->json('data.id');
    $this->postJson('/api/v1/communication/templates', templatePayload(['locale' => 'it_CH', 'subject' => 'Sollecito {{ number }}']))->assertCreated();
    $this->postJson('/api/v1/communication/templates', templatePayload(['channel' => 'sms', 'subject' => null, 'body' => 'Fattura {{ number }} scaduta']))->assertCreated();

    expect($this->getJson('/api/v1/communication/templates')->assertOk()->json('data'))->toHaveCount(3)
        ->and($this->getJson('/api/v1/communication/templates?locale=it_CH')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/communication/templates?channel=sms')->json('data'))->toHaveCount(1);

    $this->getJson("/api/v1/communication/templates/{$id}")->assertOk()->assertJsonPath('data.code', 'payment.reminder');
    $this->putJson("/api/v1/communication/templates/{$id}", templatePayload(['body' => 'Hello {{ name }}']))->assertOk()->assertJsonPath('data.placeholders', ['number', 'name']);
    $this->deleteJson("/api/v1/communication/templates/{$id}")->assertOk();
    $this->getJson("/api/v1/communication/templates/{$id}")->assertNotFound();
});

test('template input is validated: unique per language, plain placeholders, one-line subject, channel limits', function (): void {
    $this->postJson('/api/v1/communication/templates', templatePayload())->assertCreated();

    $this->postJson('/api/v1/communication/templates', templatePayload())->assertUnprocessable()->assertJsonValidationErrors(['channel']);
    $this->postJson('/api/v1/communication/templates', templatePayload(['locale' => 'de', 'body' => 'Hello {{ $name }}']))->assertUnprocessable()->assertJsonValidationErrors(['body']);
    $this->postJson('/api/v1/communication/templates', templatePayload(['locale' => 'fr', 'body' => '{{ name }} {{ unterminated']))->assertUnprocessable()->assertJsonValidationErrors(['body']);
    $this->postJson('/api/v1/communication/templates', templatePayload(['locale' => 'de', 'subject' => "Line one\r\nBcc: someone@evil.example"]))->assertUnprocessable()->assertJsonValidationErrors(['subject']);
    $this->postJson('/api/v1/communication/templates', templatePayload(['locale' => 'de', 'subject' => null]))->assertUnprocessable()->assertJsonValidationErrors(['subject']);
    $this->postJson('/api/v1/communication/templates', templatePayload(['locale' => 'Deutsch']))->assertUnprocessable()->assertJsonValidationErrors(['locale']);
    $this->postJson('/api/v1/communication/templates', templatePayload(['code' => 'Bad Code', 'locale' => 'de']))->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/v1/communication/templates', templatePayload(['channel' => 'sms', 'locale' => 'de', 'subject' => null, 'body' => str_repeat('x', 1001)]))->assertUnprocessable()->assertJsonValidationErrors(['body']);
    $this->postJson('/api/v1/communication/templates', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'channel', 'locale', 'body']);
});

test('a preview fills in the placeholders, refuses missing variables and keeps line breaks out of the subject', function (): void {
    $id = $this->postJson('/api/v1/communication/templates', templatePayload())->json('data.id');

    $this->postJson("/api/v1/communication/templates/{$id}/preview", ['variables' => ['name' => 'Rossi SA', 'number' => "INV-1\r\nBcc: evil@example.com"]])
        ->assertOk()
        ->assertJsonPath('data.subject', 'Reminder INV-1 Bcc: evil@example.com')
        ->assertJsonPath('data.body', "Dear Rossi SA, invoice INV-1\r\nBcc: evil@example.com is overdue.");

    $this->postJson("/api/v1/communication/templates/{$id}/preview", ['variables' => ['name' => 'Rossi SA']])->assertUnprocessable()->assertJsonValidationErrors(['variables']);
    $this->postJson("/api/v1/communication/templates/{$id}/preview", ['variables' => ['name' => ['nested' => 'x'], 'number' => '1']])->assertUnprocessable()->assertJsonValidationErrors(['variables.name']);
});

test('a template message picks the best language and is logged without its body', function (): void {
    $this->postJson('/api/v1/communication/templates', templatePayload(['channel' => 'sms', 'subject' => null, 'body' => 'Scaduta {{ number }}', 'locale' => 'it']))->assertCreated();
    $this->postJson('/api/v1/communication/templates', templatePayload(['channel' => 'sms', 'subject' => null, 'body' => 'Overdue {{ number }}', 'locale' => 'en']))->assertCreated();
    $send = fn (string $locale) => $this->postJson('/api/v1/communication/messages/send', ['channel' => 'sms', 'recipient' => '+41791234567', 'template_code' => 'payment.reminder', 'locale' => $locale, 'variables' => ['number' => 'INV-9']]);

    expect($send('it_CH')->assertCreated()->json('data'))->toMatchArray(['status' => 'simulated', 'locale' => 'it', 'template_code' => 'payment.reminder', 'message_type' => 'message', 'recipient' => '+41791234567'])
        ->and($send('de')->assertCreated()->json('data.locale'))->toBe('en');

    $columns = Schema::getColumnListing('delivery_logs');
    expect($columns)->not->toContain('body')->and(DeliveryLog::query()->where('message_type', 'message')->count())->toBe(2);
});

test('a template message needs an active template, all its variables and a recipient of the right shape', function (): void {
    $this->postJson('/api/v1/communication/templates', templatePayload(['channel' => 'sms', 'subject' => null, 'body' => 'Scaduta {{ number }}']))->assertCreated();
    $message = ['channel' => 'sms', 'recipient' => '+41791234567', 'template_code' => 'payment.reminder', 'variables' => ['number' => '1']];

    $this->postJson('/api/v1/communication/messages/send', [...$message, 'template_code' => 'unknown.code'])->assertNotFound();
    $this->postJson('/api/v1/communication/messages/send', [...$message, 'variables' => []])->assertUnprocessable()->assertJsonValidationErrors(['variables']);
    $this->postJson('/api/v1/communication/messages/send', [...$message, 'recipient' => 'not a phone'])->assertUnprocessable()->assertJsonValidationErrors(['recipient']);
    $this->postJson('/api/v1/communication/messages/send', [...$message, 'channel' => 'email', 'recipient' => '+41791234567'])->assertUnprocessable()->assertJsonValidationErrors(['recipient']);
    $this->postJson('/api/v1/communication/messages/send', [...$message, 'channel' => 'telegram', 'recipient' => 'someone'])->assertUnprocessable()->assertJsonValidationErrors(['recipient']);
    $this->postJson('/api/v1/communication/messages/send', [...$message, 'channel' => 'pigeon'])->assertUnprocessable()->assertJsonValidationErrors(['channel']);

    CommunicationTemplate::query()->update(['is_active' => false]);
    $this->postJson('/api/v1/communication/messages/send', $message)->assertNotFound();

    CommunicationChannel::create(['tenant_id' => $this->tenant->id, 'channel' => 'sms', 'driver' => 'local', 'is_enabled' => false]);
    CommunicationTemplate::query()->update(['is_active' => true]);
    $this->postJson('/api/v1/communication/messages/send', $message)->assertUnprocessable()->assertJsonValidationErrors(['channel']);
});

test('a message through the e-mail of the installation is sent and logged with its subject', function (): void {
    Mail::fake();
    Mail::shouldReceive('raw')->once();
    $this->postJson('/api/v1/communication/templates', templatePayload())->assertCreated();

    $log = $this->postJson('/api/v1/communication/messages/send', ['channel' => 'email', 'recipient' => 'accounting@customer.example', 'template_code' => 'payment.reminder', 'variables' => ['name' => 'Rossi', 'number' => 'INV-5']])->assertCreated()->json('data');

    expect($log)->toMatchArray(['status' => 'sent', 'driver' => 'mail', 'subject' => 'Reminder INV-5', 'message_type' => 'message'])
        ->and($log['document_id'] ?? null)->toBeNull();
});

test('a document is sent with the template of the requested language when the company has one', function (): void {
    Mail::fake();
    Mail::shouldReceive('raw')->once();
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Cliente', 'email' => 'cliente@example.com']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-77', 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $this->tenant->id;
    $invoice->save();
    $this->postJson('/api/v1/communication/templates', templatePayload(['code' => 'document.invoice', 'locale' => 'it', 'subject' => 'La tua fattura {{ number }}', 'body' => 'Gentile {{ contact_name }}, in allegato la fattura {{ number }}. {{ note }}']))->assertCreated();

    $log = $this->postJson('/api/v1/communication/documents/send', ['document_type' => 'invoice', 'document_id' => $invoice->id, 'channel' => 'email', 'locale' => 'it_CH', 'message' => 'Grazie'])->assertCreated()->json('data');

    expect($log)->toMatchArray(['subject' => 'La tua fattura FT-77', 'template_code' => 'document.invoice', 'locale' => 'it', 'message_type' => 'document', 'document_type' => 'invoice']);

    $this->postJson('/api/v1/communication/documents/send', ['document_type' => 'invoice', 'document_id' => $invoice->id, 'channel' => 'email', 'recipient' => "a@b.example\r\nBcc: x@y.example"])->assertUnprocessable()->assertJsonValidationErrors(['recipient']);
    $this->postJson('/api/v1/communication/documents/send', ['document_type' => 'invoice', 'document_id' => $invoice->id, 'channel' => 'email', 'locale' => 'italiano'])->assertUnprocessable()->assertJsonValidationErrors(['locale']);
});

test('the smtp settings of a company are validated, stored encrypted and never shown again', function (): void {
    $response = $this->putJson('/api/v1/communication/channels/email', smtpPayload())->assertOk();

    expect($response->json('data'))->toMatchArray(['driver' => 'smtp', 'has_credentials' => true, 'secrets_set' => ['password']])
        ->and($response->json('data.settings'))->toMatchArray(['host' => '93.184.216.34', 'port' => '587', 'from_address' => 'billing@company.example'])
        ->and($response->json('data.settings'))->not->toHaveKey('password')
        ->and($response->getContent())->not->toContain('hunter2-secret');

    $stored = (string) DB::table('communication_channels')->value('credentials');
    expect($stored)->not->toContain('hunter2-secret')->and($stored)->not->toContain('93.184.216.34');

    $listing = $this->getJson('/api/v1/communication/channels')->assertOk();
    expect($listing->getContent())->not->toContain('hunter2-secret')->and($listing->json('data.0.secrets_set'))->toBe(['password']);
});

test('the smtp server must be a public host and the settings complete', function (string $host): void {
    $this->putJson('/api/v1/communication/channels/email', smtpPayload(['host' => $host]))->assertUnprocessable()->assertJsonValidationErrors(['credentials.host']);
    expect(CommunicationChannel::query()->count())->toBe(0);
})->with(['127.0.0.1', 'localhost', '169.254.169.254', '10.0.0.5', '192.168.1.10', '[::1]', 'mail.internal', '2130706433']);

test('smtp settings reject missing server or sender, bad values and credentials of another driver', function (): void {
    $this->putJson('/api/v1/communication/channels/email', ['driver' => 'smtp', 'credentials' => ['from_address' => 'a@b.example']])->assertUnprocessable()->assertJsonValidationErrors(['credentials.host']);
    $this->putJson('/api/v1/communication/channels/email', ['driver' => 'smtp', 'credentials' => ['host' => '93.184.216.34']])->assertUnprocessable()->assertJsonValidationErrors(['credentials.from_address']);
    $this->putJson('/api/v1/communication/channels/email', smtpPayload(['port' => 99999]))->assertUnprocessable()->assertJsonValidationErrors(['credentials.port']);
    $this->putJson('/api/v1/communication/channels/email', smtpPayload(['encryption' => 'rot13']))->assertUnprocessable()->assertJsonValidationErrors(['credentials.encryption']);
    $this->putJson('/api/v1/communication/channels/email', smtpPayload(['from_address' => 'not-an-address']))->assertUnprocessable()->assertJsonValidationErrors(['credentials.from_address']);
    $this->putJson('/api/v1/communication/channels/email', smtpPayload(['from_name' => "Evil\r\nBcc: x@y.example"]))->assertUnprocessable()->assertJsonValidationErrors(['credentials.from_name']);
    $this->putJson('/api/v1/communication/channels/email', smtpPayload(['bot_token' => 'x']))->assertUnprocessable()->assertJsonValidationErrors(['credentials.bot_token']);
    $this->putJson('/api/v1/communication/channels/email', ['driver' => 'twilio'])->assertUnprocessable()->assertJsonValidationErrors(['driver']);
    $this->putJson('/api/v1/communication/channels/sms', ['driver' => 'smtp'])->assertUnprocessable()->assertJsonValidationErrors(['driver']);
});

test('saving merges the credentials: a secret is kept unless it is sent, null removes it, a new driver drops the old keys', function (): void {
    $this->putJson('/api/v1/communication/channels/email', smtpPayload())->assertOk();

    $kept = $this->putJson('/api/v1/communication/channels/email', ['driver' => 'smtp', 'credentials' => ['from_name' => 'Billing Team']])->assertOk();
    expect($kept->json('data.secrets_set'))->toBe(['password'])->and($kept->json('data.settings.host'))->toBe('93.184.216.34')->and($kept->json('data.settings.from_name'))->toBe('Billing Team');

    $removed = $this->putJson('/api/v1/communication/channels/email', ['driver' => 'smtp', 'credentials' => ['password' => null, 'username' => null]])->assertOk();
    expect($removed->json('data.secrets_set'))->toBe([])->and($removed->json('data.settings'))->not->toHaveKey('username');

    $switched = $this->putJson('/api/v1/communication/channels/email', ['driver' => 'mail'])->assertOk();
    expect($switched->json('data.has_credentials'))->toBeFalse()->and(CommunicationChannel::query()->firstOrFail()->credentials)->toBeNull();
});

test('channel changes are audited by key name, never by value', function (): void {
    $this->putJson('/api/v1/communication/channels/email', smtpPayload())->assertOk();
    $this->putJson('/api/v1/communication/channels/email', ['driver' => 'smtp', 'credentials' => ['password' => 'new-secret-value']])->assertOk();

    $entries = AuditLedgerEntry::query()->whereIn('action', ['COMMUNICATION_CHANNEL_CONFIGURED', 'COMMUNICATION_CHANNEL_UPDATED'])->orderBy('event_timestamp')->get();
    $payloads = json_encode($entries->map(fn (AuditLedgerEntry $entry): array => [$entry->payload_before, $entry->payload_after])->all());

    expect($entries->pluck('action')->all())->toBe(['COMMUNICATION_CHANNEL_CONFIGURED', 'COMMUNICATION_CHANNEL_UPDATED'])
        ->and($entries[1]->payload_after['credential_keys_changed'])->toBe(['password'])
        ->and($payloads)->not->toContain('hunter2-secret')->and($payloads)->not->toContain('new-secret-value')->and($payloads)->not->toContain('93.184.216.34');
});

test('an e-mail goes through the company smtp server and is logged with the smtp driver', function (): void {
    app()->bind(SmtpTransportFactory::class, fn () => new class(app(OutboundUrlGuard::class)) extends SmtpTransportFactory
    {
        public function make(array $credentials): TransportInterface
        {
            return new NullTransport;
        }
    });

    $this->putJson('/api/v1/communication/channels/email', smtpPayload())->assertOk();
    $this->postJson('/api/v1/communication/templates', templatePayload())->assertCreated();

    $log = $this->postJson('/api/v1/communication/messages/send', ['channel' => 'email', 'recipient' => 'accounting@customer.example', 'template_code' => 'payment.reminder', 'variables' => ['name' => 'Rossi', 'number' => 'INV-5']])->assertCreated()->json('data');

    expect($log)->toMatchArray(['status' => 'sent', 'driver' => 'smtp', 'subject' => 'Reminder INV-5']);
    $this->postJson('/api/v1/communication/channels/email/test', ['recipient' => 'it@company.example'])->assertCreated()->assertJsonPath('data.driver', 'smtp');
});

test('a smtp host that turned private is refused when the message is sent', function (): void {
    CommunicationChannel::create(['tenant_id' => $this->tenant->id, 'channel' => 'email', 'driver' => 'smtp', 'is_enabled' => true, 'credentials' => ['host' => '10.1.2.3', 'port' => '25', 'from_address' => 'billing@company.example']]);

    $response = $this->postJson('/api/v1/communication/channels/email/test', ['recipient' => 'it@company.example'])->assertStatus(502);

    expect($response->json('message'))->toContain('public host')
        ->and(DeliveryLog::query()->latest()->firstOrFail()->status)->toBe('failed');
});

test('the transport factory connects to the address it checked and requires tls by default', function (): void {
    $factory = app(SmtpTransportFactory::class);

    $transport = $factory->make(['host' => '93.184.216.34', 'port' => '465', 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p']);

    expect($transport)->toBeInstanceOf(EsmtpTransport::class)->and($transport->getStream()->getHost())->toBe('93.184.216.34');
    expect(fn () => $factory->make(['host' => '127.0.0.1']))->toThrow(DeliveryFailedException::class, 'public host');
    expect(fn () => $factory->make([]))->toThrow(DeliveryFailedException::class, 'not configured');
});

test('the channel test is for administrators and checks the recipient and the channel', function (): void {
    $this->postJson('/api/v1/communication/channels/email/test', ['recipient' => 'not an address'])->assertUnprocessable()->assertJsonValidationErrors(['recipient']);
    $this->postJson('/api/v1/communication/channels/sms/test', ['recipient' => '+41791234567'])->assertUnprocessable()->assertJsonValidationErrors(['channel']);
    $this->postJson('/api/v1/communication/channels/pigeon/test', ['recipient' => 'x'])->assertNotFound();

    actingAsMember($this->tenant, 'accountant');
    $this->postJson('/api/v1/communication/channels/email/test', ['recipient' => 'it@company.example'])->assertForbidden();
    $this->putJson('/api/v1/communication/channels/email', smtpPayload())->assertForbidden();
    $this->getJson('/api/v1/communication/channels')->assertForbidden();
});

test('the delivery log is filtered, shown one by one and kept inside the company', function (): void {
    $this->postJson('/api/v1/communication/templates', templatePayload(['channel' => 'sms', 'subject' => null, 'body' => 'Hi {{ name }}']))->assertCreated();
    $id = $this->postJson('/api/v1/communication/messages/send', ['channel' => 'sms', 'recipient' => '+41791234567', 'template_code' => 'payment.reminder', 'variables' => ['name' => 'x']])->json('data.id');
    DeliveryLog::create(['tenant_id' => $this->tenant->id, 'channel' => 'email', 'driver' => 'mail', 'document_type' => 'invoice', 'document_id' => (string) Str::uuid(), 'recipient' => 'a@b.example', 'status' => 'failed', 'error' => 'refused']);

    expect($this->getJson('/api/v1/communication/deliveries?message_type=message')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/communication/deliveries?status=failed')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/communication/deliveries?channel=sms')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/communication/deliveries?from='.now()->addDay()->toDateString())->json('data'))->toHaveCount(0);
    $this->getJson('/api/v1/communication/deliveries?status=lost')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->getJson("/api/v1/communication/deliveries/{$id}")->assertOk()->assertJsonPath('data.subject', 'payment.reminder');

    actingAsMember(complianceTenant('Beta'));
    $this->getJson("/api/v1/communication/deliveries/{$id}")->assertNotFound();
    expect($this->getJson('/api/v1/communication/deliveries')->json('data'))->toBe([]);
});

test('templates and messages of another company are not reachable, and read-only roles cannot write', function (): void {
    $id = $this->postJson('/api/v1/communication/templates', templatePayload())->json('data.id');

    actingAsMember($this->tenant, 'accountant_readonly');
    expect($this->getJson('/api/v1/communication/templates')->assertOk()->json('data'))->toHaveCount(1);
    $this->postJson('/api/v1/communication/templates', templatePayload(['locale' => 'de']))->assertForbidden();
    $this->putJson("/api/v1/communication/templates/{$id}", templatePayload())->assertForbidden();
    $this->deleteJson("/api/v1/communication/templates/{$id}")->assertForbidden();
    $this->postJson('/api/v1/communication/messages/send', ['channel' => 'sms', 'recipient' => '+41791234567', 'template_code' => 'payment.reminder'])->assertForbidden();

    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/communication/templates')->assertForbidden();

    actingAsMember(complianceTenant('Beta'));
    $this->getJson("/api/v1/communication/templates/{$id}")->assertNotFound();
    $this->putJson("/api/v1/communication/templates/{$id}", templatePayload())->assertNotFound();
    $this->postJson("/api/v1/communication/templates/{$id}/preview", [])->assertNotFound();
    $this->deleteJson("/api/v1/communication/templates/{$id}")->assertNotFound();
    $this->postJson('/api/v1/communication/messages/send', ['channel' => 'sms', 'recipient' => '+41791234567', 'template_code' => 'payment.reminder'])->assertNotFound();
});
