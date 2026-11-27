<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Pdf\RenderedPdf;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\SwissEbill\Application\Commands\SaveEbillSettingsCommand;
use Modules\SwissEbill\Application\Commands\SaveEbillSettingsHandler;
use Modules\SwissEbill\Application\Services\EbillDeliveryService;
use Modules\SwissEbill\Domain\Support\BillerIdValidator;
use Modules\SwissEbill\Domain\Support\SwissUid;
use Modules\SwissEbill\Infrastructure\Gateways\SandboxEbillGateway;
use Modules\SwissEbill\Models\EbillSetting;
use Modules\SwissEbill\Models\EbillTransmission;

const EBILL_QR_IBAN = 'CH4431999123000889012';
const EBILL_IBAN = 'CH9300762011623852957';
const EBILL_QRR = '210000000003139471430009017';

/**
 * A valid swiss UID in the display form (CHE-xxx.xxx.xxx); $seed picks another one.
 */
function ebUid(int $seed = 10000001): string
{
    for ($n = $seed; $n < $seed + 500; $n++) {
        $body = SwissUid::complete((string) $n);

        if ($body !== null) {
            return 'CHE-'.substr($body, 0, 3).'.'.substr($body, 3, 3).'.'.substr($body, 6, 3);
        }
    }

    throw new RuntimeException('No valid UID found.');
}

function ebBillerId(): string
{
    $body = '41'.'10560425610173';

    return $body.BillerIdValidator::checkDigit($body);
}

/**
 * Two companies, an accountant signed in on the first one (an owner of a swiss company with UID, QR-IBAN and address), the sandbox
 * simulator enabled with a valid biller id, and a PDF renderer that returns a fixed document instead of drawing one.
 */
function ebSetUp(object $test, string $role = 'accountant', bool $enable = true): void
{
    $test->travelTo('2026-03-05 10:00:00');
    $suffix = Str::upper(Str::random(5));
    $test->tenant = Tenant::create([
        'name' => 'Muster '.$suffix, 'legal_name' => 'Muster '.$suffix.' AG', 'country' => 'CH', 'default_currency' => 'CHF',
        'iban' => EBILL_IBAN, 'qr_iban' => EBILL_QR_IBAN, 'uid_number' => ebUid(),
        'settings' => ['preferences' => ['company' => ['street' => 'Hauptstrasse', 'house_number' => '5', 'postal_code' => '8000', 'city' => 'Zürich', 'country' => 'CH']]],
    ]);
    $test->otherTenant = Tenant::create(['name' => 'Other '.$suffix, 'legal_name' => 'Other '.$suffix.' AG', 'country' => 'CH', 'default_currency' => 'CHF']);

    $test->actor = ebUser($test->tenant, $role);
    Sanctum::actingAs($test->actor);
    $test->withHeader('X-Tenant-Id', $test->tenant->id);
    app(TenantContext::class)->setTenant($test->tenant);

    $test->mock(DocumentPdfService::class, fn ($mock) => $mock->shouldReceive('render')->andReturn(new RenderedPdf('%PDF-1.4 courtesy', 'invoice.pdf')));

    if ($enable) {
        $test->setting = ebSettings();
    }
}

function ebUser(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

/**
 * @param  array<string, mixed>  $values
 */
function ebSettings(array $values = []): EbillSetting
{
    return app(SaveEbillSettingsHandler::class)->handle(new SaveEbillSettingsCommand($values + [
        'ebill_enabled' => true, 'biller_id' => ebBillerId(), 'provider' => 'sandbox_simulator', 'channel_protocol' => 'rest_json_xml',
    ]));
}

/**
 * An enrolled B2B customer with a UID and a billing address (the recipient id ends in $lastDigit: 9 rejects, 8 waits in the sandbox).
 *
 * @param  array<string, mixed>  $overrides
 */
function ebCustomer(array $overrides = [], string $recipientSuffix = '1'): Contact
{
    $customer = Contact::query()->create(array_merge([
        'company_name' => 'Kunde '.Str::upper(Str::random(4)).' AG', 'type' => 'customer', 'country' => 'CH',
        'email' => Str::uuid().'@customer.example.com', 'uid_number' => ebUid(10000100),
        'ebill_recipient_id' => '4100000000000000'.$recipientSuffix, 'ebill_status' => 'enrolled', 'ebill_channel_type' => 'b2b_enterprise',
    ], $overrides));

    ContactAddress::query()->create(['contact_id' => $customer->id, 'type' => 'billing', 'street' => 'Bahnhofstrasse', 'house_number' => '1', 'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH', 'is_default' => true]);

    return $customer;
}

/**
 * An issued (posted, unless 'status' says otherwise) CHF invoice with one line at 8.1% VAT and a QR reference.
 *
 * @param  array<string, mixed>  $overrides
 */
function ebInvoice(Contact $customer, array $overrides = [], float $net = 100.0): Invoice
{
    static $sequence = 0;
    $sequence++;
    $tax = round($net * 0.081, 2);
    $status = (string) ($overrides['status'] ?? 'posted');
    unset($overrides['status']);

    $invoice = Invoice::query()->create(array_merge([
        'contact_id' => $customer->id, 'invoice_number' => sprintf('INV-2026-%04d', $sequence), 'date' => '2026-03-01', 'due_date' => '2026-03-31',
        'currency' => 'CHF', 'jurisdiction' => 'CH', 'invoice_type' => 'ch_qr_bill', 'total_net' => $net, 'total_tax' => $tax,
        'total_gross' => $net + $tax, 'payment_status' => 'unpaid', 'qr_reference' => EBILL_QRR,
    ], $overrides));

    InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => $net, 'tax_rate' => 8.1, 'discount_percentage' => 0, 'line_total' => $net]);
    $invoice->forceFill(['status' => $status])->save();

    return $invoice->refresh();
}

/**
 * Sends an invoice through the API and delivers it to the sandbox (the delivery is idempotent: a queue that ran it already is fine).
 */
function ebSend(object $test, Invoice $invoice): EbillTransmission
{
    $id = $test->postJson('/api/v1/swiss-ebill/invoices/'.$invoice->id.'/send')->assertStatus(202)->json('data.id');
    app(EbillDeliveryService::class)->deliver($id);

    return EbillTransmission::query()->findOrFail($id);
}

/**
 * A webhook call signed like the gateway does (HMAC-SHA256 over "timestamp.body").
 *
 * @param  array<string, mixed>  $payload
 */
function ebWebhook(object $test, EbillSetting $setting, array $payload, ?string $signature = null): TestResponse
{
    $body = json_encode($payload);
    $timestamp = (string) time();

    return $test->call('POST', '/api/v1/webhooks/swiss-ebill/'.$setting->webhook_slug, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_EBILL_TIMESTAMP' => $timestamp,
        'HTTP_X_EBILL_SIGNATURE' => 'sha256='.($signature ?? hash_hmac('sha256', $timestamp.'.'.$body, (string) $setting->webhook_secret)),
    ], (string) $body);
}

/**
 * The next minutes in the sandbox timeline (delivered after 1, approved or rejected after 3).
 */
function ebAdvance(object $test, int $minutes): void
{
    $test->travelTo(now()->addMinutes($minutes));
}

/**
 * The participant id the sandbox hands out, to build a transaction the way a gateway would.
 */
function ebParticipant(string $seed): string
{
    return SandboxEbillGateway::participantIdFor($seed);
}
