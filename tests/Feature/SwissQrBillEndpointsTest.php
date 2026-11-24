<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Switzerland AG',
        'legal_name' => 'FatturaFacile Switzerland AG',
        'vat_number' => 'CHE-123.456.789',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'iban' => 'CH9300762011623852957',
        'qr_iban' => 'CH4431999123000889012',
        'settings' => ['preferences' => ['company' => ['street' => 'Bahnhofstrasse', 'house_number' => '1', 'postal_code' => '6900', 'city' => 'Lugano', 'country' => 'CH']]],
    ]);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Café Müller Zürich AG', 'country' => 'CH', 'email' => 'customer@example.com']);
    $contact->addresses()->create(['type' => 'billing', 'street' => 'Bahnhofstrasse', 'house_number' => '12', 'postal_code' => '8001', 'city' => 'Zürich', 'state_canton' => 'ZH', 'country' => 'CH']);

    $this->invoice = Invoice::create([
        'tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'invoice_type' => 'ch_qr_bill', 'invoice_number' => 'INV-2026-0001',
        'date' => '2026-03-01', 'due_date' => '2026-03-31', 'currency' => 'CHF', 'total_net' => 1803.75, 'total_tax' => 146.0, 'total_gross' => 1949.75, 'jurisdiction' => 'CH',
    ]);

    $this->api = $this->withHeader('X-Tenant-Id', $this->tenant->id);
});

/**
 * @return array{0: float, 1: float} the first page size in millimetres
 */
function pdfPageSizeMm(string $pdf): array
{
    preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $pdf, $m);

    return [round((float) $m[1] / 72 * 25.4, 1), round((float) $m[2] / 72 * 25.4, 1)];
}

test('generate-pdf renders an A4 page with the attached payment slip', function (): void {
    $response = $this->api->post('/api/v1/swiss-qr-bill/generate-pdf', ['invoice_id' => $this->invoice->id, 'mode' => 'A4_ATTACHED', 'language' => 'it'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-')
        ->and(pdfPageSizeMm($response->getContent()))->toBe([210.0, 297.0]);
});

test('generate-pdf renders the standalone 210x105 mm slip in every language and perforation style', function (string $language, string $perforation): void {
    $response = $this->api->post('/api/v1/swiss-qr-bill/generate-pdf', [
        'invoice_id' => $this->invoice->id, 'mode' => 'STANDALONE', 'language' => $language, 'perforation' => $perforation,
    ])->assertOk();

    expect(pdfPageSizeMm($response->getContent()))->toBe([210.0, 105.0]);
})->with([
    ['it', 'NONE'], ['de', 'DASHED_LINE'], ['fr', 'DASHED_LINE_WITH_SCISSORS'], ['en', 'DASHED_LINE_WITH_SCISSORS'],
]);

test('a generated slip decodes back to the invoice amount and a valid QR reference', function (): void {
    $pdf = $this->api->post('/api/v1/swiss-qr-bill/generate-pdf', ['invoice_id' => $this->invoice->id, 'mode' => 'STANDALONE'])->assertOk()->getContent();
    $file = UploadedFile::fake()->createWithContent('slip.pdf', $pdf);

    $this->api->post('/api/v1/swiss-qr-bill/parse', ['file' => $file])->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.reference_type', 'QRR')
        ->assertJsonPath('data.amount', 1949.75)
        ->assertJsonPath('data.draft.supplier_name', 'FatturaFacile Switzerland AG');
});

test('generate-pdf honours the tenant default reference type and perforation', function (): void {
    $this->tenant->update(['default_reference_type' => 'SCOR', 'qr_bill_perforation' => 'NONE']);

    $pdf = $this->api->post('/api/v1/swiss-qr-bill/generate-pdf', ['invoice_id' => $this->invoice->id, 'mode' => 'STANDALONE'])->assertOk()->getContent();

    $this->api->post('/api/v1/swiss-qr-bill/parse', ['file' => UploadedFile::fake()->createWithContent('slip.pdf', $pdf)])->assertOk()
        ->assertJsonPath('data.reference_type', 'SCOR')
        ->assertJsonPath('data.is_qr_iban', false);
});

test('generate-pdf rejects invalid options and invoices that cannot produce a QR-bill', function (): void {
    $this->api->postJson('/api/v1/swiss-qr-bill/generate-pdf', ['invoice_id' => $this->invoice->id, 'mode' => 'POSTER'])->assertUnprocessable();

    $this->tenant->update(['settings' => []]);
    $this->api->postJson('/api/v1/swiss-qr-bill/generate-pdf', ['invoice_id' => $this->invoice->id])->assertUnprocessable();
});

test('another tenant cannot render or even see the invoice', function (): void {
    $other = Tenant::create(['name' => 'Other AG', 'legal_name' => 'Other AG', 'country' => 'CH', 'default_currency' => 'CHF']);
    $foreign = Invoice::withoutGlobalScopes()->create([
        'tenant_id' => $other->id, 'contact_id' => Contact::create(['tenant_id' => $other->id, 'company_name' => 'Foreign Customer AG', 'country' => 'CH'])->id, 'invoice_type' => 'ch_qr_bill', 'invoice_number' => 'OTHER-1',
        'date' => '2026-03-01', 'currency' => 'CHF', 'total_gross' => 100, 'jurisdiction' => 'CH',
    ]);

    $this->api->postJson('/api/v1/swiss-qr-bill/generate-pdf', ['invoice_id' => $foreign->id])->assertUnprocessable();
});

test('parse reads a raw SPC payload and reports invalid ones', function (): void {
    $payload = implode("\r\n", [
        'SPC', '0200', '1', 'CH4431999123000889012', 'S', 'Robert Schneider AG', 'Rue du Lac', '1268/2/22', '2501', 'Biel', 'CH',
        '', '', '', '', '', '', '', '1949.75', 'CHF', '', '', '', '', '', '', '', 'QRR', '210000000003139471430009017', 'Invoice', 'EPD', '//S1/10/INV-77/11/260301',
    ]);

    $this->api->post('/api/v1/swiss-qr-bill/parse', ['payload' => $payload])->assertOk()
        ->assertJsonPath('data.creditor.name', 'Robert Schneider AG')
        ->assertJsonPath('data.draft.invoice_number', 'INV-77')
        ->assertJsonPath('data.draft.invoice_date', '2026-03-01');

    $this->api->postJson('/api/v1/swiss-qr-bill/parse', ['payload' => 'not a qr bill'])->assertUnprocessable();
    $this->api->postJson('/api/v1/swiss-qr-bill/parse', [])->assertUnprocessable();
});

test('validate-reference accepts matching pairs and explains mismatches', function (): void {
    $this->api->postJson('/api/v1/swiss-qr-bill/validate-reference', ['iban' => 'CH44 3199 9123 0008 8901 2', 'reference' => '21 00000 00003 13947 14300 09017'])->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.reference_type', 'QRR')
        ->assertJsonPath('data.is_qr_iban', true)
        ->assertJsonPath('data.formatted_reference', '21 00000 00003 13947 14300 09017');

    $this->api->postJson('/api/v1/swiss-qr-bill/validate-reference', ['iban' => 'CH9300762011623852957', 'reference' => 'RF18 5390 0754 7034'])->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.reference_type', 'SCOR');

    $this->api->postJson('/api/v1/swiss-qr-bill/validate-reference', ['iban' => 'CH9300762011623852957', 'reference' => 'RF19 5390 0754 7034'])->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.error_codes.0', 'invalid_reference');

    $this->api->postJson('/api/v1/swiss-qr-bill/validate-reference', [])->assertUnprocessable();
});

test('the tenant bank settings store the QR-bill preferences and reject a non QR-IBAN', function (): void {
    $this->api->putJson('/api/v1/settings/tenant', ['qrr_prefix' => '123456', 'default_reference_type' => 'SCOR', 'qr_bill_perforation' => 'DASHED_LINE'])->assertOk();

    expect($this->tenant->refresh())
        ->qrr_prefix->toBe('123456')
        ->default_reference_type->toBe('SCOR')
        ->qr_bill_perforation->toBe('DASHED_LINE');

    $this->api->putJson('/api/v1/settings/tenant', ['qr_iban' => 'CH9300762011623852957'])->assertUnprocessable()->assertJsonValidationErrors('qr_iban');
    $this->api->putJson('/api/v1/settings/tenant', ['qrr_prefix' => '12345678'])->assertUnprocessable()->assertJsonValidationErrors('qrr_prefix');
    $this->api->putJson('/api/v1/settings/tenant', ['qr_bill_perforation' => 'WAVY'])->assertUnprocessable()->assertJsonValidationErrors('qr_bill_perforation');
});
