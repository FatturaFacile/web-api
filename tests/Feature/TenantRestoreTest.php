<?php

declare(strict_types=1);

use App\Exceptions\InvalidBackupArchiveException;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\EcommerceChannel;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\User;
use App\Services\Backup\TenantBackupService;
use App\Services\Backup\TenantRestoreService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('private');
    Storage::fake('local');
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);
});

function trApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

/**
 * Populate a tenant with related records and private files, returning the interesting ones.
 *
 * @return array<string, mixed>
 */
function trSeed(Tenant $tenant, string $label): array
{
    $contact = Contact::create(['tenant_id' => $tenant->id, 'company_name' => "{$label} Customer", 'email' => strtolower($label).'@example.com', 'type' => 'customer', 'country' => 'IT']);
    Contact::create(['tenant_id' => $tenant->id, 'company_name' => "{$label} Supplier", 'email' => strtolower($label).'-supplier@example.com', 'type' => 'supplier', 'country' => 'IT']);

    $parent = Product::create(['tenant_id' => $tenant->id, 'sku' => "{$label}-TSH", 'name' => "{$label} T-shirt", 'sale_price' => 20, 'tax_rate' => 22]);
    $variant = Product::create(['tenant_id' => $tenant->id, 'sku' => "{$label}-TSH-M", 'name' => "{$label} T-shirt M", 'sale_price' => 20, 'tax_rate' => 22, 'parent_id' => $parent->id, 'is_variant' => true, 'attributes' => ['Size' => 'M']]);

    $invoice = Invoice::create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'invoice_number' => "{$label}-INV-1", 'date' => '2026-03-01', 'currency' => 'EUR', 'total_net' => 100, 'total_tax' => 22, 'total_gross' => 122, 'payment_status' => 'paid', 'total_paid' => 122]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $variant->id, 'description' => "{$label} line", 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
    // A payment whose invoice totals were never updated: the restore must recalculate them.
    $payment = Payment::create(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'paid_at' => '2026-03-02']);

    $statement = BankStatement::create(['tenant_id' => $tenant->id, 'source_format' => 'camt053', 'account_iban' => 'IT60X0542811101000000123456', 'statement_date' => '2026-03-10', 'currency' => 'EUR', 'total_entries' => 1, 'status' => 'draft']);
    BankStatementLine::create(['tenant_id' => $tenant->id, 'bank_statement_id' => $statement->id, 'line_number' => 1, 'booking_date' => '2026-03-10', 'value_date' => '2026-03-10', 'amount' => 10, 'direction' => 'debit', 'description' => "{$label} line", 'reconciled' => true, 'reconciliation_type' => 'manual_categorization', 'reconciliation_category' => 'generic_expense']);

    $channel = EcommerceChannel::create(['tenant_id' => $tenant->id, 'name' => "{$label} shop", 'driver' => 'woocommerce', 'api_url' => 'https://woo.example.com', 'credentials' => ['consumer_key' => 'ck_original', 'consumer_secret' => 'cs_original'], 'webhook_secret' => 'original-secret-value', 'is_active' => true]);

    Storage::disk('local')->put("tenants/{$tenant->id}/attachments/{$label}.txt", "{$label} attachment");
    Storage::disk('local')->put("tenants/{$tenant->id}/signatures/work-orders/{$label}.png", "{$label} signature");

    return compact('contact', 'parent', 'variant', 'invoice', 'payment', 'statement', 'channel');
}

function trBackup(Tenant $tenant): TenantBackup
{
    $service = app(TenantBackupService::class);

    return $service->generate($service->create($tenant, test()->user))->refresh();
}

/**
 * A copy of the archive of a backup, changed by `$change(ZipArchive)`, as an uploaded file.
 */
function trTamperedUpload(TenantBackup $backup, Closure $change): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'tampered-').'.zip';
    copy(Storage::disk($backup->disk)->path($backup->storagePath()), $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $change($zip);
    $zip->close();

    return new UploadedFile($path, 'backup.zip', 'application/zip', null, true);
}

function trManifest(TenantBackup $backup): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk($backup->disk)->path($backup->storagePath()));
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();

    return $manifest;
}

function trRestoreUpload(UploadedFile $file, ?string $tenantId = null)
{
    return trApi($tenantId)->post('/api/v1/system/backups/restore-upload', ['file' => $file], ['Accept' => 'application/json']);
}

// ---------------------------------------------------------------------------------------------------------------
// Restore
// ---------------------------------------------------------------------------------------------------------------

test('a restore brings back records, relations and private files exactly as they were', function (): void {
    $own = trSeed($this->tenant, 'Alpha');
    $foreign = trSeed($this->otherTenant, 'Beta');
    $backup = trBackup($this->tenant);

    // Damage the tenant: change, delete and add records and files.
    Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->delete();
    Product::withoutGlobalScopes()->where('id', $own['parent']->id)->update(['name' => 'Renamed']);
    Product::withoutGlobalScopes()->whereKey($own['variant']->id)->forceDelete();
    Payment::withoutGlobalScopes()->where('invoice_id', $own['invoice']->id)->delete();
    DB::table('invoice_items')->where('invoice_id', $own['invoice']->id)->delete();
    Invoice::withoutGlobalScopes()->whereKey($own['invoice']->id)->forceDelete();
    Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Added after the backup', 'email' => 'late@example.com', 'type' => 'customer', 'country' => 'IT']);
    Storage::disk('local')->delete("tenants/{$this->tenant->id}/attachments/Alpha.txt");
    Storage::disk('local')->put("tenants/{$this->tenant->id}/attachments/new.txt", 'created after the backup');
    EcommerceChannel::withoutGlobalScopes()->whereKey($own['channel']->id)->update(['is_active' => false]);

    $summary = app(TenantRestoreService::class)->restoreBackup($backup, $this->tenant);

    expect($summary['rows'])->toBeGreaterThan(8)->and($summary['files'])->toBe(2)->and($summary['skipped_rows'])->toBe(0);

    $contacts = Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('email')->sort()->values()->all();
    expect($contacts)->toBe(['alpha-supplier@example.com', 'alpha@example.com']);

    $parent = Product::withoutGlobalScopes()->find($own['parent']->id);
    $variant = Product::withoutGlobalScopes()->find($own['variant']->id);
    expect($parent->name)->toBe('Alpha T-shirt')
        ->and($variant->parent_id)->toBe($parent->id)
        ->and($variant->is_variant)->toBeTrue()
        ->and($variant->attributes)->toBe(['Size' => 'M']);

    $invoice = Invoice::withoutGlobalScopes()->find($own['invoice']->id);
    expect($invoice->invoice_number)->toBe('Alpha-INV-1')
        ->and(DB::table('invoice_items')->where('invoice_id', $invoice->id)->pluck('product_id')->all())->toBe([$variant->id])
        ->and(Payment::withoutGlobalScopes()->find($own['payment']->id)->invoice_id)->toBe($invoice->id)
        ->and(BankStatementLine::withoutGlobalScopes()->where('bank_statement_id', $own['statement']->id)->count())->toBe(1);

    expect(Storage::disk('local')->get("tenants/{$this->tenant->id}/attachments/Alpha.txt"))->toBe('Alpha attachment')
        ->and(Storage::disk('local')->get("tenants/{$this->tenant->id}/signatures/work-orders/Alpha.png"))->toBe('Alpha signature');
    Storage::disk('local')->assertMissing("tenants/{$this->tenant->id}/attachments/new.txt");
    expect(Storage::disk('local')->allFiles('restore-staging'))->toBe([]);

    // The other tenant, its files and the backups themselves are untouched.
    expect(Contact::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(2)
        ->and(Invoice::withoutGlobalScopes()->find($foreign['invoice']->id))->not->toBeNull()
        ->and(Storage::disk('local')->get("tenants/{$this->otherTenant->id}/attachments/Beta.txt"))->toBe('Beta attachment')
        ->and(TenantBackup::withoutGlobalScopes()->find($backup->id))->not->toBeNull()
        ->and(Tenant::find($this->tenant->id)->is_maintenance)->toBeFalse()
        ->and($this->user->tenants()->count())->toBe(2);
});

test('derived state is recalculated after a restore', function (): void {
    $own = trSeed($this->tenant, 'Alpha');
    $backup = trBackup($this->tenant);

    // The seeded invoice claims 122.00 paid and "paid", but only 100.00 of payments exist.
    expect((float) Invoice::withoutGlobalScopes()->find($own['invoice']->id)->total_paid)->toBe(122.0);

    $summary = app(TenantRestoreService::class)->restoreBackup($backup, $this->tenant);

    $invoice = Invoice::withoutGlobalScopes()->find($own['invoice']->id);
    expect($summary['recalculated'])->toBe(1)
        ->and((float) $invoice->total_paid)->toBe(100.0)
        ->and($invoice->payment_status)->toBe('partially_paid')
        ->and(BankStatement::withoutGlobalScopes()->find($own['statement']->id)->status)->toBe('reconciled');
});

test('secrets survive for existing channels and restored channels without credentials are switched off', function (): void {
    $own = trSeed($this->tenant, 'Alpha');
    $backup = trBackup($this->tenant);

    // This channel stays; its credentials changed after the backup and must be kept.
    $channel = EcommerceChannel::withoutGlobalScopes()->find($own['channel']->id);
    $channel->update(['credentials' => ['consumer_key' => 'ck_rotated', 'consumer_secret' => 'cs_rotated'], 'webhook_secret' => 'rotated-secret-value']);

    // This one is created later with the same data... and a second one that is deleted before the restore.
    $gone = EcommerceChannel::create(['tenant_id' => $this->tenant->id, 'name' => 'Gone shop', 'driver' => 'shopify', 'credentials' => ['access_token' => 'x'], 'webhook_secret' => 'gone-secret-value']);
    $backup2 = trBackup($this->tenant);
    EcommerceChannel::withoutGlobalScopes()->whereKey($gone->id)->delete();

    app(TenantRestoreService::class)->restoreBackup($backup2, $this->tenant);

    $kept = EcommerceChannel::withoutGlobalScopes()->find($own['channel']->id);
    expect($kept->credentials)->toBe(['consumer_key' => 'ck_rotated', 'consumer_secret' => 'cs_rotated'])
        ->and($kept->webhook_secret)->toBe('rotated-secret-value')
        ->and($kept->is_active)->toBeTrue();

    $restored = EcommerceChannel::withoutGlobalScopes()->find($gone->id);
    expect($restored)->not->toBeNull()
        ->and($restored->credentials)->toBe([])
        ->and($restored->is_active)->toBeFalse();

    expect($backup->refresh()->status)->toBe('completed');
});

test('the restore endpoints restore a stored backup and an uploaded archive', function (): void {
    $own = trSeed($this->tenant, 'Alpha');
    $backup = trBackup($this->tenant);

    Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->delete();
    $response = trApi()->postJson("/api/v1/system/backups/{$backup->id}/restore")->assertOk();

    expect($response->json('data.rows'))->toBeGreaterThan(8)
        ->and(Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(2);

    Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->delete();
    $archive = new UploadedFile(Storage::disk($backup->disk)->path($backup->storagePath()), 'external.zip', 'application/zip', null, true);
    trRestoreUpload($archive)->assertOk()->assertJsonPath('data.files', 2);
    expect(Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(Invoice::withoutGlobalScopes()->find($own['invoice']->id))->not->toBeNull();

    // Only completed backups can be restored.
    $pending = app(TenantBackupService::class)->create($this->tenant, $this->user);
    trApi()->postJson("/api/v1/system/backups/{$pending->id}/restore")->assertStatus(409);
    trApi()->postJson('/api/v1/system/backups/'.Str::uuid().'/restore')->assertNotFound();

    // A backup of another tenant is invisible, and uploads must be ZIP files.
    $foreign = trBackup($this->otherTenant);
    trApi()->postJson("/api/v1/system/backups/{$foreign->id}/restore")->assertNotFound();
    trApi()->post('/api/v1/system/backups/restore-upload', [], ['Accept' => 'application/json'])->assertUnprocessable();
    trApi()->post('/api/v1/system/backups/restore-upload', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')], ['Accept' => 'application/json'])->assertUnprocessable();
    trRestoreUpload(UploadedFile::fake()->createWithContent('fake.zip', 'this is not a zip archive'))->assertUnprocessable()->assertJsonPath('title', 'Backup restore');
});

test('an archive of another tenant is refused and nothing changes', function (): void {
    trSeed($this->tenant, 'Alpha');
    trSeed($this->otherTenant, 'Beta');
    $foreign = trBackup($this->otherTenant);
    $before = Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('id')->sort()->values()->all();

    $archive = new UploadedFile(Storage::disk($foreign->disk)->path($foreign->storagePath()), 'beta.zip', 'application/zip', null, true);
    trRestoreUpload($archive)->assertUnprocessable()->assertJsonPath('detail', 'The archive belongs to another organization.');

    expect(Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->pluck('id')->sort()->values()->all())->toBe($before)
        ->and(Contact::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(2)
        ->and(Tenant::find($this->tenant->id)->is_maintenance)->toBeFalse();

    expect(fn () => app(TenantRestoreService::class)->restoreBackup($foreign, $this->tenant))->toThrow(InvalidBackupArchiveException::class, 'belongs to another organization');
});

test('missing, incomplete or altered manifests and data are refused before anything is touched', function (): void {
    trSeed($this->tenant, 'Alpha');
    $backup = trBackup($this->tenant);
    $before = Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count();
    $manifest = trManifest($backup);

    $rewrite = fn (array $changes) => fn (ZipArchive $zip) => $zip->addFromString('manifest.json', (string) json_encode(array_merge($manifest, $changes)));

    $cases = [
        'no manifest' => [fn (ZipArchive $zip) => $zip->deleteName('manifest.json'), 'no manifest.json'],
        'not json' => [fn (ZipArchive $zip) => $zip->addFromString('manifest.json', '{not json'), 'incomplete or has been altered'],
        'no checksum' => [fn (ZipArchive $zip) => $zip->addFromString('manifest.json', (string) json_encode(Arr::except($manifest, ['data_sha256']))), 'incomplete or has been altered'],
        'no tables' => [fn (ZipArchive $zip) => $zip->addFromString('manifest.json', (string) json_encode(Arr::except($manifest, ['tables']))), 'incomplete or has been altered'],
        'other tenant' => [$rewrite(['tenant_id' => (string) $this->otherTenant->id]), 'another organization'],
        'wrong checksum' => [$rewrite(['data_sha256' => str_repeat('a', 64)]), 'checksum'],
        'altered data' => [fn (ZipArchive $zip) => $zip->addFromString('data.json', str_replace('Alpha Customer', 'Hacked Customer', (string) $zip->getFromName('data.json'))), 'checksum'],
        'no data' => [fn (ZipArchive $zip) => $zip->deleteName('data.json'), 'no data.json'],
        'path traversal' => [$rewrite(['files' => ['files/../../escape.txt']]), 'unsafe file path'],
        'absolute path' => [$rewrite(['files' => ['/etc/passwd']]), 'unsafe file path'],
        'listed file missing' => [$rewrite(['files' => array_merge($manifest['files'], ['files/attachments/ghost.txt'])]), 'missing the file'],
        'row counts' => [$rewrite(['tables' => array_merge($manifest['tables'], ['contacts' => 99])]), 'does not match the manifest'],
        'table unknown' => [$rewrite(['tables' => array_merge($manifest['tables'], ['nope' => 0])]), 'does not match the manifest'],
    ];

    foreach ($cases as $name => [$change, $message]) {
        trRestoreUpload(trTamperedUpload($backup, $change))->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, $message) || throw new RuntimeException("{$name}: {$detail}"));
    }

    expect(Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe($before)
        ->and(Storage::disk('local')->get("tenants/{$this->tenant->id}/attachments/Alpha.txt"))->toBe('Alpha attachment');
});

test('an archive naming a table that does not exist is refused', function (): void {
    trSeed($this->tenant, 'Alpha');
    $backup = trBackup($this->tenant);
    $manifest = trManifest($backup);

    $upload = trTamperedUpload($backup, function (ZipArchive $zip) use ($manifest): void {
        $data = (string) $zip->getFromName('data.json');
        $data = str_replace("\"contacts\":[\n", "\"unknown_things\":[\n{\"id\":\"1\"}\n]\n,\"contacts\":[\n", $data);
        $zip->addFromString('data.json', $data);
        $zip->addFromString('manifest.json', (string) json_encode(array_merge($manifest, ['data_sha256' => hash('sha256', $data)])));
    });

    trRestoreUpload($upload)->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'unknown table (unknown_things)'));
});

test('a failure while restoring rolls everything back', function (): void {
    trSeed($this->tenant, 'Alpha');
    $backup = trBackup($this->tenant);
    $manifest = trManifest($backup);

    // A row that breaks a NOT NULL constraint, with a consistent checksum, fails in the middle of the load.
    $upload = trTamperedUpload($backup, function (ZipArchive $zip) use ($manifest): void {
        $data = (string) $zip->getFromName('data.json');
        $data = str_replace('"type":"customer"', '"type":null', $data);
        $zip->addFromString('data.json', $data);
        $zip->addFromString('manifest.json', (string) json_encode(array_merge($manifest, ['data_sha256' => hash('sha256', $data)])));
    });

    Contact::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('email', 'alpha-supplier@example.com')->update(['company_name' => 'Edited since the backup']);
    Storage::disk('local')->put("tenants/{$this->tenant->id}/attachments/keep.txt", 'must survive');

    expect(fn () => app(TenantRestoreService::class)->restoreArchive($upload->getRealPath(), $this->tenant))->toThrow(QueryException::class);

    expect(Contact::withoutGlobalScopes()->where('email', 'alpha-supplier@example.com')->value('company_name'))->toBe('Edited since the backup')
        ->and(Storage::disk('local')->get("tenants/{$this->tenant->id}/attachments/keep.txt"))->toBe('must survive')
        ->and(Storage::disk('local')->allFiles('restore-staging'))->toBe([])
        ->and(Tenant::find($this->tenant->id)->is_maintenance)->toBeFalse();
});

test('writes are rejected while a tenant is in maintenance but reads still work', function (): void {
    Tenant::query()->whereKey($this->tenant->id)->update(['is_maintenance' => true]);

    trApi()->getJson('/api/v1/system/backups')->assertOk();
    trApi()->postJson('/api/v1/system/backups')->assertStatus(503)->assertHeader('Retry-After', '60')->assertJsonPath('title', 'Tenant under maintenance');
    trApi($this->otherTenant->id)->postJson('/api/v1/system/backups')->assertStatus(202);

    Tenant::query()->whereKey($this->tenant->id)->update(['is_maintenance' => false]);
    trApi()->postJson('/api/v1/system/backups')->assertStatus(202);
});

// ---------------------------------------------------------------------------------------------------------------
// Scheduled backups
// ---------------------------------------------------------------------------------------------------------------

test('tenants:backup --all queues a backup for every tenant and keeps the 7 most recent', function (): void {
    for ($i = 0; $i < 8; $i++) {
        $this->travelTo(now()->addDay());
        $this->artisan('tenants:backup', ['--all' => true])->assertExitCode(0)->expectsOutputToContain('Backup queued for 2 tenant(s).');
    }

    foreach ([$this->tenant, $this->otherTenant] as $tenant) {
        $backups = TenantBackup::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderByDesc('completed_at')->get();
        expect($backups)->toHaveCount(7)
            ->and($backups->every(fn (TenantBackup $backup): bool => $backup->status === 'completed'))->toBeTrue()
            ->and(Storage::disk('private')->allFiles("backups/{$tenant->id}"))->toHaveCount(7);
    }
});

test('tenants:backup --tenant backs up one tenant and rejects invalid options', function (): void {
    $this->artisan('tenants:backup', ['--tenant' => $this->tenant->id])->assertExitCode(0)->expectsOutputToContain('Backup queued for 1 tenant(s).');

    expect(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(0);

    $this->artisan('tenants:backup')->assertExitCode(2)->expectsOutputToContain('exactly one of --tenant or --all');
    $this->artisan('tenants:backup', ['--tenant' => $this->tenant->id, '--all' => true])->assertExitCode(2);
    $this->artisan('tenants:backup', ['--tenant' => (string) Str::uuid()])->assertExitCode(1);

    Tenant::query()->whereKey($this->otherTenant->id)->delete();
    $this->artisan('tenants:backup', ['--all' => true])->assertExitCode(0)->expectsOutputToContain('Backup queued for 1 tenant(s).');
});

test('the nightly backup is scheduled at 02:00', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'tenants:backup'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->command)->toContain('--all')
        ->and($event->withoutOverlapping)->toBeTrue();
});
