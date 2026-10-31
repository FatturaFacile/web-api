<?php

declare(strict_types=1);

use App\Jobs\GenerateTenantBackupJob;
use App\Models\Contact;
use App\Models\EcommerceChannel;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Backup\TenantBackupService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
});

function tbApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

/**
 * Read an archive back from the private disk.
 *
 * @return array{data: array<string, mixed>, manifest: array<string, mixed>, names: array<int, string>, raw: string}
 */
function tbArchive(TenantBackup $backup): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk($backup->disk)->path($backup->storagePath())))->toBeTrue();

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $raw = (string) $zip->getFromName('data.json');
    $archive = ['data' => json_decode($raw, true), 'manifest' => json_decode((string) $zip->getFromName('manifest.json'), true), 'names' => $names, 'raw' => $raw];
    $zip->close();

    return $archive;
}

function tbBackup(?Tenant $tenant = null): TenantBackup
{
    $service = app(TenantBackupService::class);

    return $service->generate($service->create($tenant ?? test()->tenant, test()->user))->refresh();
}

// ---------------------------------------------------------------------------------------------------------------
// min_stock
// ---------------------------------------------------------------------------------------------------------------

test('min_stock is validated and stored when creating and updating products', function (): void {
    $payload = ['sku' => 'MS-1', 'name' => 'Widget', 'unit_measure' => 'pcs', 'cost_price' => 1, 'sale_price' => 2, 'tax_rate' => 22];

    $created = tbApi()->postJson('/api/v1/inventory/products', $payload + ['min_stock' => 5])->assertCreated();
    expect((float) $created->json('data.min_stock'))->toBe(5.0);

    tbApi()->postJson('/api/v1/inventory/products', ['sku' => 'MS-2'] + $payload + ['min_stock' => -1])->assertUnprocessable()->assertJsonValidationErrors(['min_stock']);
    tbApi()->postJson('/api/v1/inventory/products', ['sku' => 'MS-3'] + $payload + ['min_stock' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors(['min_stock']);
    expect(tbApi()->postJson('/api/v1/inventory/products', ['sku' => 'MS-4'] + $payload)->assertCreated()->json('data.min_stock'))->toBeNull();

    $id = $created->json('data.id');
    $updated = tbApi()->putJson("/api/v1/inventory/products/{$id}", ['min_stock' => 2.5, 'name' => 'Widget Pro'])->assertOk();
    expect((float) $updated->json('data.min_stock'))->toBe(2.5)->and($updated->json('data.name'))->toBe('Widget Pro')->and($updated->json('data.sku'))->toBe('MS-1');

    tbApi()->putJson("/api/v1/inventory/products/{$id}", ['min_stock' => -0.5])->assertUnprocessable();
    tbApi()->putJson("/api/v1/inventory/products/{$id}", ['sku' => 'MS-4'])->assertUnprocessable();
    expect(tbApi()->putJson("/api/v1/inventory/products/{$id}", ['min_stock' => null])->assertOk()->json('data.min_stock'))->toBeNull();

    $foreign = Product::create(['tenant_id' => $this->otherTenant->id, 'sku' => 'F-1', 'name' => 'Foreign', 'sale_price' => 1, 'tax_rate' => 0]);
    tbApi()->putJson("/api/v1/inventory/products/{$foreign->id}", ['min_stock' => 1])->assertNotFound();
});

test('min_stock is handled by the variant endpoints and inherited from the parent', function (): void {
    $parent = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'TSH', 'name' => 'T-shirt', 'sale_price' => 20, 'tax_rate' => 22, 'min_stock' => 4]);

    $inherited = tbApi()->postJson("/api/v1/inventory/products/{$parent->id}/variants", ['attributes' => ['Size' => 'S']])->assertCreated();
    expect($inherited->json('data.min_stock'))->toEqual(4);

    $explicit = tbApi()->postJson("/api/v1/inventory/products/{$parent->id}/variants", ['attributes' => ['Size' => 'M'], 'min_stock' => 1.5])->assertCreated();
    expect($explicit->json('data.min_stock'))->toEqual(1.5);

    tbApi()->postJson("/api/v1/inventory/products/{$parent->id}/variants", ['attributes' => ['Size' => 'L'], 'min_stock' => -2])->assertUnprocessable();

    $generated = tbApi()->postJson("/api/v1/inventory/products/{$parent->id}/variants/generate", ['attributes' => ['color' => ['Red', 'Blue']], 'min_stock' => 3])->assertCreated();
    expect($generated->json('data.created.*.min_stock'))->toEqual([3, 3]);

    $id = $explicit->json('data.id');
    expect(tbApi()->putJson("/api/v1/inventory/products/variants/{$id}", ['min_stock' => 9])->assertOk()->json('data.min_stock'))->toEqual(9)
        ->and(tbApi()->putJson("/api/v1/inventory/products/variants/{$id}", ['min_stock' => null])->assertOk()->json('data.min_stock'))->toBeNull();
    tbApi()->putJson("/api/v1/inventory/products/variants/{$id}", ['min_stock' => -1])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------
// Queueing and execution
// ---------------------------------------------------------------------------------------------------------------

test('a backup is queued as pending and built later by the job', function (): void {
    Queue::fake();

    $response = tbApi()->postJson('/api/v1/system/backups')->assertStatus(202);

    $id = $response->json('data.id');
    expect($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.download_url'))->toBeNull()
        ->and(TenantBackup::find($id)->status)->toBe('pending')
        ->and(TenantBackup::find($id)->user_id)->toBe($this->user->id)
        ->and(TenantBackup::find($id)->disk)->toBe('private');
    Queue::assertPushed(GenerateTenantBackupJob::class, fn (GenerateTenantBackupJob $job): bool => $job->backupId === $id);
    Storage::disk('private')->assertMissing(TenantBackup::find($id)->storagePath());

    tbApi()->getJson("/api/v1/system/backups/{$id}")->assertOk()->assertJsonPath('data.status', 'pending');

    (new GenerateTenantBackupJob($id))->handle(app(TenantBackupService::class));

    $backup = TenantBackup::find($id);
    expect($backup->status)->toBe('completed')
        ->and($backup->completed_at)->not->toBeNull()
        ->and($backup->file_size_bytes)->toBeGreaterThan(0);
    Storage::disk('private')->assertExists($backup->storagePath());
    expect(Storage::disk('private')->size($backup->storagePath()))->toBe($backup->file_size_bytes);

    // Running the job again does nothing: the backup is no longer pending.
    (new GenerateTenantBackupJob($id))->handle(app(TenantBackupService::class));
    expect(TenantBackup::find($id)->completed_at->equalTo($backup->completed_at))->toBeTrue();
});

test('with the queue running synchronously the backup completes right away', function (): void {
    $id = tbApi()->postJson('/api/v1/system/backups')->assertStatus(202)->json('data.id');

    $shown = tbApi()->getJson("/api/v1/system/backups/{$id}")->assertOk();
    expect($shown->json('data.status'))->toBe('completed')
        ->and($shown->json('data.file_size_bytes'))->toBeGreaterThan(0)
        ->and($shown->json('data.download_url'))->toEndWith("/api/v1/system/backups/{$id}/download");
});

test('a failing backup is recorded as failed and keeps the older ones', function (): void {
    $good = tbBackup();
    config(['backup.files_disk' => 'missing-disk']);

    $failed = tbBackup();

    expect($failed->status)->toBe('failed')
        ->and($failed->error_message)->not->toBeEmpty()
        ->and($failed->completed_at)->toBeNull()
        ->and($failed->file_size_bytes)->toBeNull();
    Storage::disk('private')->assertMissing($failed->storagePath());
    expect(TenantBackup::find($good->id)->status)->toBe('completed');

    $listed = tbApi()->getJson('/api/v1/system/backups')->assertOk()->json('data');
    expect(collect($listed)->pluck('status')->sort()->values()->all())->toBe(['completed', 'failed']);
});

// ---------------------------------------------------------------------------------------------------------------
// Archive content
// ---------------------------------------------------------------------------------------------------------------

test('the archive holds the data and files of the tenant and nothing from other tenants', function (): void {
    $customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi SRL', 'email' => 'rossi@example.com', 'type' => 'customer', 'country' => 'IT']);
    $stranger = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Stranger Ltd', 'email' => 'stranger@secret.test', 'type' => 'customer', 'country' => 'IT']);

    $invoice = Invoice::create(['tenant_id' => $this->tenant->id, 'contact_id' => $customer->id, 'invoice_number' => 'INV-1', 'date' => '2026-03-01', 'total_net' => 100, 'total_gross' => 122]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Own line item', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
    $foreignInvoice = Invoice::create(['tenant_id' => $this->otherTenant->id, 'contact_id' => $stranger->id, 'invoice_number' => 'INV-X', 'date' => '2026-03-01', 'total_net' => 999, 'total_gross' => 1218]);
    InvoiceItem::create(['invoice_id' => $foreignInvoice->id, 'description' => 'Foreign line item', 'quantity' => 1, 'unit_price' => 999, 'line_total' => 999]);

    $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'OWN-1', 'name' => 'Own product', 'sale_price' => 10, 'tax_rate' => 22, 'track_inventory' => true, 'min_stock' => 2]);
    Product::create(['tenant_id' => $this->otherTenant->id, 'sku' => 'FOREIGN-1', 'name' => 'Foreign product', 'sale_price' => 10, 'tax_rate' => 22]);
    tbApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $product->id, 'quantity' => 6])->assertCreated();
    tbApi()->putJson('/api/v1/settings/preferences/invoice', ['footer_text' => 'Own footer text'])->assertOk();

    EcommerceChannel::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Shop', 'driver' => 'woocommerce', 'api_url' => 'https://woo.example.com',
        'credentials' => ['consumer_key' => 'ck_super_secret', 'consumer_secret' => 'cs_super_secret'], 'webhook_secret' => 'hook-super-secret-value',
    ]);

    Storage::disk('local')->put("tenants/{$this->tenant->id}/attachments/own.txt", 'own file');
    Storage::disk('local')->put("tenants/{$this->tenant->id}/signatures/work-orders/sig.png", 'png-bytes');
    Storage::disk('local')->put("tenants/{$this->otherTenant->id}/attachments/foreign.txt", 'foreign file');
    Storage::disk('local')->put('unrelated/readme.txt', 'not a tenant file');

    $backup = tbBackup();
    $archive = tbArchive($backup);
    $tables = $archive['data']['tables'];

    expect($backup->status)->toBe('completed')
        ->and($archive['names'])->toContain('data.json', 'manifest.json', 'files/attachments/own.txt', 'files/signatures/work-orders/sig.png')
        ->and($archive['names'])->toHaveCount(4)
        ->and($archive['data']['tenant_id'])->toBe($this->tenant->id)
        ->and($archive['data']['tenant']['name'])->toBe('FatturaFacile Italy SRL')
        ->and($archive['data']['tenant']['settings']['preferences']['invoice']['footer_text'])->toBe('Own footer text')
        ->and(array_column($archive['data']['members'], 'email'))->toBe(['leo@fatturafacile.com'])
        ->and(array_column($tables['contacts'], 'email'))->toBe(['rossi@example.com'])
        ->and(array_column($tables['invoices'], 'invoice_number'))->toBe(['INV-1'])
        ->and(array_column($tables['invoice_items'], 'description'))->toBe(['Own line item'])
        ->and(array_column($tables['products'], 'sku'))->toBe(['OWN-1'])
        ->and($tables['products'][0]['min_stock'])->toEqual(2)
        ->and($tables['stock_movements'])->toHaveCount(1)
        ->and($tables['stock_movements'][0]['product_id'])->toBe($product->id)
        ->and($archive['manifest']['tables']['contacts'])->toBe(1)
        ->and($archive['manifest']['tables']['invoice_items'])->toBe(1)
        ->and($archive['manifest']['files'])->toEqualCanonicalizing(['files/attachments/own.txt', 'files/signatures/work-orders/sig.png']);

    foreach (['stranger@secret.test', 'Stranger Ltd', 'INV-X', 'Foreign line item', 'FOREIGN-1', $this->otherTenant->id, 'Other SRL'] as $leak) {
        expect($archive['raw'])->not->toContain($leak);
    }

    // Secrets and credentials never go into the archive.
    foreach (['ck_super_secret', 'cs_super_secret', 'hook-super-secret-value', 'secret123'] as $secret) {
        expect($archive['raw'])->not->toContain($secret);
    }
    expect($tables['ecommerce_channels'][0]['credentials'])->toBeNull()->and($tables['ecommerce_channels'][0]['webhook_secret'])->toBeNull();

    // Backups are not included in later backups.
    expect($tables)->not->toHaveKey('tenant_backups');
});

// ---------------------------------------------------------------------------------------------------------------
// Retention
// ---------------------------------------------------------------------------------------------------------------

test('only the seven most recent completed backups of a tenant are kept', function (): void {
    $foreign = tbBackup($this->otherTenant);
    $created = [];

    for ($i = 1; $i <= 8; $i++) {
        $this->travelTo(now()->addMinutes(5));
        $created[] = tbBackup();
    }

    $first = $created[0];
    $kept = TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->orderByDesc('completed_at')->get();

    expect($kept)->toHaveCount(7)
        ->and($kept->pluck('id')->all())->toBe(collect($created)->slice(1)->reverse()->pluck('id')->values()->all())
        ->and(TenantBackup::withoutGlobalScopes()->find($first->id))->toBeNull();

    Storage::disk('private')->assertMissing($first->storagePath());
    foreach ($kept as $backup) {
        Storage::disk('private')->assertExists($backup->storagePath());
    }
    expect(Storage::disk('private')->allFiles("backups/{$this->tenant->id}"))->toHaveCount(7);

    // Another tenant is never touched by this tenant's retention.
    Storage::disk('private')->assertExists($foreign->storagePath());
    expect(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(1);
});

test('retention ignores failed and pending backups and the rolling window follows completion order', function (): void {
    $service = app(TenantBackupService::class);
    $pending = $service->create($this->tenant, $this->user);

    config(['backup.files_disk' => 'missing-disk']);
    $failed = tbBackup();
    config(['backup.files_disk' => 'local']);

    foreach (range(1, 7) as $i) {
        $this->travelTo(now()->addMinutes(5));
        tbBackup();
    }

    expect(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('status', 'completed')->count())->toBe(7)
        ->and(TenantBackup::withoutGlobalScopes()->find($pending->id))->not->toBeNull()
        ->and(TenantBackup::withoutGlobalScopes()->find($failed->id))->not->toBeNull();

    $this->travelTo(now()->addMinutes(5));
    tbBackup();

    expect(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('status', 'completed')->count())->toBe(7)
        ->and(TenantBackup::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(9);
});

// ---------------------------------------------------------------------------------------------------------------
// Download, deletion and isolation
// ---------------------------------------------------------------------------------------------------------------

test('a completed backup is downloaded as a zip file', function (): void {
    Storage::disk('local')->put("tenants/{$this->tenant->id}/attachments/own.txt", 'own file');
    $backup = tbBackup();

    $response = tbApi()->get("/api/v1/system/backups/{$backup->id}/download")->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('backup-20260315-100000.zip')
        ->and(str_starts_with($response->streamedContent(), 'PK'))->toBeTrue();
});

test('a backup that is not completed or whose file is gone cannot be downloaded', function (): void {
    $pending = app(TenantBackupService::class)->create($this->tenant, $this->user);
    tbApi()->get("/api/v1/system/backups/{$pending->id}/download")->assertStatus(409);

    config(['backup.files_disk' => 'missing-disk']);
    $failed = tbBackup();
    tbApi()->get("/api/v1/system/backups/{$failed->id}/download")->assertStatus(409);
    config(['backup.files_disk' => 'local']);

    $completed = tbBackup();
    Storage::disk('private')->delete($completed->storagePath());
    tbApi()->get("/api/v1/system/backups/{$completed->id}/download")->assertNotFound();
});

test('deleting a backup removes its archive and record', function (): void {
    $backup = tbBackup();
    Storage::disk('private')->assertExists($backup->storagePath());

    tbApi()->deleteJson("/api/v1/system/backups/{$backup->id}")->assertOk();

    expect(TenantBackup::find($backup->id))->toBeNull();
    Storage::disk('private')->assertMissing($backup->storagePath());
    tbApi()->deleteJson("/api/v1/system/backups/{$backup->id}")->assertNotFound();

    $running = app(TenantBackupService::class)->create($this->tenant, $this->user);
    $running->update(['status' => 'processing']);
    tbApi()->deleteJson("/api/v1/system/backups/{$running->id}")->assertStatus(409);
    expect(TenantBackup::find($running->id))->not->toBeNull();
});

test('backups are listed newest first and are invisible to other tenants', function (): void {
    $older = tbBackup();
    $this->travelTo(now()->addHour());
    $newer = tbBackup();
    $foreign = tbBackup($this->otherTenant);

    expect(tbApi()->getJson('/api/v1/system/backups')->assertOk()->json('data.*.id'))->toBe([$newer->id, $older->id]);

    tbApi()->getJson("/api/v1/system/backups/{$foreign->id}")->assertNotFound();
    tbApi()->get("/api/v1/system/backups/{$foreign->id}/download")->assertNotFound();
    tbApi()->deleteJson("/api/v1/system/backups/{$foreign->id}")->assertNotFound();
    Storage::disk('private')->assertExists($foreign->storagePath());

    // The same user sees the foreign backup from the other tenant, but not the first tenant's ones.
    expect(tbApi($this->otherTenant->id)->getJson('/api/v1/system/backups')->json('data.*.id'))->toBe([$foreign->id]);
    tbApi($this->otherTenant->id)->getJson("/api/v1/system/backups/{$older->id}")->assertNotFound();
    tbApi($this->otherTenant->id)->get("/api/v1/system/backups/{$foreign->id}/download")->assertOk();
});
