<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\FieldService\SignWorkOrder;
use App\Models\Attachment;
use App\Models\Contact;
use App\Models\DocumentSequence;
use App\Models\EiInboundLog;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Enterprise IT',
        'legal_name' => 'FatturaFacile Enterprise IT SRL',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'vat_number' => '12345678901',
        'fiscal_code' => '12345678901',
    ]);

    $this->user = User::create([
        'name' => 'Admin Operator',
        'email' => 'admin@fatturafacile.it',
        'password' => bcrypt('password123'),
        'locale' => 'it',
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->user);
});

/*
|--------------------------------------------------------------------------
| 1. Document Numbering Race Condition Fixes Tests
|--------------------------------------------------------------------------
*/

test('HIGH-01: DocumentNumberingService reserves consecutive gapless numbers without collision', function (): void {
    $service = app(DocumentNumberingService::class);

    $doc1 = $service->next('invoice', 2026);
    $doc2 = $service->next('invoice', 2026);
    $doc3 = $service->next('invoice', 2026);

    expect($doc1['number'])->toBe('INV-2026-0001')
        ->and($doc1['sequential_number'])->toBe(1)
        ->and($doc2['number'])->toBe('INV-2026-0002')
        ->and($doc2['sequential_number'])->toBe(2)
        ->and($doc3['number'])->toBe('INV-2026-0003')
        ->and($doc3['sequential_number'])->toBe(3);

    // Different document types have independent sequences
    $quote1 = $service->next('quote', 2026);
    expect($quote1['number'])->toBe('QT-2026-0001')
        ->and($quote1['sequential_number'])->toBe(1);

    // Sequence record in database is updated
    $seq = DocumentSequence::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->where('document_type', 'invoice')
        ->where('fiscal_year', 2026)
        ->first();

    expect($seq)->not->toBeNull()
        ->and($seq->last_number)->toBe(3);
});

test('HIGH-01: DocumentNumberingService throws exception when tenant context is missing', function (): void {
    app(TenantContext::class)->setTenant(null);

    $service = app(DocumentNumberingService::class);

    expect(fn () => $service->next('invoice', 2026))
        ->toThrow(InvalidArgumentException::class, 'Active tenant context is required');
});

/*
|--------------------------------------------------------------------------
| 2. Incoming Invoice Webhook Idempotency Protection Tests
|--------------------------------------------------------------------------
*/

test('HIGH-02: incoming invoice webhook is idempotent and prevents duplicate passive invoice creation', function (): void {
    Config::set('services.sdi.webhook_secret', 'sdi-secure-token');

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">
  <FatturaElettronicaHeader>
    <CedentePrestatore>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>09876543210</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Fornitore Elettrico SPA</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CedentePrestatore>
    <CessionarioCommittente>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>12345678901</IdCodice></IdFiscaleIVA>
      </DatiAnagrafici>
    </CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <TipoDocumento>TD01</TipoDocumento>
        <Divisa>EUR</Divisa>
        <Data>2026-10-28</Data>
        <Numero>FPR-999</Numero>
        <ImportoTotaleDocumento>122.00</ImportoTotaleDocumento>
      </DatiGeneraliDocumento>
    </DatiGenerali>
    <DatiBeniServizi>
      <DatiRiepilogo>
        <AliquotaIVA>22.00</AliquotaIVA>
        <ImponibileImporto>100.00</ImponibileImporto>
        <Imposta>22.00</Imposta>
      </DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;

    $payload = [
        'provider' => 'aruba',
        'file_name' => 'IT09876543210_00001.xml',
        'xml' => $xml,
    ];

    // First delivery creates passive invoice and inbound log
    $response1 = $this->withHeaders(['X-Webhook-Secret' => 'sdi-secure-token'])
        ->postJson('/api/v1/webhooks/sdi/incoming-invoice', $payload);

    $response1->assertCreated()
        ->assertJsonPath('data.duplicate', false)
        ->assertJsonPath('data.documents', 1);

    expect(PassiveInvoice::withoutGlobalScopes()->where('invoice_number', 'FPR-999')->count())->toBe(1)
        ->and(EiInboundLog::where('event_type', 'INVOICE_RECEIVED')->count())->toBe(1);

    // Second duplicate delivery returns 200 OK with duplicate => true and creates NO duplicate records
    $response2 = $this->withHeaders(['X-Webhook-Secret' => 'sdi-secure-token'])
        ->postJson('/api/v1/webhooks/sdi/incoming-invoice', $payload);

    $response2->assertOk()
        ->assertJsonPath('data.duplicate', true);

    expect(PassiveInvoice::withoutGlobalScopes()->where('invoice_number', 'FPR-999')->count())->toBe(1)
        ->and(EiInboundLog::where('event_type', 'INVOICE_RECEIVED')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 3. Storage Portability Abstraction Tests
|--------------------------------------------------------------------------
*/

test('HIGH-03: attachments use configurable private storage disk and record disk column', function (): void {
    Storage::fake('custom_private');
    Config::set('filesystems.private_disk', 'custom_private');

    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'client',
        'company_name' => 'Storage Test Client',
        'country' => 'IT',
    ]);

    $file = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');

    $response = $this->withHeader('X-Tenant-ID', $this->tenant->id)
        ->postJson('/api/v1/attachments', [
            'attachable_type' => 'contact',
            'attachable_id' => $contact->id,
            'file' => $file,
        ]);

    $response->assertCreated();

    $attachmentId = $response->json('data.id');
    $attachment = Attachment::findOrFail($attachmentId);

    // Disk recorded and file stored on configurable disk
    expect($attachment->disk)->toBe('custom_private')
        ->and($attachment->getStorageDisk())->toBe('custom_private');

    Storage::disk('custom_private')->assertExists($attachment->path);

    // Download uses resolved storage disk
    $download = $this->withHeader('X-Tenant-ID', $this->tenant->id)
        ->get("/api/v1/attachments/{$attachmentId}/download");

    $download->assertOk();

    // Destroy uses resolved storage disk and removes file
    $destroy = $this->withHeader('X-Tenant-ID', $this->tenant->id)
        ->deleteJson("/api/v1/attachments/{$attachmentId}");

    $destroy->assertOk();
    Storage::disk('custom_private')->assertMissing($attachment->path);
});

test('HIGH-03: SignWorkOrder uses configurable private storage disk', function (): void {
    Storage::fake('workorder_disk');
    Config::set('filesystems.private_disk', 'workorder_disk');

    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'client',
        'company_name' => 'WO Client',
        'country' => 'IT',
    ]);

    $workOrder = WorkOrder::create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $contact->id,
        'technician_user_id' => $this->user->id,
        'work_order_number' => 'WO-TEST-001',
        'date' => now()->toDateString(),
        'status' => 'draft',
    ]);

    // 1x1 transparent PNG base64
    $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    $action = app(SignWorkOrder::class);
    $signed = $action->execute($workOrder, 'Mario Rossi', $pngBase64);

    expect($signed->client_signature_path)->not->toBeNull();
    Storage::disk('workorder_disk')->assertExists($signed->client_signature_path);
});

/*
|--------------------------------------------------------------------------
| 4. Missing PostgreSQL Indexes Verification Tests
|--------------------------------------------------------------------------
*/

test('HIGH-04: performance composite indexes exist in database schema', function (): void {
    $expectedIndexes = [
        'invoices' => ['invoices_tenant_id_contact_id_idx'],
        'quotes' => ['quotes_tenant_id_contact_id_idx', 'quotes_tenant_id_status_idx', 'quotes_tenant_id_date_idx'],
        'sales_orders' => ['sales_orders_tenant_id_contact_id_idx', 'sales_orders_tenant_id_status_idx', 'sales_orders_tenant_id_date_idx'],
        'delivery_notes' => ['delivery_notes_tenant_id_contact_id_idx', 'delivery_notes_tenant_id_date_idx'],
        'payments' => ['payments_tenant_id_paid_at_idx'],
        'passive_invoices' => ['passive_invoices_tenant_id_supplier_id_idx', 'passive_invoices_tenant_id_date_idx'],
        'purchase_orders' => ['purchase_orders_tenant_id_supplier_id_idx', 'purchase_orders_tenant_id_status_idx', 'purchase_orders_tenant_id_date_idx'],
        'tickets' => ['tickets_tenant_id_client_id_idx'],
        'work_orders' => ['work_orders_tenant_id_client_id_idx', 'work_orders_tenant_id_status_idx', 'work_orders_tenant_id_date_idx'],
        'contacts' => ['contacts_tenant_id_vat_number_idx', 'contacts_tenant_id_fiscal_code_idx'],
        'attachments' => ['attachments_tenant_attachable_idx'],
    ];

    foreach ($expectedIndexes as $table => $indexNames) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        $tableIndexes = Schema::getIndexes($table);
        $names = array_column($tableIndexes, 'name');

        foreach ($indexNames as $indexName) {
            expect($names)->toContain($indexName);
        }
    }
});
