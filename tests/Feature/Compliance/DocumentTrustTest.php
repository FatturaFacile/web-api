<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

/**
 * @return array{certificate: string, private_key: string}
 */
function selfSignedPair(int $days = 30): array
{
    $config = tempnam(sys_get_temp_dir(), 'cnf');
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
    $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];

    $key = openssl_pkey_new($options);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Firma Test'], $key, $options), null, $key, $days, $options);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem, null, $options);
    unlink($config);

    return ['certificate' => $certificatePem, 'private_key' => $keyPem];
}

function trustInvoice(Tenant $tenant, ?string $xml = '<xml>fattura</xml>', string $number = 'FT-1'): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => '2027-01-15', 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    if ($xml !== null) {
        InvoiceSdiMetadata::create(['invoice_id' => $invoice->id, 'transmission_format' => 'FPR12', 'xml_content' => $xml]);
    }

    return $invoice;
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    actingAsMember($this->tenant);
    $this->invoice = trustInvoice($this->tenant);
    $this->reference = ['document_type' => 'invoice', 'document_id' => $this->invoice->id];
});

test('the local driver records a fingerprint and calls it that', function (): void {
    $signature = $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertCreated()->json('data');

    expect($signature)->toMatchArray(['driver' => 'local', 'format' => 'sha256-integrity', 'content_sha256' => hash('sha256', '<xml>fattura</xml>')]);
});

test('with a certificate the document gets a CMS with the CAdES attribute that OpenSSL verifies', function (): void {
    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades', ...selfSignedPair()])->assertOk();

    $signature = $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertCreated()->json('data');
    $der = base64_decode($signature['signature']);

    expect($signature['format'])->toBe('cades-bes-p7m')
        ->and(str_contains($der, hex2bin('2a864886f70d010910022f')))->toBeTrue();

    $signed = tempnam(sys_get_temp_dir(), 'p7m');
    $content = tempnam(sys_get_temp_dir(), 'out');
    file_put_contents($signed, $der);

    expect(openssl_cms_verify($signed, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $content, null, null, OPENSSL_ENCODING_DER))->toBeTrue()
        ->and(file_get_contents($content))->toBe('<xml>fattura</xml>');

    unlink($signed);
    unlink($content);
});

test('a stored fingerprint verifies until the XML of the document changes', function (): void {
    $signature = $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertCreated()->json('data');

    $ok = $this->postJson("/api/v1/document-trust/signatures/{$signature['id']}/verify")->assertOk()->json('data');
    expect($ok)->toMatchArray(['valid' => true, 'reason' => null, 'content_changed' => false, 'trust_chain_validated' => false, 'driver' => 'local']);

    InvoiceSdiMetadata::where('invoice_id', $this->invoice->id)->update(['xml_content' => '<xml>fattura alterata</xml>']);

    expect($this->postJson("/api/v1/document-trust/signatures/{$signature['id']}/verify")->json('data'))->toMatchArray(['valid' => false, 'reason' => 'content_changed', 'content_changed' => true]);
});

test('a CAdES signature verifies, and fails once the file is changed or the signature is altered', function (): void {
    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades', ...selfSignedPair()])->assertOk();
    $signature = $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertCreated()->json('data');
    $url = "/api/v1/document-trust/signatures/{$signature['id']}/verify";

    expect($this->postJson($url)->json('data'))->toMatchArray(['valid' => true, 'format' => 'cades-bes-p7m', 'trust_chain_validated' => false]);

    DB::table('document_signatures')->where('id', $signature['id'])->update(['signature' => base64_encode(str_repeat("\x01", 64))]);
    expect($this->postJson($url)->json('data'))->toMatchArray(['valid' => false, 'reason' => 'signature_invalid']);

    InvoiceSdiMetadata::where('invoice_id', $this->invoice->id)->update(['xml_content' => '<xml>altro</xml>']);
    expect($this->postJson($url)->json('data.content_changed'))->toBeTrue();
});

test('verification of a signature of another tenant is a 404 and the route is closed to read-only roles', function (): void {
    $signature = $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertCreated()->json('data');

    actingAsMember(complianceTenant('Beta'));
    $this->postJson("/api/v1/document-trust/signatures/{$signature['id']}/verify")->assertNotFound();
    $this->postJson('/api/v1/document-trust/signatures/'.Str::uuid().'/verify')->assertNotFound();

    actingAsMember($this->tenant, 'accountant_readonly');
    $this->postJson("/api/v1/document-trust/signatures/{$signature['id']}/verify")->assertForbidden();
});

test('keys are encrypted, hidden, and must match a valid certificate', function (): void {
    $pair = selfSignedPair();

    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades', 'certificate' => $pair['certificate'], 'private_key' => selfSignedPair()['private_key']])->assertUnprocessable();
    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades', 'certificate' => 'not a certificate', 'private_key' => 'x'])->assertUnprocessable();
    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades'])->assertUnprocessable();

    $response = $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades', ...$pair])->assertOk();

    expect($response->getContent())->not->toContain('PRIVATE KEY')
        ->and(DB::table('document_signing_settings')->value('private_key'))->not->toContain('PRIVATE KEY');
});

test('the settings can be read without any secret and go back to local', function (): void {
    expect($this->getJson('/api/v1/document-trust/settings')->assertOk()->json('data.driver'))->toBe('local');

    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades', ...selfSignedPair()])->assertOk();
    $shown = $this->getJson('/api/v1/document-trust/settings')->assertOk();
    expect($shown->json('data.driver'))->toBe('cades')->and($shown->json('data.certificate_expires_at'))->not->toBeNull()
        ->and($shown->getContent())->not->toContain('PRIVATE KEY')->not->toContain('BEGIN CERTIFICATE');

    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'local'])->assertOk()->assertJsonPath('data.certificate_expires_at', null);

    actingAsMember($this->tenant, 'accountant');
    $this->getJson('/api/v1/document-trust/settings')->assertForbidden();
});

test('a certificate that is not valid today is refused', function (): void {
    $pair = selfSignedPair(1);
    $this->travel(5)->days();

    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'cades', ...$pair])->assertUnprocessable();
});

test('preservation is a fingerprint locally, and the accredited driver refuses to run', function (): void {
    expect($this->postJson('/api/v1/document-trust/preservation', $this->reference)->assertCreated()->json('data.status'))->toBe('fingerprint_only');

    Config::set('services.preservation.driver', 'accredited');

    $this->postJson('/api/v1/document-trust/preservation', $this->reference)->assertUnprocessable()->assertJsonValidationErrors('document');
});

test('a document without XML cannot be signed', function (): void {
    $this->postJson('/api/v1/document-trust/signatures', ['document_type' => 'invoice', 'document_id' => trustInvoice($this->tenant, null, 'FT-2')->id])->assertUnprocessable();
});

test('tenants and roles are enforced', function (): void {
    $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertCreated();

    actingAsMember(complianceTenant('Beta'));
    $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertNotFound();
    expect($this->getJson("/api/v1/document-trust/signatures?document_id={$this->invoice->id}")->json('data'))->toBe([]);

    actingAsMember($this->tenant, 'member');
    $this->postJson('/api/v1/document-trust/signatures', $this->reference)->assertForbidden();

    actingAsMember($this->tenant, 'accountant');
    $this->putJson('/api/v1/document-trust/settings', ['driver' => 'local'])->assertForbidden();
});
