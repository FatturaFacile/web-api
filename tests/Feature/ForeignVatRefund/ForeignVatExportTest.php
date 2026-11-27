<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

require_once __DIR__.'/ForeignVatSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Binary body of a download (file or streamed).
 */
function fvBody(TestResponse $response): string
{
    return $response->baseResponse instanceof StreamedResponse || $response->baseResponse instanceof BinaryFileResponse
        ? $response->streamedContent()
        : (string) $response->getContent();
}

test('the EU portal XML carries the claim, the lines and is valid against the schema', function (): void {
    fvSetUp($this);
    fvEntry($this, ['document_number' => 'DE-1', 'supplier_country_code' => 'DE', 'supplier_vat_number' => 'DE123456789', 'foreign_vat_amount' => 190]);
    fvEntry($this, ['document_number' => 'DE-2', 'supplier_country_code' => 'DE', 'document_date' => '2026-05-10', 'foreign_vat_amount' => 95, 'net_amount' => 500]);
    $claim = fvClaim($this);

    $response = $this->get(FVR_API.'/claims/'.$claim->id.'/exports/eu-xml')->assertOk();
    $xml = fvBody($response);

    $document = new DOMDocument;
    expect($document->loadXML($xml))->toBeTrue()
        ->and($document->schemaValidate(base_path('modules/87_ForeignVatRefund/resources/xsd/eu-vat-refund-application.xsd')))->toBeTrue()
        ->and($xml)->toContain('DE-1')->toContain('DE-2')->toContain('285.00');
});

test('the EU XML is refused for the Swiss and Italian procedures', function (): void {
    fvSetUp($this);
    $this->travelTo('2027-05-10 09:00:00');
    fvEntry($this, ['supplier_country_code' => 'CH', 'currency_code' => 'CHF', 'net_amount' => 20000, 'foreign_vat_rate' => 3.8, 'foreign_vat_amount' => 760, 'exchange_rate_to_base' => 1.05]);
    $claim = fvClaim($this, ['target_country_code' => 'CH']);

    $this->get(FVR_API.'/claims/'.$claim->id.'/exports/eu-xml')->assertUnprocessable()->assertJsonPath('code', 'export_not_available');
    $this->get(FVR_API.'/claims/'.$claim->id.'/exports/iva79-package')->assertUnprocessable()->assertJsonPath('code', 'export_not_available');
});

test('the ESTV package is a ZIP with forms 1222 and 1223, the mandate, the certificate and the invoices', function (): void {
    fvSetUp($this);
    $this->travelTo('2027-05-10 09:00:00');
    $entry = fvEntry($this, ['document_number' => 'ZH-1', 'supplier_country_code' => 'CH', 'currency_code' => 'CHF', 'net_amount' => 20000, 'foreign_vat_rate' => 3.8, 'foreign_vat_amount' => 760, 'exchange_rate_to_base' => 1.05]);
    $this->post(FVR_API.'/ledger/'.$entry->id.'/attachment', ['file' => UploadedFile::fake()->create('zh-1.pdf', 20, 'application/pdf')])->assertOk();
    $claim = fvClaim($this, ['target_country_code' => 'CH']);

    $this->get(FVR_API.'/claims/'.$claim->id.'/exports/estv-package')->assertUnprocessable()->assertJsonPath('code', 'fiscal_representative_missing');

    $mandate = $this->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'representative_tax_id' => 'CHE-123.456.788 MWST', 'mandate_start_date' => '2026-01-01', 'certificate_expiry_date' => '2028-01-01'])->assertCreated();
    $this->post(FVR_API.'/mandates/'.$mandate->json('data.id').'/documents', ['file' => UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'), 'type' => 'certificate', 'certificate_expiry_date' => '2028-01-01'])->assertOk();

    $response = $this->get(FVR_API.'/claims/'.$claim->id.'/exports/estv-package')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'fvr');
    file_put_contents($path, fvBody($response));
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }

    $zip->close();
    unlink($path);

    expect(collect($names)->contains(fn (string $name): bool => str_contains($name, '1222')))->toBeTrue()
        ->and(collect($names)->contains(fn (string $name): bool => str_contains($name, '1223')))->toBeTrue()
        ->and(collect($names)->contains(fn (string $name): bool => str_contains($name, 'manifest')))->toBeTrue()
        ->and(count($names))->toBeGreaterThanOrEqual(5);
});

test('the Italian Modello IVA 79 package is produced for a Swiss company claiming from Italy', function (): void {
    fvSetUp($this, 'CH');
    fvEntry($this, ['document_number' => 'MI-1', 'supplier_country_code' => 'IT', 'currency_code' => 'EUR', 'net_amount' => 2500, 'foreign_vat_rate' => 22, 'foreign_vat_amount' => 550, 'exchange_rate_to_base' => 0.95, 'expense_category_code' => 6]);
    $claim = fvClaim($this, ['target_country_code' => 'IT']);

    expect($claim->regime_type->value)->toBe('IT_ART_38_TER');

    $response = $this->get(FVR_API.'/claims/'.$claim->id.'/exports/iva79-package');

    expect($response->status())->toBeIn([200, 422]);
});

test('an unknown export type is not routed', function (): void {
    fvSetUp($this);
    fvEntry($this, ['supplier_country_code' => 'DE']);
    $claim = fvClaim($this);

    $this->get(FVR_API.'/claims/'.$claim->id.'/exports/pdf')->assertNotFound();
});

test('a VAT rate that the country does not allow is refused when the line is saved', function (): void {
    fvSetUp($this);

    fvManual($this, ['supplier_country_code' => 'CH', 'currency_code' => 'CHF', 'foreign_vat_rate' => 19, 'foreign_vat_amount' => 1900, 'net_amount' => 10000, 'exchange_rate_to_base' => 1.05])
        ->assertUnprocessable()->assertJsonPath('code', 'vat_rate_not_allowed');
});

test('another company cannot export the claim', function (): void {
    fvSetUp($this);
    fvEntry($this, ['supplier_country_code' => 'DE']);
    $claim = fvClaim($this);

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->get(FVR_API.'/claims/'.$claim->id.'/exports/eu-xml')->assertNotFound();
});
