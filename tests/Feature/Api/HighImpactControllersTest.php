<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Enterprise HQ',
        'legal_name' => 'Enterprise HQ S.p.A.',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create([
        'name' => 'Enterprise Admin',
        'email' => 'admin@enterprise.eu',
        'password' => bcrypt('password123'),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    Sanctum::actingAs($this->user);
});

function apiRequest()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

// ------------------------------------------------------------------------------------------------
// PurchasesController Endpoints
// ------------------------------------------------------------------------------------------------

test('purchases controller retrieves paginated lists of requests, orders, and passive invoices', function (): void {
    PurchaseRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'request_number' => 'REQ-001',
        'requested_date' => now()->toDateString(),
        'status' => 'pending',
    ]);

    $supplier = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Supplier Co',
        'type' => 'supplier',
        'country' => 'IT',
    ]);

    PurchaseOrder::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'order_number' => 'PO-001',
        'date' => now()->toDateString(),
        'status' => 'draft',
        'total_net' => 500,
        'total_gross' => 610,
    ]);

    PassiveInvoice::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PI-001',
        'date' => now()->toDateString(),
        'total_amount' => 610,
        'currency' => 'EUR',
    ]);

    apiRequest()->getJson('/api/v1/purchases/requests')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    apiRequest()->getJson('/api/v1/purchases/orders')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    apiRequest()->getJson('/api/v1/purchases/passive-invoices')
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

test('purchases controller imports fatturapa xml with validation and edge cases', function (): void {
    // 1. Invalid XML
    $invalidFile = UploadedFile::fake()->createWithContent('invalid.xml', 'Not an XML payload');
    apiRequest()->postJson('/api/v1/purchases/passive-invoices/import-sdi', [
        'xml_file' => $invalidFile,
    ])->assertStatus(422);

    // 2. Valid FatturaPA XML
    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
  <FatturaElettronicaHeader>
    <CedentePrestatore>
      <DatiAnagrafici>
        <IdFiscaleIVA>
          <IdPaese>IT</IdPaese>
          <IdCodice>09876543210</IdCodice>
        </IdFiscaleIVA>
        <Anagrafica>
          <Denominazione>Acme Tech SRL</Denominazione>
        </Anagrafica>
      </DatiAnagrafici>
    </CedentePrestatore>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <Numero>FT-999</Numero>
        <Data>2026-10-31</Data>
        <Divisa>EUR</Divisa>
        <ImportoTotaleDocumento>305.00</ImportoTotaleDocumento>
      </DatiGeneraliDocumento>
    </DatiGenerali>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;

    $validFile = UploadedFile::fake()->createWithContent('fattura.xml', $xmlContent);
    $response = apiRequest()->postJson('/api/v1/purchases/passive-invoices/import-sdi', [
        'xml_file' => $validFile,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.invoice_number', 'FT-999')
        ->assertJsonPath('data.total_amount', fn ($val) => (float) $val === 305.0)
        ->assertJsonPath('data.supplier', 'Acme Tech SRL');
});

test('purchases controller scans swiss qr bill payload and exports pain001', function (): void {
    // 1. Invalid QR payload
    apiRequest()->postJson('/api/v1/purchases/passive-invoices/scan-qr-bill', [
        'qr_payload' => 'INVALID-HEADER',
    ])->assertStatus(422);

    // 2. Valid Swiss SPC QR payload using \n separation
    $qrLines = array_fill(0, 32, '');
    $qrLines[0] = 'SPC';
    $qrLines[1] = '0200';
    $qrLines[2] = '1';
    $qrLines[3] = 'CH4431999123000889012';
    $qrLines[5] = 'Swiss Telecom AG';
    $qrLines[18] = '149.90';
    $qrLines[19] = 'CHF';
    $qrLines[27] = '210000000003139471430009017';

    $payload = implode("\n", $qrLines);

    $qrResponse = apiRequest()->postJson('/api/v1/purchases/passive-invoices/scan-qr-bill', [
        'qr_payload' => $payload,
    ]);

    $qrResponse->assertCreated()
        ->assertJsonPath('data.creditor', 'Swiss Telecom AG')
        ->assertJsonPath('data.amount', fn ($val) => (float) $val === 149.90)
        ->assertJsonPath('data.currency', 'CHF');

    // 3. Export pain.001 XML
    $painResponse = apiRequest()->postJson('/api/v1/purchases/export-pain001', [
        'invoice_ids' => [$qrResponse->json('data.id')],
        'format' => 'sepa_pain_001_it',
    ]);

    $painResponse->assertOk()
        ->assertJsonPath('data.format', 'sepa_pain_001_it')
        ->assertJsonStructure(['data' => ['batch_id', 'message_id', 'xml_preview']]);
});

// ------------------------------------------------------------------------------------------------
// TimeAttendanceController Endpoints
// ------------------------------------------------------------------------------------------------

test('time attendance controller manages clock-in, clock-out lifecycle and reports', function (): void {
    // 1. Clock in
    $clockInResponse = apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => now()->format('Y-m-d H:i:s'),
        'latitude' => 45.4642,
        'longitude' => 9.1900,
        'notes' => 'Morning shift',
    ]);
    $clockInResponse->assertCreated();

    // 2. Clock in again while active shift exists -> 409 Conflict
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => now()->format('Y-m-d H:i:s'),
    ])->assertStatus(409);

    // 3. Clock out with invalid timestamp before clock in
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => now()->subHours(2)->format('Y-m-d H:i:s'),
    ])->assertStatus(422);

    // 4. Clock out successfully
    $clockOutResponse = apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => now()->addHours(8)->format('Y-m-d H:i:s'),
        'break_minutes' => 45,
        'notes' => 'Completed shift',
    ]);
    $clockOutResponse->assertOk();

    // 5. Clock out when no active shift exists -> 422
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => now()->format('Y-m-d H:i:s'),
    ])->assertStatus(422);

    // 6. List entries
    apiRequest()->getJson('/api/v1/time-attendance/entries')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    // 7. Compliance check
    apiRequest()->getJson('/api/v1/time-attendance/compliance-check')
        ->assertOk()
        ->assertJsonPath('data.jurisdiction', 'IT');

    // 8. Monthly report
    apiRequest()->getJson('/api/v1/time-attendance/monthly-report?month='.now()->month.'&year='.now()->year)
        ->assertOk()
        ->assertJsonStructure(['data' => ['summary', 'compliance', 'entries']]);
});

// ------------------------------------------------------------------------------------------------
// TreasuryController Endpoints
// ------------------------------------------------------------------------------------------------

test('treasury controller handles bank accounts, auto-reconciliation, and camt/cbi imports', function (): void {
    $bankAccount = BankAccount::create([
        'tenant_id' => $this->tenant->id,
        'bank_name' => 'Intesa Sanpaolo',
        'iban' => 'IT60X0542811101000000123456',
        'currency' => 'EUR',
    ]);

    // 1. List accounts and transactions
    apiRequest()->getJson('/api/v1/treasury/bank-accounts')
        ->assertOk()
        ->assertJsonPath('data.0.bank_name', 'Intesa Sanpaolo');

    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Debtor Client SRL',
        'type' => 'customer',
        'country' => 'IT',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-MATCH-100',
        'date' => now()->toDateString(),
        'total_net' => 200,
        'total_gross' => 244,
        'payment_status' => 'unpaid',
    ]);

    $transaction = BankTransaction::create([
        'tenant_id' => $this->tenant->id,
        'bank_account_id' => $bankAccount->id,
        'booking_date' => now()->toDateString(),
        'value_date' => now()->toDateString(),
        'amount' => 244.00,
        'currency' => 'EUR',
        'description' => 'Payment for INV-MATCH-100',
        'reference' => 'INV-MATCH-100',
        'status' => 'unmatched',
    ]);

    apiRequest()->getJson('/api/v1/treasury/transactions')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    // 2. Auto-match reconciliation
    $matchResponse = apiRequest()->postJson('/api/v1/treasury/reconciliation/auto-match');
    $matchResponse->assertOk()
        ->assertJsonPath('data.matched_invoices', 1);

    expect($transaction->fresh()->status)->toBe('matched')
        ->and($invoice->fresh()->payment_status)->toBe('paid');

    // 3. CAMT.053 XML import
    $invalidCamt = UploadedFile::fake()->createWithContent('invalid.xml', 'Not CAMT XML');
    apiRequest()->postJson('/api/v1/treasury/import-camt', [
        'camt_file' => $invalidCamt,
        'bank_account_id' => $bankAccount->id,
    ])->assertStatus(422);

    $camtXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Ntry>
        <Amt Ccy="EUR">550.00</Amt>
        <Dt>2026-10-31</Dt>
        <Ref>CAMT-REF-01</Ref>
        <Ustrd>Direct Wire Transfer</Ustrd>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;

    $validCamt = UploadedFile::fake()->createWithContent('statement.xml', $camtXml);
    apiRequest()->postJson('/api/v1/treasury/import-camt', [
        'camt_file' => $validCamt,
        'bank_account_id' => $bankAccount->id,
    ])->assertOk()->assertJsonPath('data.imported_records', 1);

    // 4. CBI import
    $cbiFile = UploadedFile::fake()->create('cbi.txt', 256);
    apiRequest()->postJson('/api/v1/treasury/import-cbi', [
        'cbi_file' => $cbiFile,
        'bank_account_id' => $bankAccount->id,
    ])->assertOk()->assertJsonPath('data.status', 'parsed');
});
