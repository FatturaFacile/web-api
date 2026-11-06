<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

function archiveInvoice(Tenant $tenant, string $number, string $date, bool $withXml = true): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => '=Cliente', 'vat_number' => '12345678901']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'invoice', 'invoice_number' => $number, 'date' => $date, 'due_date' => $date, 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();

    if ($withXml) {
        InvoiceSdiMetadata::create(['invoice_id' => $invoice->id, 'transmission_format' => 'FPR12', 'file_name' => "../IT123_{$number}.xml", 'xml_content' => "<xml>{$number}</xml>"]);
    }

    return $invoice;
}

function archivePassive(Tenant $tenant, string $number, string $date): PassiveInvoice
{
    $supplier = Contact::create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'company_name' => 'Fornitore', 'vat_number' => '01234567890']);

    return PassiveInvoice::withoutEvents(fn () => tap(new PassiveInvoice(['supplier_id' => $supplier->id, 'invoice_number' => $number, 'date' => $date, 'due_date' => $date, 'total_amount' => 61, 'total_net' => 50, 'total_tax' => 11, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid', 'document_hash' => Str::random(40), 'sdi_file_name' => "{$number}.xml", 'xml_payload' => "<xml>{$number}</xml>"]), static function (PassiveInvoice $invoice) use ($tenant): void {
        $invoice->tenant_id = $tenant->id;
        $invoice->save();
    }));
}

function archiveContents(mixed $response): array
{
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $contents = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $contents[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
    }

    return $contents;
}

beforeEach(function (): void {
    $this->tenant = complianceTenant();
    archiveInvoice($this->tenant, 'FT-1', '2027-02-10');
    archiveInvoice($this->tenant, 'FT-2', '2027-02-11', withXml: false);
    archiveInvoice($this->tenant, 'FT-OLD', '2026-02-10');
    archivePassive($this->tenant, 'P-1', '2027-02-12');
});

test('the archive holds the XML of the period with a CSV index', function (): void {
    actingAsMember($this->tenant, 'accountant_readonly');

    $response = $this->get('/api/v1/exports/accountant-archive?from=2027-01-01&to=2027-12-31', ['Accept' => 'application/json'])->assertOk();
    $files = archiveContents($response);

    expect(array_keys($files))->toEqualCanonicalizing(['issued/IT123_FT-1.xml', 'received/P-1.xml', 'index.csv'])
        ->and($files['issued/IT123_FT-1.xml'])->toBe('<xml>FT-1</xml>')
        ->and($files['index.csv'])->toContain('"FT-2"')->toContain('xml_missing')->toContain('"received"')->not->toContain('FT-OLD');
});

test('counterparty names cannot become spreadsheet formulas in the index', function (): void {
    actingAsMember($this->tenant);

    $files = archiveContents($this->get('/api/v1/exports/accountant-archive?from=2027-01-01&to=2027-12-31', ['Accept' => 'application/json']));

    expect($files['index.csv'])->toContain("\"'=Cliente\"");
});

test('the archive never contains documents of another tenant', function (): void {
    actingAsMember(complianceTenant('Beta'));

    $files = archiveContents($this->get('/api/v1/exports/accountant-archive?from=2027-01-01&to=2027-12-31', ['Accept' => 'application/json'])->assertOk());

    expect(array_keys($files))->toBe(['index.csv']);
});

test('invalid periods and other roles are refused', function (): void {
    actingAsMember($this->tenant);
    $this->getJson('/api/v1/exports/accountant-archive?from=2027-02-01&to=2027-01-01')->assertUnprocessable();
    $this->getJson('/api/v1/exports/accountant-archive?from=2025-01-01&to=2027-01-01')->assertStatus(422);

    actingAsMember($this->tenant, 'member');
    $this->getJson('/api/v1/exports/accountant-archive?from=2027-01-01&to=2027-12-31')->assertForbidden();
});
