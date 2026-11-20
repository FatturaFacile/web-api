<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\DigitalPreservation\Domain\Contracts\PreservationConnectorInterface;
use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Domain\Exceptions\ConnectorException;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationImmutableException;
use Modules\DigitalPreservation\Domain\ValueObjects\ConnectorReceipt;
use Modules\DigitalPreservation\Infrastructure\Connectors\ArubaPecConnector;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationAuditLog;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;
use Modules\DigitalPreservation\Infrastructure\Services\MerkleTreeHasher;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationAuditLogger;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationVault;
use Modules\Documents\Models\DmsDocument;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

const DP = '/api/v1/preservation';

function dpInvoice(Tenant $tenant, string $number, string $date = '2027-01-15'): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Beta', 'vat_number' => '09876543210']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => $date, 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 1000, 'total_tax' => 220, 'total_gross' => 1220, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();
    InvoiceSdiMetadata::create(['invoice_id' => $invoice->id, 'transmission_format' => 'FPR12', 'file_name' => "IT00811720580_{$number}.xml", 'xml_content' => "<fattura>{$number}</fattura>", 'sdi_identifier' => '9876543210']);

    return $invoice;
}

/**
 * @return array<string, mixed>
 */
function dpCreateBatch(string $category = 'active_invoices', string $from = '2027-01-01', string $to = '2027-12-31'): array
{
    return test()->postJson(DP.'/batches', ['document_category' => $category, 'period_from' => $from, 'period_to' => $to])->assertCreated()->json('data');
}

function dpUploadScan(string $title = 'Ricevuta spese'): string
{
    $id = test()->post('/api/v1/dms/documents', ['title' => $title, 'file' => UploadedFile::fake()->createWithContent('ricevuta.jpg', "\xFF\xD8\xFFscan-bytes")], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    DmsDocument::findOrFail($id)->update(['metadata' => ['preservation_category' => 'analog_dematerialized', 'document_number' => '88', 'document_date' => '2027-03-01']]);

    return $id;
}

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('preservation_worm');
    config(['filesystems.private_disk' => 'local', 'digital_preservation.pdfa_driver' => 'simulated']);
    app()->instance(HostResolver::class, new StaticHostResolver);
    $this->tenant = complianceTenant();
    $this->admin = actingAsMember($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('invoices and a scanned receipt go from draft to a dispatched, verifiable and exportable preservation package', function (): void {
    dpInvoice($this->tenant, 'FT-1');
    dpInvoice($this->tenant, 'FT-2');
    dpUploadScan();

    $invoices = dpCreateBatch('active_invoices');
    expect($invoices['status'])->toBe('draft')->and($invoices['item_count'])->toBe(2)->and($invoices['retention_until'])->toBe('2037-12-31');

    $analog = dpCreateBatch('analog_dematerialized', now()->subDay()->toDateString(), now()->addDay()->toDateString());
    $detail = $this->getJson(DP."/batches/{$analog['id']}")->assertOk()->json('data');
    expect($detail['items'][0])->toMatchArray(['is_pdfa_compliant' => true, 'pdfa_conformance_level' => 'PDF/A-2b', 'mime_type' => 'application/pdf']);

    $sealed = $this->postJson(DP."/batches/{$invoices['id']}/seal")->assertOk()->json('data');
    expect($sealed['status'])->toBe('timestamped')
        ->and($sealed['previous_batch_sha256'])->toBeNull()
        ->and($sealed['pdv_sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($sealed['has_timestamp'])->toBeTrue();

    $detail = $this->getJson(DP."/batches/{$invoices['id']}")->assertOk()->json('data');
    $leaves = collect($detail['items'])->pluck('sha256_hash')->all();
    expect($detail['merkle_root_sha256'])->toBe(app(MerkleTreeHasher::class)->root($leaves))
        ->and(hash('sha256', $detail['pdv_xml']))->toBe($detail['pdv_sha256'])
        ->and($detail['pdv_xml'])->toContain('urn:agid:conservazione:pdv:v1.2')->toContain('FATTURA_ELETTRONICA_ATTIVA')->toContain('<IdoneoLungoTermine>true</IdoneoLungoTermine>')
        ->and($detail['timestamp']['provider'])->toBe('local-test-tsa')->and($detail['timestamp']['qualified'])->toBeFalse();

    $second = $this->postJson(DP."/batches/{$analog['id']}/seal")->assertOk()->json('data');
    expect($second['previous_batch_sha256'])->toBe($sealed['pdv_sha256']);

    $dispatched = $this->postJson(DP."/batches/{$invoices['id']}/dispatch")->assertOk()->json('data');
    expect($dispatched['status'])->toBe('confirmed')->and($dispatched['has_rdv'])->toBeTrue()->and($dispatched['external_conservator_id'])->toStartWith('LOCAL-');

    $report = $this->postJson(DP."/batches/{$invoices['id']}/verify")->assertOk()->json('data');
    expect($report)->toMatchArray(['ok' => true, 'checked_files' => 2, 'failures' => []])
        ->and($report['chain']['ok'])->toBeTrue()->and($report['timestamp']['valid'])->toBeTrue()->and($report['audit_chain']['ok'])->toBeTrue();

    $zipPath = tempnam(sys_get_temp_dir(), 'pddtest');
    file_put_contents($zipPath, $this->get(DP."/batches/{$invoices['id']}/export-pdd")->assertOk()->assertHeader('content-type', 'application/zip')->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => (string) $zip->getNameIndex($i));
    expect($names->filter(fn ($n) => str_starts_with($n, 'documents/'))->count())->toBe(2)
        ->and($names->all())->toContain('PdV.xml', 'PdA_manifest.json', 'timestamp.tsr', 'RdV.xml', 'CERTIFICATE_OF_CONFORMITY.pdf', 'verification_report.json');
    expect($zip->getFromName('CERTIFICATE_OF_CONFORMITY.pdf'))->toStartWith('%PDF-')
        ->and(json_decode((string) $zip->getFromName('verification_report.json'), true)['ok'])->toBeTrue();
    $zip->close();
    @unlink($zipPath);

    $item = $detail['items'][0];
    $itemZip = $this->get(DP."/items/{$item['id']}/export-pdd")->assertOk();
    expect($itemZip->headers->get('content-disposition'))->toContain('PdD_'.$invoices['batch_number']);
    $this->postJson(DP."/items/{$item['id']}/verify")->assertOk()->assertJsonPath('data.ok', true);

    $dashboard = $this->getJson(DP.'/dashboard')->assertOk()->json('data');
    expect($dashboard)->toMatchArray(['preserved_documents' => 3, 'chain_status' => 'verified', 'retention_years' => 10, 'pending_unpreserved_documents' => 0]);

    $logs = $this->getJson(DP.'/audit-logs')->assertOk();
    expect($logs->json('meta.chain_valid'))->toBeTrue();
    expect(collect($logs->json('data'))->pluck('action')->unique()->all())->toContain('ingested', 'pdfa_converted', 'pdv_generated', 'sealed', 'timestamped', 'dispatched', 'rdv_received', 'pdd_exported', 'integrity_verified');
});

test('a search finds a preserved document by number, tax id, hash and category', function (): void {
    dpInvoice($this->tenant, 'FT-77');
    $batch = dpCreateBatch();
    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertOk();
    $hash = PreservationBatchItem::firstOrFail()->sha256_hash;

    foreach (['search=FT-77', 'document_number=ft-77', 'tax_id=09876543210', "sha256={$hash}", 'counterparty=beta', 'document_category=active_invoices', 'jurisdiction=DUAL', 'date_from=2027-01-01&date_to=2027-01-31'] as $query) {
        expect($this->getJson(DP."/items?{$query}")->assertOk()->json('meta.total'))->toBe(1, $query);
    }

    expect($this->getJson(DP.'/items?search=nothing-here')->json('meta.total'))->toBe(0);
});

test('a sealed batch, its items and the audit log cannot be changed or deleted before retention ends', function (): void {
    dpInvoice($this->tenant, 'FT-1');
    $batch = dpCreateBatch();
    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertOk();

    $model = PreservationBatch::findOrFail($batch['id']);
    $item = PreservationBatchItem::firstOrFail();
    $log = PreservationAuditLog::firstOrFail();

    $model->pdv_sha256 = str_repeat('a', 64);
    expect(fn () => $model->save())->toThrow(PreservationImmutableException::class);
    expect(fn () => PreservationBatch::findOrFail($batch['id'])->delete())->toThrow(PreservationImmutableException::class);
    $item->original_filename = 'tampered.xml';
    expect(fn () => $item->save())->toThrow(PreservationImmutableException::class);
    expect(fn () => $item->delete())->toThrow(PreservationImmutableException::class);
    $log->action = 'sealed';
    expect(fn () => $log->save())->toThrow(PreservationImmutableException::class);
    expect(fn () => $log->delete())->toThrow(PreservationImmutableException::class);

    expect(fn () => app(PreservationVault::class)->put((string) $model->pdv_xml_path, 'overwrite'))
        ->toThrow(PreservationImmutableException::class);

    $draft = dpInvoice($this->tenant, 'FT-9', '2028-01-10');
    $second = dpCreateBatch('active_invoices', '2028-01-01', '2028-01-31');
    expect(PreservationBatch::findOrFail($second['id'])->delete())->toBeTrue();
});

test('tampering with a preserved file or the audit log is detected and raises an alert', function (): void {
    dpInvoice($this->tenant, 'FT-1');
    $batch = dpCreateBatch();
    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertOk();
    $item = PreservationBatchItem::firstOrFail();

    Storage::disk('preservation_worm')->put($item->archive_path, '<fattura>tampered</fattura>');

    $report = $this->postJson(DP."/batches/{$batch['id']}/verify")->assertOk()->json('data');
    expect($report['ok'])->toBeFalse()->and(collect($report['failures'])->pluck('type')->all())->toContain('file_hash_mismatch');
    $this->postJson(DP."/items/{$item->id}/verify")->assertOk()->assertJsonPath('data.ok', false);
    expect(PreservationAuditLog::where('action', 'integrity_alert')->exists())->toBeTrue();
    expect($this->getJson(DP.'/dashboard')->json('data.chain_status'))->toBe('alert');

    DB::table('preservation_audit_logs')->where('sequence', 1)->update(['details_json' => json_encode(['forged' => true])]);
    expect(app(PreservationAuditLogger::class)->verifyChain()['ok'])->toBeFalse();
    expect($this->getJson(DP.'/audit-logs')->json('meta.chain_valid'))->toBeFalse();
});

test('batches, items and audit entries of another tenant are invisible', function (): void {
    dpInvoice($this->tenant, 'FT-1');
    $batch = dpCreateBatch();
    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertOk();
    $itemId = PreservationBatchItem::firstOrFail()->id;

    app(TenantContext::class)->clear();
    $other = complianceTenant('Beta');
    actingAsMember($other);

    $this->getJson(DP.'/batches')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(DP."/batches/{$batch['id']}")->assertNotFound();
    $this->postJson(DP."/batches/{$batch['id']}/verify")->assertNotFound();
    $this->get(DP."/batches/{$batch['id']}/export-pdd")->assertNotFound();
    $this->get(DP."/items/{$itemId}/export-pdd")->assertNotFound();
    $this->getJson(DP.'/items')->assertJsonPath('meta.total', 0);
    $this->getJson(DP.'/audit-logs')->assertJsonPath('meta.total', 0);
    $this->getJson(DP.'/dashboard')->assertOk()->assertJsonPath('data.preserved_documents', 0);
});

test('the configuration keeps credentials encrypted, write-only and validates the retention minimum', function (): void {
    $payload = [
        'jurisdiction' => 'IT', 'provider' => 'infocert', 'credentials' => ['api_key' => 'super-secret-key', 'organization_id' => 'org-1'],
        'preservation_officer_name' => 'Mario Rossi', 'preservation_officer_tax_code' => 'RSSMRA80A01F205X',
        'tsa_url' => 'https://tsa.example.org/tsr', 'tsa_credentials' => ['username' => 'u', 'password' => 'p'],
        'auto_seal_frequency' => 'weekly', 'default_retention_years' => 12, 'is_active' => true,
    ];

    $saved = $this->putJson(DP.'/configuration', $payload)->assertOk()->json('data');
    expect($saved)->toMatchArray(['jurisdiction' => 'IT', 'provider' => 'infocert', 'has_credentials' => true, 'has_tsa_credentials' => true, 'default_retention_years' => 12]);
    expect(json_encode($saved))->not->toContain('super-secret-key');

    $raw = DB::table('preservation_configurations')->first();
    expect($raw->credentials)->not->toContain('super-secret-key')->and($raw->tsa_credentials)->not->toContain('"password"');

    $this->putJson(DP.'/configuration', array_diff_key($payload, ['credentials' => 1, 'tsa_credentials' => 1]) + ['default_retention_years' => 12])->assertOk();
    expect(PreservationBatch::query()->count())->toBe(0);
    expect(PreservationConfiguration::firstOrFail()->credentials)->toBe(['api_key' => 'super-secret-key', 'organization_id' => 'org-1']);

    $this->putJson(DP.'/configuration', ['default_retention_years' => 5] + $payload)->assertUnprocessable();
    $this->putJson(DP.'/configuration', ['tsa_url' => 'http://insecure.example.org'] + $payload)->assertUnprocessable();

    $this->putJson(DP.'/configuration', ['provider' => 'local_worm'] + $payload)->assertOk();
    $this->postJson(DP.'/configuration/test-connection')->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.provider', 'local_worm');
    $this->getJson(DP.'/configuration')->assertOk()->assertJsonPath('data.provider', 'local_worm');
});

test('a refused dispatch marks the batch failed and can be retried', function (): void {
    dpInvoice($this->tenant, 'FT-1');
    $this->putJson(DP.'/configuration', [
        'jurisdiction' => 'IT', 'provider' => 'aruba_pec', 'credentials' => ['api_key' => 'k'], 'auto_seal_frequency' => 'daily', 'default_retention_years' => 10, 'is_active' => true,
    ])->assertOk();

    $calls = 0;
    app()->bind(ArubaPecConnector::class, function () use (&$calls) {
        return new class($calls) implements PreservationConnectorInterface
        {
            public function __construct(private int &$calls) {}

            public function provider(): PreservationProvider
            {
                return PreservationProvider::ArubaPec;
            }

            public function dispatch(PreservationBatch $batch, string $pdvXml, array $files, PreservationConfiguration $configuration): ConnectorReceipt
            {
                if (++$this->calls === 1) {
                    throw new ConnectorException('aruba_pec provider unavailable (HTTP 503)');
                }

                return new ConnectorReceipt('ARUBA-1', '<RdV>ok</RdV>', false);
            }

            public function testConnection(PreservationConfiguration $configuration): bool
            {
                return true;
            }
        };
    });

    $batch = dpCreateBatch();
    $this->postJson(DP."/batches/{$batch['id']}/dispatch")->assertUnprocessable();
    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertOk();
    $this->postJson(DP."/batches/{$batch['id']}/dispatch")->assertStatus(502);
    expect(PreservationBatch::findOrFail($batch['id'])->status->value)->toBe('failed');

    $ok = $this->postJson(DP."/batches/{$batch['id']}/dispatch")->assertOk()->json('data');
    expect($ok['status'])->toBe('dispatched')->and($ok['external_conservator_id'])->toBe('ARUBA-1');
    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertUnprocessable();
});

test('a batch of an empty period is refused and a draft cannot be exported', function (): void {
    $this->postJson(DP.'/batches', ['document_category' => 'active_invoices', 'period_from' => '2027-01-01', 'period_to' => '2027-12-31'])->assertUnprocessable();
    $this->postJson(DP.'/batches', ['document_category' => 'bogus', 'period_from' => '2027-02-01', 'period_to' => '2027-01-01'])->assertUnprocessable();

    dpInvoice($this->tenant, 'FT-1');
    $batch = dpCreateBatch();
    $this->get(DP."/batches/{$batch['id']}/export-pdd")->assertUnprocessable();
});

test('a document is never preserved twice and a failed time-stamp authority leaves the batch sealed for a retry', function (): void {
    dpInvoice($this->tenant, 'FT-1');
    $batch = dpCreateBatch();
    $this->postJson(DP.'/batches', ['document_category' => 'active_invoices', 'period_from' => '2027-01-01', 'period_to' => '2027-12-31'])->assertUnprocessable();

    $this->putJson(DP.'/configuration', [
        'jurisdiction' => 'DUAL', 'provider' => 'local_worm', 'tsa_url' => 'https://tsa.example.org/tsr', 'auto_seal_frequency' => 'daily', 'default_retention_years' => 10, 'is_active' => true,
    ])->assertOk();
    Http::fake(['tsa.example.org/*' => Http::response('boom', 500)]);

    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertUnprocessable();
    expect(PreservationBatch::findOrFail($batch['id'])->status->value)->toBe('sealed');

    $this->putJson(DP.'/configuration', [
        'jurisdiction' => 'DUAL', 'provider' => 'local_worm', 'tsa_url' => null, 'auto_seal_frequency' => 'daily', 'default_retention_years' => 10, 'is_active' => true,
    ])->assertOk();
    $this->postJson(DP."/batches/{$batch['id']}/seal")->assertOk()->assertJsonPath('data.status', 'timestamped');
});

test('the scheduled run ingests, seals and dispatches, and the verification job re-checks the vault', function (): void {
    dpInvoice($this->tenant, 'FT-1', now()->subDays(3)->toDateString());
    $this->putJson(DP.'/configuration', [
        'jurisdiction' => 'DUAL', 'provider' => 'local_worm', 'auto_seal_frequency' => 'daily', 'default_retention_years' => 10, 'is_active' => true,
    ])->assertOk();

    $this->artisan('fatturafacile:preservation:run', ['--force' => true])->assertSuccessful();

    $batch = PreservationBatch::firstOrFail();
    expect($batch->status->value)->toBe('confirmed')->and($batch->item_count)->toBe(1);

    $this->artisan('fatturafacile:preservation:verify')->assertSuccessful();
    expect(PreservationAuditLog::where('action', 'integrity_verified')->count())->toBe(1);

    Storage::disk('preservation_worm')->put(PreservationBatchItem::firstOrFail()->archive_path, 'rot');
    $this->artisan('preservation:verify-integrity')->assertSuccessful();
    expect(PreservationAuditLog::where('action', 'integrity_alert')->count())->toBe(1);
});
