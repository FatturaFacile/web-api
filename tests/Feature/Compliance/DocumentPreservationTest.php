<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Documents\Jobs\ApplyDocumentRetentionJob;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsRetentionPolicy;
use Modules\Documents\Services\DocumentRetention;
use Modules\DocumentTrust\Contracts\TrustedTimestampProvider;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;
use Modules\DocumentTrust\Jobs\VerifyPreservationPackagesJob;
use Modules\DocumentTrust\Models\PreservationAccessLog;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Models\PreservationVerification;
use Modules\DocumentTrust\Services\PreservationPackageService;
use Modules\DocumentTrust\Support\TimestampToken;

require_once __DIR__.'/ComplianceHelpers.php';

uses(RefreshDatabase::class);

const PRESERVATION_BASE = '/api/v1/document-trust';

function preservationInvoice(Tenant $tenant, string $number = 'FT-1', string $date = '2027-01-15', ?string $xml = null): Invoice
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Rossi', 'vat_number' => '01234567890']);
    $invoice = new Invoice(['contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => $number, 'date' => $date, 'due_date' => '2027-02-15', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'total_paid' => 0, 'payment_status' => 'unpaid']);
    $invoice->tenant_id = $tenant->id;
    $invoice->save();
    InvoiceSdiMetadata::create(['invoice_id' => $invoice->id, 'transmission_format' => 'FPR12', 'file_name' => "IT00811720580_{$number}.xml", 'xml_content' => $xml ?? "<xml>{$number}</xml>"]);

    return $invoice;
}

function preservationPackage(string $class = 'issued_invoice', array $extra = []): string
{
    return test()->postJson(PRESERVATION_BASE.'/preservation-packages', ['document_class' => $class, 'name' => 'Pacchetto test', ...$extra])->assertCreated()->json('data.id');
}

/**
 * @return array{package: string, invoice: Invoice, item: PreservationItem}
 */
function closedInvoicePackage(Tenant $tenant, string $number = 'FT-1'): array
{
    $invoice = preservationInvoice($tenant, $number);
    $package = preservationPackage();
    test()->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $invoice->id])->assertCreated();
    test()->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/close")->assertOk();

    return ['package' => $package, 'invoice' => $invoice, 'item' => PreservationItem::where('package_id', $package)->firstOrFail()];
}

function preservationSwitchTo(Tenant $tenant, string $role = 'admin'): void
{
    app(TenantContext::class)->clear();
    actingAsMember($tenant, $role);
}

function preservationDisableGuard(string $table, string $trigger, Closure $callback): void
{
    DB::unprepared("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    $callback();
    DB::unprepared("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
}

function preservationUploadDocument(string $title, string $content): string
{
    return test()->post('/api/v1/dms/documents', ['title' => $title, 'file' => UploadedFile::fake()->createWithContent('book.csv', $content)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = complianceTenant();
    $this->admin = actingAsMember($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a package of issued invoices is closed with a manifest, a package hash and an honest time stamp', function (): void {
    $invoice = preservationInvoice($this->tenant, 'FT-7', '2027-03-02');
    $package = preservationPackage(extra: ['period_from' => '2027-01-01', 'period_to' => '2027-12-31']);

    $item = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $invoice->id])->assertCreated()->json('data');

    expect($item)->toMatchArray([
        'sha256' => hash('sha256', '<xml>FT-7</xml>'), 'document_number' => 'FT-7', 'document_date' => '2027-03-02', 'counterpart_name' => 'Cliente Rossi',
        'counterpart_vat_number' => '01234567890', 'own_vat_number' => '00811720580', 'document_type' => 'invoice', 'package_status' => null,
    ]);

    $closed = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/close")->assertOk()->json('data');
    $manifest = PreservationPackage::findOrFail($package)->manifest_json;

    expect($closed['status'])->toBe('closed')
        ->and($closed['package_hash'])->toBe(hash('sha256', $manifest))
        ->and($closed['timestamp_provider'])->toBe('local-clock')
        ->and($closed['timestamp_token'])->toBeNull()
        ->and($closed['timestamp_qualified'])->toBeFalse()
        ->and($closed['retention_source'])->toBe('suggested_default')
        ->and($closed['retention_until'])->toBe(now()->addYears($closed['retention_years'])->toDateString());

    $decoded = json_decode($manifest, true);
    expect($decoded['format'])->toBe('fattura-facile-preservation-manifest')
        ->and($decoded['note'])->toContain('Not UNI SINCRO')
        ->and($decoded['files'][0]['sha256'])->toBe(hash('sha256', '<xml>FT-7</xml>'));
});

test('received invoices are preserved from their stored XML', function (): void {
    $supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Fornitore Verdi', 'vat_number' => '09876543210']);
    $received = PassiveInvoice::withoutEvents(fn () => tap(new PassiveInvoice(['supplier_id' => $supplier->id, 'invoice_number' => 'F-99', 'date' => '2027-02-02', 'due_date' => '2027-03-02', 'total_amount' => 61, 'total_net' => 50, 'total_tax' => 11, 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid', 'document_hash' => Str::random(40), 'sdi_file_name' => 'F-99.xml', 'xml_payload' => '<xml>F-99</xml>']), function (PassiveInvoice $invoice): void {
        $invoice->tenant_id = $this->tenant->id;
        $invoice->save();
    }));
    $package = preservationPackage('received_invoice');

    $item = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $received->id])->assertCreated()->json('data');

    expect($item)->toMatchArray(['counterpart_name' => 'Fornitore Verdi', 'counterpart_vat_number' => '09876543210', 'document_number' => 'F-99', 'file_name' => 'F-99.xml']);
});

test('ledger books, payslips and contracts are preserved from the library file with the stated metadata', function (string $class): void {
    $document = preservationUploadDocument('Documento', "contenuto {$class}");
    $package = preservationPackage($class);

    $item = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $document, 'document_date' => '2027-06-30', 'document_number' => 'LG-2026', 'counterpart_name' => 'Mario Bianchi', 'counterpart_vat_number' => 'BNCMRA80A01H501Z'])->assertCreated()->json('data');

    expect($item)->toMatchArray(['document_type' => $class, 'document_number' => 'LG-2026', 'sha256' => hash('sha256', "contenuto {$class}"), 'source_type' => 'dms_document']);
})->with(['ledger_book', 'payslip', 'contract']);

test('a document is refused when it has no file, is added twice or falls outside the period', function (): void {
    $invoice = preservationInvoice($this->tenant, 'FT-1', '2026-05-05');
    $package = preservationPackage(extra: ['period_from' => '2027-01-01', 'period_to' => '2027-12-31']);

    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $invoice->id])->assertUnprocessable();

    $open = preservationPackage();
    $noXml = preservationInvoice($this->tenant, 'FT-2');
    InvoiceSdiMetadata::where('invoice_id', $noXml->id)->delete();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$open}/items", ['source_id' => $noXml->id])->assertUnprocessable();

    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$open}/items", ['source_id' => $invoice->id])->assertCreated();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$open}/items", ['source_id' => $invoice->id])->assertUnprocessable();
    $this->postJson(PRESERVATION_BASE.'/preservation-packages/'.preservationPackage().'/close')->assertUnprocessable();
});

test('a closed package accepts no new file, removal or deletion', function (): void {
    ['package' => $package, 'item' => $item] = closedInvoicePackage($this->tenant);
    $other = preservationInvoice($this->tenant, 'FT-9');

    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $other->id])->assertConflict();
    $this->deleteJson(PRESERVATION_BASE."/preservation-packages/{$package}/items/{$item->id}")->assertConflict();
    $this->deleteJson(PRESERVATION_BASE."/preservation-packages/{$package}")->assertConflict();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/close")->assertConflict();

    expect(PreservationItem::where('package_id', $package)->count())->toBe(1)
        ->and(Storage::disk('local')->exists($item->storage_path))->toBeTrue();
});

test('the models of a closed package refuse every update and delete', function (): void {
    ['package' => $packageId, 'item' => $item] = closedInvoicePackage($this->tenant);
    $package = PreservationPackage::findOrFail($packageId);

    expect(fn () => $package->update(['name' => 'Altro']))->toThrow(PreservationImmutableException::class)
        ->and(fn () => $package->delete())->toThrow(PreservationImmutableException::class)
        ->and(fn () => $item->update(['sha256' => str_repeat('a', 64)]))->toThrow(PreservationImmutableException::class)
        ->and(fn () => $item->delete())->toThrow(PreservationImmutableException::class);
});

test('raw queries on a closed package are stopped by the database too', function (): void {
    ['package' => $package] = closedInvoicePackage($this->tenant);
    expect(fn () => DB::table('preservation_items')->where('package_id', $package)->update(['sha256' => str_repeat('b', 64)]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('preservation_items')->where('package_id', $package)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('preservation_packages')->where('id', $package)->update(['package_hash' => str_repeat('c', 64)]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('preservation_packages')->where('id', $package)->delete())->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'The triggers exist on PostgreSQL only');

test('raw queries cannot rewrite the verification history or the access log', function (): void {
    ['package' => $package] = closedInvoicePackage($this->tenant);
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->assertOk();

    expect(fn () => DB::table('preservation_verifications')->update(['result' => 'passed']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('preservation_access_logs')->delete())->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'The triggers exist on PostgreSQL only');

test('deleting a tenant still cascades to its closed packages', function (): void {
    closedInvoicePackage($this->tenant);
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta Srl', 'vat_number' => '00000000000', 'country' => 'IT', 'default_currency' => 'EUR']);

    DB::table('tenants')->where('id', $this->tenant->id)->delete();

    expect(DB::table('preservation_packages')->count())->toBe(0)->and($other->exists)->toBeTrue();
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'The triggers exist on PostgreSQL only');

test('a library document in a closed package cannot be changed, versioned, deleted or purged by retention', function (): void {
    $policy = DmsRetentionPolicy::create(['name' => 'Subito', 'document_type' => 'contract', 'retention_months' => 1, 'action' => 'delete', 'is_active' => true]);
    $document = preservationUploadDocument('Contratto', 'testo del contratto');
    DmsDocument::where('id', $document)->update(['retention_policy_id' => $policy->id, 'retention_until' => now()->subDay()->toDateString()]);
    $package = preservationPackage('contract');
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $document])->assertCreated();

    $this->putJson("/api/v1/dms/documents/{$document}", ['title' => 'Modificato'])->assertOk();

    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/close")->assertOk();

    $this->putJson("/api/v1/dms/documents/{$document}", ['title' => 'Di nuovo'])->assertConflict();
    $this->deleteJson("/api/v1/dms/documents/{$document}")->assertConflict();
    $this->post("/api/v1/dms/documents/{$document}/versions", ['file' => UploadedFile::fake()->createWithContent('v2.txt', 'nuovo testo')], ['Accept' => 'application/json'])->assertConflict();

    (new ApplyDocumentRetentionJob((string) $this->tenant->id))->handle(app(DocumentRetention::class));

    expect(DmsDocument::find($document))->not->toBeNull()
        ->and(DmsDocument::find($document)->title)->toBe('Modificato');
});

test('tampering with a preserved file is detected by the verification and blocks the export', function (): void {
    ['package' => $package, 'item' => $item] = closedInvoicePackage($this->tenant);

    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->assertOk()->assertJsonPath('data.result', 'passed');

    Storage::disk('local')->put($item->storage_path, '<xml>manomesso</xml>');

    $result = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->assertOk()->json('data');

    expect($result['result'])->toBe('failed')
        ->and($result['failures'])->toBe([['item_id' => $item->id, 'reason' => 'hash_mismatch']])
        ->and($result['manifest_ok'])->toBeTrue();

    $this->getJson(PRESERVATION_BASE."/preservation-packages/{$package}/export")->assertConflict();
    expect(PreservationAccessLog::where('package_id', $package)->where('action', 'export_refused')->exists())->toBeTrue()
        ->and(PreservationAccessLog::where('package_id', $package)->where('action', 'export')->exists())->toBeFalse();
});

test('a deleted file, an altered item record, an altered manifest and a smuggled row are all detected', function (): void {
    ['package' => $package, 'item' => $item] = closedInvoicePackage($this->tenant);

    Storage::disk('local')->delete($item->storage_path);
    $missing = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->json('data.failures');
    Storage::disk('local')->put($item->storage_path, '<xml>FT-1</xml>');

    preservationDisableGuard('preservation_items', 'trg_preservation_items_guard', function () use ($item): void {
        DB::table('preservation_items')->where('id', $item->id)->update(['sha256' => str_repeat('d', 64)]);
    });
    $altered = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->json('data.failures');

    preservationDisableGuard('preservation_items', 'trg_preservation_items_guard', function () use ($item): void {
        DB::table('preservation_items')->where('id', $item->id)->update(['sha256' => hash('sha256', '<xml>FT-1</xml>')]);
        DB::table('preservation_items')->insert([...(array) DB::table('preservation_items')->where('id', $item->id)->first(), 'id' => (string) Str::uuid(), 'sequence' => 2, 'source_id' => (string) Str::uuid()]);
    });
    $smuggled = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->json('data.failures');

    preservationDisableGuard('preservation_packages', 'trg_preservation_packages_guard', function () use ($package): void {
        DB::table('preservation_packages')->where('id', $package)->update(['manifest_json' => DB::raw("replace(manifest_json, 'FT-1', 'FT-2')")]);
    });
    $manifest = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->json('data');

    expect($missing)->toBe([['item_id' => $item->id, 'reason' => 'file_missing']])
        ->and($altered)->toBe([['item_id' => $item->id, 'reason' => 'item_record_altered']])
        ->and(collect($smuggled)->pluck('reason')->all())->toBe(['item_not_in_manifest'])
        ->and($manifest['manifest_ok'])->toBeFalse()
        ->and(collect($manifest['failures'])->pluck('reason')->all())->toContain('manifest_hash_mismatch');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'The triggers exist on PostgreSQL only');

test('the periodic job re-verifies every closed package, records the result and leaves open packages alone', function (): void {
    ['package' => $good] = closedInvoicePackage($this->tenant, 'FT-1');
    ['package' => $bad, 'item' => $badItem] = closedInvoicePackage($this->tenant, 'FT-2');
    preservationPackage();
    Storage::disk('local')->put($badItem->storage_path, 'corrotto');

    $failed = (new VerifyPreservationPackagesJob((string) $this->tenant->id))->handle(app(PreservationPackageService::class));

    $goodCheck = PreservationVerification::where('package_id', $good)->sole();
    $badCheck = PreservationVerification::where('package_id', $bad)->sole();

    expect($failed)->toBe(1)
        ->and($goodCheck->result)->toBe('passed')->and($goodCheck->trigger)->toBe('scheduled')
        ->and($badCheck->result)->toBe('failed')->and($badCheck->failures[0]['reason'])->toBe('hash_mismatch')
        ->and(PreservationVerification::count())->toBe(2);

    $this->getJson(PRESERVATION_BASE.'/preservation-packages?status=closed')->assertOk()->assertJsonPath('data.0.last_verification.trigger', 'scheduled');
});

test('the export is a zip with the manifest, an index and the files, and is logged', function (): void {
    ['package' => $package] = closedInvoicePackage($this->tenant, 'FT-5');

    $response = $this->get(PRESERVATION_BASE."/preservation-packages/{$package}/export")->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => (string) $zip->getNameIndex($i))->sort()->values()->all();
    $manifest = (string) $zip->getFromName('manifest.json');
    $index = (string) $zip->getFromName('index.csv');
    $zip->close();
    unlink($path);

    expect($names)->toBe(['README.txt', 'files/000001-IT00811720580_FT-5.xml', 'index.csv', 'manifest.json'])
        ->and(hash('sha256', $manifest))->toBe(PreservationPackage::findOrFail($package)->package_hash)
        ->and($index)->toContain('sequence;path;sha256')->toContain(hash('sha256', '<xml>FT-5</xml>'))->toContain('FT-5')
        ->and(PreservationAccessLog::where('package_id', $package)->where('action', 'export')->count())->toBe(1);

    $this->getJson(PRESERVATION_BASE.'/preservation-packages/'.preservationPackage().'/export')->assertUnprocessable();
});

test('preserved documents are searched by their metadata and the search is logged', function (): void {
    closedInvoicePackage($this->tenant, 'FT-100');
    $second = preservationInvoice($this->tenant, 'AB-200', '2027-08-20');
    $package = preservationPackage();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $second->id])->assertCreated();

    $byNumber = $this->getJson(PRESERVATION_BASE.'/preservation-items?number=ab-2')->assertOk()->json('data');
    $byDate = $this->getJson(PRESERVATION_BASE.'/preservation-items?date_from=2027-08-01&date_to=2027-08-31')->json('data');
    $byVat = $this->getJson(PRESERVATION_BASE.'/preservation-items?vat_number=01234567890')->json('data');
    $byCounterpart = $this->getJson(PRESERVATION_BASE.'/preservation-items?counterpart=rossi&document_class=issued_invoice')->json('data');
    $none = $this->getJson(PRESERVATION_BASE.'/preservation-items?vat_number=99999999999')->json('data');
    $byHash = $this->getJson(PRESERVATION_BASE.'/preservation-items?sha256='.hash('sha256', '<xml>FT-100</xml>'))->json('data');

    expect(collect($byNumber)->pluck('document_number')->all())->toBe(['AB-200'])
        ->and(collect($byDate)->pluck('document_number')->all())->toBe(['AB-200'])
        ->and($byVat)->toHaveCount(2)
        ->and($byCounterpart)->toHaveCount(2)
        ->and($none)->toBe([])
        ->and(collect($byHash)->pluck('document_number')->all())->toBe(['FT-100'])
        ->and(PreservationAccessLog::where('action', 'search')->count())->toBe(6);

    $this->getJson(PRESERVATION_BASE.'/preservation-items?document_class=unknown')->assertUnprocessable();
});

test('every access leaves a record and the access log is for owners and administrators', function (): void {
    ['package' => $package] = closedInvoicePackage($this->tenant);
    $this->getJson(PRESERVATION_BASE.'/preservation-packages')->assertOk();
    $this->getJson(PRESERVATION_BASE."/preservation-packages/{$package}")->assertOk();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->assertOk();
    $this->get(PRESERVATION_BASE."/preservation-packages/{$package}/export")->assertOk();

    $actions = $this->getJson(PRESERVATION_BASE.'/preservation-access-logs?per_page=100')->assertOk()->json('data');

    expect(collect($actions)->pluck('action')->unique()->sort()->values()->all())->toBe(['add_item', 'close', 'create', 'export', 'list', 'verify', 'view'])
        ->and(collect($actions)->firstWhere('action', 'close')['user_id'])->toBe($this->admin->id);

    $log = PreservationAccessLog::firstOrFail();
    expect(fn () => $log->update(['action' => 'x']))->toThrow(PreservationImmutableException::class)
        ->and(fn () => $log->delete())->toThrow(PreservationImmutableException::class);

    preservationSwitchTo($this->tenant, 'accountant');
    $this->getJson(PRESERVATION_BASE.'/preservation-access-logs')->assertForbidden();
});

test('closing and exporting reach the audit ledger', function (): void {
    ['package' => $package] = closedInvoicePackage($this->tenant);
    $this->get(PRESERVATION_BASE."/preservation-packages/{$package}/export")->assertOk();

    $actions = DB::table('enterprise_audit_ledger')->where('resource_id', $package)->pluck('action')->all();

    expect($actions)->toContain('PRESERVATION_CLOSE')->toContain('PRESERVATION_EXPORT')->toContain('PRESERVATION_VERIFY');
});

test('retention periods are tenant settings with documented unconfirmed defaults', function (): void {
    $settings = $this->getJson(PRESERVATION_BASE.'/preservation-settings')->assertOk()->json('data');

    expect($settings['confirmed_by_accountant'])->toBeFalse()
        ->and($settings['disclaimer'])->toContain('not verified')
        ->and(collect($settings['classes'])->pluck('source')->unique()->all())->toBe(['suggested_default'])
        ->and(collect($settings['classes'])->pluck('document_class')->all())->toBe(['issued_invoice', 'received_invoice', 'ledger_book', 'payslip', 'contract']);

    $saved = $this->putJson(PRESERVATION_BASE.'/preservation-settings', ['retention_years' => ['issued_invoice' => 12, 'payslip' => 6], 'confirmed_by_accountant' => true])->assertOk()->json('data');

    expect($saved['confirmed_by_accountant'])->toBeTrue()
        ->and(collect($saved['classes'])->firstWhere('document_class', 'issued_invoice'))->toMatchArray(['configured_years' => 12, 'effective_years' => 12, 'source' => 'tenant_setting'])
        ->and(collect($saved['classes'])->firstWhere('document_class', 'contract')['source'])->toBe('suggested_default');

    $this->putJson(PRESERVATION_BASE.'/preservation-settings', ['retention_years' => ['payslip' => 7]])->assertOk()->assertJsonPath('data.confirmed_by_accountant', false);

    ['package' => $package] = closedInvoicePackage($this->tenant);
    $closed = PreservationPackage::findOrFail($package);

    expect($closed->retention_years)->toBe(12)->and($closed->retention_source)->toBe('tenant_setting');

    $this->putJson(PRESERVATION_BASE.'/preservation-settings', ['retention_years' => ['issued_invoice' => 3]])->assertOk();
    expect($closed->fresh()->retention_years)->toBe(12);
});

test('settings reject unknown classes and invalid years, and only owners and administrators write them', function (): void {
    $this->putJson(PRESERVATION_BASE.'/preservation-settings', ['retention_years' => ['diary' => 5]])->assertUnprocessable();
    $this->putJson(PRESERVATION_BASE.'/preservation-settings', ['retention_years' => ['payslip' => 0]])->assertUnprocessable();

    preservationSwitchTo($this->tenant, 'accountant');
    $this->putJson(PRESERVATION_BASE.'/preservation-settings', ['retention_years' => ['payslip' => 6]])->assertForbidden();
    $this->getJson(PRESERVATION_BASE.'/preservation-settings')->assertForbidden();
    $this->postJson(PRESERVATION_BASE.'/preservation-packages', ['document_class' => 'payslip', 'name' => 'Cedolini'])->assertCreated();
});

test('the module permissions apply: members cannot read, read-only roles cannot write', function (): void {
    preservationSwitchTo($this->tenant, 'member');
    $this->getJson(PRESERVATION_BASE.'/preservation-packages')->assertForbidden();

    preservationSwitchTo($this->tenant, 'accountant_readonly');
    $this->getJson(PRESERVATION_BASE.'/preservation-packages')->assertOk();
    $this->postJson(PRESERVATION_BASE.'/preservation-packages', ['document_class' => 'payslip', 'name' => 'Cedolini'])->assertForbidden();
});

test('the package validates its class and period', function (): void {
    $this->postJson(PRESERVATION_BASE.'/preservation-packages', ['document_class' => 'diary', 'name' => 'x'])->assertUnprocessable();
    $this->postJson(PRESERVATION_BASE.'/preservation-packages', ['document_class' => 'payslip', 'name' => 'x', 'period_from' => '2027-05-01', 'period_to' => '2027-01-01'])->assertUnprocessable();
});

test('a time-stamp provider is recorded with its name and token, and a failing one keeps the package open', function (): void {
    $invoice = preservationInvoice($this->tenant);
    $package = preservationPackage();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $invoice->id])->assertCreated();

    $provider = new class implements TrustedTimestampProvider
    {
        public bool $failing = true;

        public string $token = '';

        public function name(): string
        {
            return 'fake-qtsp';
        }

        public function timestamp(string $sha256Hex): TimestampToken
        {
            if ($this->failing) {
                throw new DocumentTrustException('provider unreachable');
            }

            return new TimestampToken('fake-qtsp', $this->token, new DateTimeImmutable('2027-04-01T10:00:00+00:00'), true);
        }
    };
    $provider->token = base64_encode(Str::random(24));
    $this->app->instance(TrustedTimestampProvider::class, $provider);

    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/close")->assertUnprocessable();
    expect(PreservationPackage::findOrFail($package)->status)->toBe('open');

    $provider->failing = false;
    $closed = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/close")->assertOk()->json('data');

    expect($closed['timestamp_provider'])->toBe('fake-qtsp')->and($closed['timestamp_token'])->toBe($provider->token)->and($closed['timestamp_qualified'])->toBeTrue();
});

test('an open package can be emptied and deleted, which removes its files', function (): void {
    $invoice = preservationInvoice($this->tenant);
    $package = preservationPackage();
    $item = $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $invoice->id])->assertCreated()->json('data.id');
    $path = PreservationItem::findOrFail($item)->storage_path;

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->deleteJson(PRESERVATION_BASE."/preservation-packages/{$package}/items/{$item}")->assertOk();
    expect(Storage::disk('local')->exists($path))->toBeFalse();

    $this->deleteJson(PRESERVATION_BASE."/preservation-packages/{$package}")->assertOk();
    expect(PreservationPackage::find($package))->toBeNull();
});

test('another tenant sees none of the packages, files, logs or settings and cannot act on them', function (): void {
    ['package' => $package, 'invoice' => $invoice, 'item' => $item] = closedInvoicePackage($this->tenant);
    $this->putJson(PRESERVATION_BASE.'/preservation-settings', ['retention_years' => ['issued_invoice' => 15]])->assertOk();

    $beta = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta Srl', 'vat_number' => '00000000019', 'country' => 'IT', 'default_currency' => 'EUR']);
    preservationSwitchTo($beta);
    $ownPackage = preservationPackage();

    $this->getJson(PRESERVATION_BASE.'/preservation-packages')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownPackage);
    $this->getJson(PRESERVATION_BASE."/preservation-packages/{$package}")->assertNotFound();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/verify")->assertNotFound();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$package}/items", ['source_id' => $invoice->id])->assertNotFound();
    $this->getJson(PRESERVATION_BASE."/preservation-packages/{$package}/export")->assertNotFound();
    $this->deleteJson(PRESERVATION_BASE."/preservation-packages/{$package}/items/{$item->id}")->assertNotFound();
    $this->deleteJson(PRESERVATION_BASE."/preservation-packages/{$package}")->assertNotFound();
    $this->postJson(PRESERVATION_BASE."/preservation-packages/{$ownPackage}/items", ['source_id' => $invoice->id])->assertNotFound();
    $this->getJson(PRESERVATION_BASE.'/preservation-items')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(PRESERVATION_BASE.'/preservation-items?sha256='.$item->sha256)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(PRESERVATION_BASE."/preservation-access-logs?package_id={$package}")->assertOk()->assertJsonCount(0, 'data');

    $settings = $this->getJson(PRESERVATION_BASE.'/preservation-settings')->json('data.classes');
    expect(collect($settings)->firstWhere('document_class', 'issued_invoice')['source'])->toBe('suggested_default')
        ->and(PreservationAccessLog::where('package_id', $package)->exists())->toBeFalse();
});

test('the periodic job of one tenant never verifies the packages of another', function (): void {
    closedInvoicePackage($this->tenant);
    $beta = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta Srl', 'vat_number' => '00000000019', 'country' => 'IT', 'default_currency' => 'EUR']);

    (new VerifyPreservationPackagesJob((string) $beta->id))->handle(app(PreservationPackageService::class));

    expect(PreservationVerification::withoutGlobalScopes()->count())->toBe(0);
});
