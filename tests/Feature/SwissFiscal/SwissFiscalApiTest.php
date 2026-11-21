<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function swissFiscalActAs(): void
{
    $tenant = Tenant::create([
        'name' => 'Swiss '.Str::random(5), 'legal_name' => 'Swiss SA', 'country' => 'CH', 'default_currency' => 'CHF',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
    ]);
    $user = User::create(['name' => 'owner', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner', 'permissions' => json_encode([])]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

/** @return array<string, mixed> */
function qrBillPayload(array $override = []): array
{
    return array_replace([
        'iban' => 'CH44 3199 9123 0008 8901 2',
        'currency' => 'CHF',
        'amount' => '1949.75',
        'reference_type' => 'QRR',
        'reference' => '210000000003139471430009017',
        'creditor' => ['name' => 'Robert Schneider AG', 'street' => 'Rue du Lac', 'building_number' => '1268', 'postal_code' => '2501', 'town' => 'Biel', 'country' => 'CH'],
        'format' => 'json',
    ], $override);
}

it('requires authentication', function (): void {
    $this->getJson('/api/v1/swiss-fiscal/vat-rates')->assertUnauthorized();
});

it('lists the Swiss VAT rates', function (): void {
    swissFiscalActAs();

    $this->getJson('/api/v1/swiss-fiscal/vat-rates')->assertOk()->assertJsonPath('data.0.percent', '8.1')->assertJsonPath('data.1.percent', '2.6')->assertJsonPath('data.2.percent', '3.8');
});

it('returns the QR-bill payload as json and the slip as pdf', function (): void {
    swissFiscalActAs();

    $this->postJson('/api/v1/swiss-fiscal/qr-bills', qrBillPayload())->assertOk()
        ->assertJsonPath('data.reference_type', 'QRR')
        ->assertJsonStructure(['data' => ['payload', 'qr_svg']]);

    $pdf = $this->post('/api/v1/swiss-fiscal/qr-bills', qrBillPayload(['format' => 'pdf', 'language' => 'de']), ['Accept' => 'application/pdf']);
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects a QR reference with a QR-IBAN mismatch as a rule violation', function (): void {
    swissFiscalActAs();

    $this->postJson('/api/v1/swiss-fiscal/qr-bills', qrBillPayload(['iban' => 'CH93 0076 2011 6238 5295 7']))
        ->assertUnprocessable()->assertJsonPath('title', 'Fiscal Document Rule Violation');
});

it('validates the request fields', function (): void {
    swissFiscalActAs();

    $this->postJson('/api/v1/swiss-fiscal/qr-bills', qrBillPayload(['currency' => 'USD', 'amount' => '12.345']))
        ->assertUnprocessable()->assertJsonValidationErrors(['currency', 'amount']);
});

it('parses a camt.054 notification', function (): void {
    swissFiscalActAs();
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.04"><BkToCstmrDbtCdtNtfctn><Ntfctn>
<Ntry><Amt Ccy="CHF">1949.75</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-06-16</Dt></BookgDt><ValDt><Dt>2026-06-17</Dt></ValDt>
<NtryDtls><TxDtls><RltdPties><Dbtr><Nm>Pia-Maria Rutschmann</Nm></Dbtr></RltdPties>
<RmtInf><Strd><CdtrRefInf><Ref>210000000003139471430009017</Ref></CdtrRefInf></Strd></RmtInf></TxDtls></NtryDtls></Ntry>
</Ntfctn></BkToCstmrDbtCdtNtfctn></Document>
XML;

    $this->postJson('/api/v1/swiss-fiscal/camt054', ['xml' => $xml])->assertOk()
        ->assertJsonPath('data.0.reference', '210000000003139471430009017')
        ->assertJsonPath('data.0.reference_valid', true)
        ->assertJsonPath('data.0.amount', '1949.75')
        ->assertJsonPath('data.0.credit', true)
        ->assertJsonPath('data.0.debtor', 'Pia-Maria Rutschmann');

    $this->postJson('/api/v1/swiss-fiscal/camt054', ['xml' => '<foo/>'])->assertUnprocessable();
});

it('reports an unknown invoice through the Italian strategy', function (): void {
    swissFiscalActAs();

    $this->postJson('/api/v1/swiss-fiscal/fiscal-documents', ['country' => 'IT', 'invoice_id' => (string) Str::uuid()])->assertUnprocessable();
});
