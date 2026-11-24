<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Tax\LipeXsdValidator;

require_once __DIR__.'/ItalianVatTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ivDeferForeignKeys();
    $this->tenant = ivTenant();
    ivActAs($this->tenant);
});

/**
 * The three monthly settlements of a quarter, each posted on the Erario c/IVA account for $ledger.
 *
 * @param  array<int, string>  $ledger  amount posted per month index (0..2); 300.00 where missing
 */
function quarterSettlements(object $test, int $year, int $quarter, array $ledger = []): void
{
    foreach ([0, 1, 2] as $index) {
        $month = ($quarter - 1) * 3 + 1 + $index;
        $from = sprintf('%d-%02d-01', $year, $month);
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();

        ivSettlement($test->tenant, $from, $to, [], $ledger[$index] ?? '300.00');
    }
}

/**
 * @return array{0: resource|OpenSSLAsymmetricKey, 1: string}
 */
function signingMaterial(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);

    if ($key === false) {
        test()->markTestSkipped('OpenSSL cannot create keys on this machine.');
    }

    $csr = openssl_csr_new(['commonName' => 'LIPE signer'], $key);
    $certificate = openssl_csr_sign($csr, null, $key, 2);
    openssl_x509_export($certificate, $pem);

    return [$key, $pem];
}

it('generates the LIPE of a quarter with one module per month, valid and reconciled with the ledger', function (): void {
    quarterSettlements($this, 2025, 1);

    $response = $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->assertCreated();

    $response->assertJsonPath('data.status', 'validated')
        ->assertJsonPath('data.reconciliation_status', 'balanced')
        ->assertJsonPath('data.signature_status', 'unsigned')
        ->assertJsonPath('data.file_name', 'IT00743110157_LI_00001.xml')
        ->assertJsonPath('data.totals.output_vat', '3000.00')
        ->assertJsonPath('data.totals.input_vat', '2100.00')
        ->assertJsonPath('data.totals.difference', '900.00')
        ->assertJsonPath('data.totals.balance', '900.00');

    expect($response->json('data.xsd_errors'))->toBe([])
        ->and($response->json('data.settlement_ids'))->toHaveCount(3)
        ->and($response->json('data.reconciliation.total_difference'))->toBe('0.00');

    $xml = $this->get('/api/v1/italian-vat/lipe/'.$response->json('data.id').'/xml')->assertOk()->getContent();

    expect(substr_count($xml, '<iv:Modulo>'))->toBe(3)
        ->and(LipeXsdValidator::errors($xml))->toBe([]);
});

it('accepts the single quarterly settlement of a quarterly taxpayer', function (): void {
    ivSettlement($this->tenant, '2025-01-01', '2025-03-31', [], '300.00');

    $response = $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->assertCreated();

    expect($response->json('data.settlement_ids'))->toHaveCount(1)
        ->and($response->json('data.reconciliation_status'))->toBe('balanced')
        ->and($this->get('/api/v1/italian-vat/lipe/'.$response->json('data.id').'/xml')->getContent())->toContain('<iv:Trimestre>1</iv:Trimestre>');
});

it('refuses a quarter whose settlements are not complete, or not posted', function (): void {
    ivSettlement($this->tenant, '2025-04-01', '2025-04-30', [], '300.00');
    ivSettlement($this->tenant, '2025-05-01', '2025-05-31', [], '300.00');

    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('invalid_params.0.code', 'lipe_incomplete_periods')
        ->assertJsonPath('invalid_params.0.params.missing', '6');

    ivSettlement($this->tenant, '2025-06-01', '2025-06-30', ['status' => VatSettlement::STATUS_CALCULATED]);

    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 2])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'lipe_incomplete_periods');

    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 5])->assertUnprocessable();
});

it('flags a settlement that differs from the Erario c/IVA account', function (): void {
    quarterSettlements($this, 2025, 2, [1 => '250.00']);

    $response = $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 2])->assertCreated();

    $response->assertJsonPath('data.reconciliation_status', 'unbalanced')
        ->assertJsonPath('data.reconciliation.rows.1.status', 'difference')
        ->assertJsonPath('data.reconciliation.rows.1.difference', '50.00')
        ->assertJsonPath('data.reconciliation.total_difference', '50.00');
});

it('flags a settlement with no entry on the account', function (): void {
    ivSettlement($this->tenant, '2025-01-01', '2025-03-31', [], null);

    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->assertCreated()
        ->assertJsonPath('data.reconciliation_status', 'unbalanced')
        ->assertJsonPath('data.reconciliation.rows.0.status', 'missing_entry');
});

it('accepts a debt below the minimum carried forward without a payment entry', function (): void {
    ivSettlement($this->tenant, '2025-01-01', '2025-03-31', ['below_minimum_payment' => true, 'payable_amount' => '20.00'], '0.00');

    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->assertCreated()
        ->assertJsonPath('data.reconciliation_status', 'balanced')
        ->assertJsonPath('data.reconciliation.rows.0.status', 'carried_forward');
});

it('follows the file from signature to transmission', function (): void {
    [$key, $certificate] = signingMaterial();
    quarterSettlements($this, 2025, 1);
    $id = $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->json('data.id');
    $xml = $this->get("/api/v1/italian-vat/lipe/{$id}/xml")->getContent();

    $this->postJson("/api/v1/italian-vat/lipe/{$id}/ready")
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.params.problems', 'signature');

    $this->postJson("/api/v1/italian-vat/lipe/{$id}/signature", ['signature' => base64_encode('forged'), 'certificate' => $certificate])
        ->assertUnprocessable()->assertJsonPath('invalid_params.0.code', 'lipe_signature_invalid');

    openssl_sign($xml, $signature, $key, OPENSSL_ALGO_SHA256);

    $this->postJson("/api/v1/italian-vat/lipe/{$id}/signature", ['signature' => base64_encode($signature), 'certificate' => $certificate])
        ->assertOk()
        ->assertJsonPath('data.status', 'signed')
        ->assertJsonPath('data.signature_status', 'signed')
        ->assertJsonPath('data.signature_digest', hash('sha256', $xml));

    $this->postJson("/api/v1/italian-vat/lipe/{$id}/ready")->assertOk()->assertJsonPath('data.status', 'ready');
    $this->postJson("/api/v1/italian-vat/lipe/{$id}/transmission", ['protocol' => 'not-a-protocol'])->assertUnprocessable();
    $this->postJson("/api/v1/italian-vat/lipe/{$id}/transmission", ['protocol' => '12345678901234567-000001'])
        ->assertOk()->assertJsonPath('data.status', 'transmitted')->assertJsonPath('data.transmission_protocol', '12345678901234567-000001');

    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])
        ->assertStatus(409)->assertJsonPath('invalid_params.0.code', 'lipe_already_transmitted');

    $this->postJson("/api/v1/italian-vat/lipe/{$id}/rejection", ['reason' => 'Codice carica errato'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('drops the signature when the file is generated again, and blocks the transmission when the ledger does not balance', function (): void {
    [$key, $certificate] = signingMaterial();
    quarterSettlements($this, 2025, 1);
    $id = $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->json('data.id');
    $xml = $this->get("/api/v1/italian-vat/lipe/{$id}/xml")->getContent();
    openssl_sign($xml, $signature, $key, OPENSSL_ALGO_SHA256);
    $this->postJson("/api/v1/italian-vat/lipe/{$id}/signature", ['signature' => base64_encode($signature), 'certificate' => $certificate])->assertOk();

    $again = $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->assertCreated();

    expect($again->json('data.id'))->toBe($id)
        ->and($again->json('data.signature_status'))->toBe('unsigned')
        ->and($again->json('data.status'))->toBe('validated');

    // A signature made on another file is refused: the file is what is signed.
    openssl_sign('<other/>', $other, $key, OPENSSL_ALGO_SHA256);
    $this->postJson("/api/v1/italian-vat/lipe/{$id}/signature", ['signature' => base64_encode($other), 'certificate' => $certificate])->assertUnprocessable();

    openssl_sign($xml, $signature, $key, OPENSSL_ALGO_SHA256);
    $this->postJson("/api/v1/italian-vat/lipe/{$id}/signature", ['signature' => base64_encode($signature), 'certificate' => $certificate])->assertOk();

    // A posted settlement is immutable through the model: the difference is introduced behind it, like a ledger that moved.
    DB::table('vat_settlements')->where('tenant_id', $this->tenant->id)->where('period_from', '2025-01-01')->update(['payable_amount' => '999.00']);

    $this->postJson("/api/v1/italian-vat/lipe/{$id}/reconcile")->assertOk()->assertJsonPath('data.reconciliation_status', 'unbalanced');
    $this->postJson("/api/v1/italian-vat/lipe/{$id}/ready")->assertUnprocessable()->assertJsonPath('invalid_params.0.params.problems', 'reconciliation');
});

it('lists the communications of a year and keeps them apart from other tenants', function (): void {
    quarterSettlements($this, 2025, 1);
    $this->postJson('/api/v1/italian-vat/lipe', ['year' => 2025, 'quarter' => 1])->assertCreated();
    $id = $this->getJson('/api/v1/italian-vat/lipe?year=2025')->assertOk()->json('data.0.id');

    $other = ivTenant('Other');
    ivActAs($other);

    expect($this->getJson('/api/v1/italian-vat/lipe?year=2025')->assertOk()->json('data'))->toBe([]);
    $this->getJson("/api/v1/italian-vat/lipe/{$id}")->assertNotFound();
    $this->get("/api/v1/italian-vat/lipe/{$id}/xml")->assertNotFound();
});
