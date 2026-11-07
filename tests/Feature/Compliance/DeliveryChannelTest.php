<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Communication\Models\DeliveryLog;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function deliverable(Tenant $tenant): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente', 'email' => 'cliente@example.com', 'phone' => '+393331112222']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'FT-'.Str::random(4), 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    return $invoice;
}

function sendOn(string $channel, array $overrides = [])
{
    return test()->postJson('/api/v1/communication/documents/send', ['document_type' => 'invoice', 'document_id' => test()->invoice->id, 'channel' => $channel, ...$overrides]);
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
    $this->invoice = deliverable($this->tenant);
    Http::preventStrayRequests();
});

test('without configuration a channel only simulates and says so', function (string $channel): void {
    expect(sendOn($channel, ['recipient' => '12345'])->assertCreated()->json('data'))->toMatchArray(['status' => 'simulated', 'driver' => 'local', 'channel' => $channel]);
    Http::assertNothingSent();
})->with(['whatsapp', 'telegram', 'sms']);

test('e-mail goes through the mailer to the address of the contact', function (): void {
    Mail::fake();
    Mail::shouldReceive('raw')->once();

    expect(sendOn('email')->assertCreated()->json('data'))->toMatchArray(['status' => 'sent', 'recipient' => 'cliente@example.com', 'driver' => 'mail']);
});

test('Twilio sends SMS and WhatsApp with the documented shape', function (string $channel, string $to): void {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);
    $this->putJson("/api/v1/communication/channels/{$channel}", ['driver' => 'twilio', 'credentials' => ['account_sid' => 'AC1', 'auth_token' => 'secret', 'from' => '+15550001111']])->assertOk();

    expect(sendOn($channel)->assertCreated()->json('data.provider_reference'))->toBe('SM123');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC1/Messages.json'
        && $request['To'] === $to
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC1:secret')));
})->with([
    ['sms', '+393331112222'],
    ['whatsapp', 'whatsapp:+393331112222'],
]);

test('Telegram sends to the chat with the bot token', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
    $this->putJson('/api/v1/communication/channels/telegram', ['driver' => 'telegram', 'credentials' => ['bot_token' => '123:ABC']])->assertOk();

    expect(sendOn('telegram', ['recipient' => '-100200'])->assertCreated()->json('data.provider_reference'))->toBe('77');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/bot123:ABC/sendMessage') && $request['chat_id'] === '-100200');
});

test('a refused message is logged as failed', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
    $this->putJson('/api/v1/communication/channels/telegram', ['driver' => 'telegram', 'credentials' => ['bot_token' => '123:ABC']])->assertOk();

    sendOn('telegram', ['recipient' => '1'])->assertStatus(502);

    expect(DeliveryLog::first())->status->toBe('failed')->error->toBe('chat not found');
});

test('credentials are encrypted and never returned', function (): void {
    $this->putJson('/api/v1/communication/channels/sms', ['driver' => 'twilio', 'credentials' => ['account_sid' => 'AC1', 'auth_token' => 'very-secret', 'from' => '+1']])->assertOk();

    $listed = $this->getJson('/api/v1/communication/channels')->assertOk();

    expect($listed->getContent())->not->toContain('very-secret')
        ->and($listed->json('data.0.has_credentials'))->toBeTrue()
        ->and(DB::table('communication_channels')->value('credentials'))->not->toContain('very-secret');
});

test('a disabled channel or a missing recipient is refused', function (): void {
    $this->putJson('/api/v1/communication/channels/sms', ['driver' => 'local', 'is_enabled' => false])->assertOk();
    sendOn('sms')->assertUnprocessable();
    sendOn('telegram')->assertUnprocessable()->assertJsonValidationErrors('recipient');
    $this->putJson('/api/v1/communication/channels/sms', ['driver' => 'mail'])->assertUnprocessable();
});

test('documents, channels and logs of another tenant stay out of reach', function (): void {
    sendOn('sms')->assertCreated();

    actingAsMember(complianceTenant('Beta'));

    sendOn('sms')->assertNotFound();
    expect($this->getJson('/api/v1/communication/deliveries')->json('data'))->toBe([]);
});

test('sending and configuring follow the roles', function (): void {
    actingAsMember($this->tenant, 'member');
    sendOn('sms')->assertForbidden();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->getJson('/api/v1/communication/deliveries')->assertOk();
    sendOn('sms')->assertForbidden();

    actingAsMember($this->tenant, 'sales');
    sendOn('sms')->assertCreated();
    $this->putJson('/api/v1/communication/channels/sms', ['driver' => 'local'])->assertForbidden();
});
