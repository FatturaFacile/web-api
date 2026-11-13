<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Jobs\ApplyDocumentRetentionJob;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\Documents\Models\DmsFolder;
use Modules\Documents\Models\DmsRetentionPolicy;
use Modules\Documents\Services\DocumentRetention;

uses(RefreshDatabase::class);

function dmsMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function dmsActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function dmsUpload(string $title = 'Contract', string $content = 'first content', array $extra = []): string
{
    return test()->post('/api/v1/dms/documents', [
        'title' => $title,
        'file' => UploadedFile::fake()->createWithContent('contract.txt', $content),
        ...$extra,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->admin = dmsMember($this->tenant, 'admin');
    dmsActAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a document stores its file on the private disk with a sha-256 hash', function (): void {
    $id = dmsUpload('Lease', 'lease terms');

    $response = $this->getJson("/api/v1/dms/documents/{$id}")->assertOk();
    $version = $response->json('data.versions.0');

    expect($version['sha256'])->toBe(hash('sha256', 'lease terms'))
        ->and($version['version_number'])->toBe(1)
        ->and($version)->not->toHaveKey('path');

    Storage::disk('local')->assertExists(DmsDocumentVersion::query()->firstOrFail()->path);
});

test('versions keep the earlier files and refuse the same file twice in a row', function (): void {
    $id = dmsUpload('Lease', 'v1');

    $this->post("/api/v1/dms/documents/{$id}/versions", ['file' => UploadedFile::fake()->createWithContent('b.txt', 'v2')], ['Accept' => 'application/json'])->assertCreated();
    $this->post("/api/v1/dms/documents/{$id}/versions", ['file' => UploadedFile::fake()->createWithContent('c.txt', 'v2')], ['Accept' => 'application/json'])->assertConflict();

    $versions = $this->getJson("/api/v1/dms/documents/{$id}/versions")->assertOk()->json('data');

    expect($versions)->toHaveCount(2)
        ->and($versions[0]['version_number'])->toBe(2)
        ->and(DmsDocument::query()->findOrFail($id)->current_version)->toBe(2);
});

test('verification detects a file changed on disk', function (): void {
    $id = dmsUpload('Lease', 'original');
    $version = DmsDocumentVersion::query()->firstOrFail();

    $this->getJson("/api/v1/dms/documents/{$id}/versions/{$version->id}/verify")->assertOk()->assertJsonPath('data.is_intact', true);

    Storage::disk('local')->put($version->path, 'tampered');

    $this->getJson("/api/v1/dms/documents/{$id}/versions/{$version->id}/verify")->assertOk()->assertJsonPath('data.is_intact', false);
});

test('the same file is refused as a duplicate unless allowed, and listed by hash', function (): void {
    $first = dmsUpload('A', 'same bytes');

    $refused = $this->post('/api/v1/dms/documents', ['title' => 'B', 'file' => UploadedFile::fake()->createWithContent('b.txt', 'same bytes')], ['Accept' => 'application/json']);
    $refused->assertConflict()->assertJsonPath('invalid_params.existing_document_id', $first);

    $second = dmsUpload('B', 'same bytes', ['allow_duplicate' => '1']);

    $groups = $this->getJson('/api/v1/dms/documents/duplicates')->assertOk()->json('data');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['document_ids'])->toEqualCanonicalizing([$first, $second]);
});

test('full-text search covers title, tags, file name and metadata', function (): void {
    $tag = $this->postJson('/api/v1/dms/tags', ['name' => 'Fornitori'])->assertCreated()->json('data.id');
    $id = dmsUpload('Polizza annuale', 'x1', ['tag_ids' => [$tag], 'metadata' => ['broker' => 'Zurigo']]);
    dmsUpload('Altro documento', 'x2');

    foreach (['polizza', 'fornitori', 'zurigo'] as $term) {
        expect(collect($this->getJson("/api/v1/dms/documents?q={$term}")->assertOk()->json('data'))->pluck('id')->all())->toBe([$id]);
    }

    expect($this->getJson('/api/v1/dms/documents?q=contract')->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/v1/dms/documents?q=inesistente')->json('data'))->toBe([]);
    expect($this->getJson('/api/v1/dms/documents?tag=fornitori')->json('data'))->toHaveCount(1);
});

test('a search term is bound, not interpreted', function (): void {
    dmsUpload('Polizza', 'x1');

    $this->getJson('/api/v1/dms/documents?q='.urlencode("'; DROP TABLE dms_documents; --"))->assertOk();

    expect(DmsDocument::query()->count())->toBe(1);
});

test('an attachment comes in by reference, once, without touching the file', function (): void {
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    Storage::disk('local')->put('tenants/a/attachments/invoice.txt', 'attached');
    $attachment = Attachment::create([
        'tenant_id' => $this->tenant->id,
        'attachable_type' => $contact->getMorphClass(),
        'attachable_id' => $contact->id,
        'original_name' => 'invoice.txt',
        'disk' => 'local',
        'path' => 'tenants/a/attachments/invoice.txt',
        'mime_type' => 'text/plain',
        'size' => 8,
    ]);

    $id = $this->postJson('/api/v1/dms/documents/import-attachment', ['attachment_id' => $attachment->id])->assertCreated()->json('data.id');
    $again = $this->postJson('/api/v1/dms/documents/import-attachment', ['attachment_id' => $attachment->id])->assertCreated()->json('data.id');

    $version = DmsDocumentVersion::query()->firstOrFail();

    expect($again)->toBe($id)
        ->and(DmsDocumentVersion::query()->count())->toBe(1)
        ->and($version->path)->toBe('tenants/a/attachments/invoice.txt')
        ->and($version->sha256)->toBe(hash('sha256', 'attached'))
        ->and(Storage::disk('local')->allFiles())->toBe(['tenants/a/attachments/invoice.txt']);

    $this->getJson("/api/v1/dms/documents?linkable_type=contact&linkable_id={$contact->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/attachments/{$attachment->id}")->assertConflict();
    Storage::disk('local')->assertExists('tenants/a/attachments/invoice.txt');
});

test('a document links to a record of the tenant, never of another one', function (): void {
    $id = dmsUpload();
    $own = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreign = Contact::factory()->create(['tenant_id' => $this->other->id]);

    $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'contact', 'linkable_id' => $own->id])->assertCreated();
    $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'contact', 'linkable_id' => $foreign->id])->assertUnprocessable();
    $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'unknown', 'linkable_id' => $own->id])->assertUnprocessable();
});

test('legal hold freezes a document until an administrator releases it', function (): void {
    $id = dmsUpload();

    $this->postJson("/api/v1/dms/documents/{$id}/legal-hold", ['reason' => 'Litigation 12/2026'])->assertOk()->assertJsonPath('data.legal_hold', true);

    $this->putJson("/api/v1/dms/documents/{$id}", ['title' => 'Changed'])->assertConflict();
    $this->deleteJson("/api/v1/dms/documents/{$id}")->assertConflict();
    $this->post("/api/v1/dms/documents/{$id}/versions", ['file' => UploadedFile::fake()->createWithContent('b.txt', 'v2')], ['Accept' => 'application/json'])->assertConflict();

    $member = dmsMember($this->tenant, 'member');
    dmsActAs($member, $this->tenant);
    $this->deleteJson("/api/v1/dms/documents/{$id}/legal-hold")->assertForbidden();

    dmsActAs($this->admin, $this->tenant);
    $this->deleteJson("/api/v1/dms/documents/{$id}/legal-hold")->assertOk()->assertJsonPath('data.legal_hold', false);
    $this->deleteJson("/api/v1/dms/documents/{$id}")->assertOk();
});

test('a role outside the access list gets 404, and a restricted folder hides its subtree', function (): void {
    $restricted = dmsUpload('Payroll', 'p1', ['access_roles' => ['hr']]);
    $open = dmsUpload('Open', 'p2');

    $parent = $this->postJson('/api/v1/dms/folders', ['name' => 'Board', 'access_roles' => ['admin']])->assertCreated()->json('data.id');
    $child = $this->postJson('/api/v1/dms/folders', ['name' => 'Minutes', 'parent_id' => $parent])->assertCreated()->json('data.id');
    $inChild = dmsUpload('Minutes 1', 'p3', ['folder_id' => $child]);

    dmsActAs(dmsMember($this->tenant, 'member'), $this->tenant);

    $this->getJson("/api/v1/dms/documents/{$restricted}")->assertNotFound();
    $this->getJson("/api/v1/dms/documents/{$inChild}")->assertNotFound();
    $this->getJson("/api/v1/dms/documents/{$open}")->assertOk();

    expect(collect($this->getJson('/api/v1/dms/documents')->json('data'))->pluck('id')->all())->toBe([$open])
        ->and($this->getJson('/api/v1/dms/folders')->json('data'))->toBe([]);

    dmsActAs(dmsMember($this->tenant, 'hr'), $this->tenant);
    $this->getJson("/api/v1/dms/documents/{$restricted}")->assertOk();
});

test('roles without write access cannot store documents', function (): void {
    dmsActAs(dmsMember($this->tenant, 'warehouse'), $this->tenant);

    $this->post('/api/v1/dms/documents', ['title' => 'X', 'file' => UploadedFile::fake()->createWithContent('b.txt', 'v2')], ['Accept' => 'application/json'])->assertForbidden();
});

test('tenants never see the documents, folders or tags of each other', function (): void {
    $id = dmsUpload('Secret', 'tenant a');
    $tag = $this->postJson('/api/v1/dms/tags', ['name' => 'Private'])->assertCreated()->json('data.id');
    $folder = $this->postJson('/api/v1/dms/folders', ['name' => 'Private'])->assertCreated()->json('data.id');

    dmsActAs(dmsMember($this->other, 'admin'), $this->other);

    $this->getJson("/api/v1/dms/documents/{$id}")->assertNotFound();
    $this->getJson('/api/v1/dms/documents')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/dms/tags')->assertOk()->assertJsonPath('data', []);
    $this->postJson('/api/v1/dms/folders', ['name' => 'Mine', 'parent_id' => $folder])->assertUnprocessable();
    $this->post('/api/v1/dms/documents', ['title' => 'X', 'folder_id' => $folder, 'tag_ids' => [$tag], 'file' => UploadedFile::fake()->createWithContent('b.txt', 'same')], ['Accept' => 'application/json'])->assertUnprocessable();
    dmsUpload('Their copy', 'tenant a');
});

test('only administrators manage retention, which blocks deletion until it expires', function (): void {
    $policyId = $this->postJson('/api/v1/dms/retention-policies', ['name' => 'Ten years', 'retention_months' => 120, 'action' => 'archive'])->assertCreated()->json('data.id');
    $id = dmsUpload('Ledger', 'r1', ['retention_policy_id' => $policyId]);

    expect($this->getJson("/api/v1/dms/documents/{$id}")->json('data.retention_until'))->toBe(now()->addMonthsNoOverflow(120)->toDateString());

    $this->deleteJson("/api/v1/dms/documents/{$id}")->assertConflict();

    dmsActAs(dmsMember($this->tenant, 'member'), $this->tenant);
    $this->postJson('/api/v1/dms/retention-policies', ['name' => 'X', 'retention_months' => 1, 'action' => 'delete'])->assertForbidden();
});

test('the retention job archives or purges due documents and respects a legal hold', function (): void {
    $archive = DmsRetentionPolicy::create(['tenant_id' => $this->tenant->id, 'name' => 'Archive', 'retention_months' => 1, 'action' => 'archive']);
    $purge = DmsRetentionPolicy::create(['tenant_id' => $this->tenant->id, 'name' => 'Purge', 'retention_months' => 1, 'action' => 'delete']);

    $archived = dmsUpload('Old', 'a1', ['retention_policy_id' => $archive->id]);
    $purged = dmsUpload('Older', 'a2', ['retention_policy_id' => $purge->id]);
    $held = dmsUpload('Held', 'a3', ['retention_policy_id' => $purge->id]);
    $this->postJson("/api/v1/dms/documents/{$held}/legal-hold", ['reason' => 'Audit'])->assertOk();

    $purgedPath = DmsDocumentVersion::query()->where('document_id', $purged)->firstOrFail()->path;
    DmsDocument::query()->update(['retention_until' => now()->subDay()->toDateString()]);

    app(TenantContext::class)->clear();
    (new ApplyDocumentRetentionJob((string) $this->tenant->id))->handle(app(DocumentRetention::class));

    expect(DmsDocument::withoutGlobalScopes()->findOrFail($archived)->status)->toBe('archived')
        ->and(DmsDocument::withoutGlobalScopes()->whereKey($purged)->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(DmsDocument::withoutGlobalScopes()->whereKey($held)->whereNull('deleted_at')->exists())->toBeTrue();

    Storage::disk('local')->assertMissing($purgedPath);
});

test('a folder with content cannot be deleted', function (): void {
    $folder = $this->postJson('/api/v1/dms/folders', ['name' => 'Full'])->assertCreated()->json('data.id');
    dmsUpload('Inside', 'f1', ['folder_id' => $folder]);

    $this->deleteJson("/api/v1/dms/folders/{$folder}")->assertConflict();

    expect(DmsFolder::query()->count())->toBe(1);
});

test('a version is always downloaded as an attachment with sniffing disabled, and only by the tenant that owns it', function (): void {
    $id = $this->post('/api/v1/dms/documents', [
        'title' => 'Page',
        'file' => UploadedFile::fake()->createWithContent('page.xml', '<html><script>alert(1)</script></html>'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $versionId = $this->getJson("/api/v1/dms/documents/{$id}/versions")->json('data.0.id');

    $response = $this->get("/api/v1/dms/documents/{$id}/versions/{$versionId}/download")->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Type'))->not->toContain('html');

    dmsActAs(dmsMember($this->other, 'admin'), $this->other);
    $this->get("/api/v1/dms/documents/{$id}/versions/{$versionId}/download")->assertNotFound();
});

test('the document grid embeds folder and latest version and handles its calculated columns', function (): void {
    $folder = DmsFolder::create(['tenant_id' => $this->tenant->id, 'name' => 'Legal']);
    $a = dmsUpload('Alpha', 'a1', ['folder_id' => $folder->id]);
    dmsUpload('Beta', 'b1');

    $rows = $this->getJson('/api/v1/dms/documents?sort_by=title&sort_dir=asc')->assertOk()->json('data');
    expect($rows[0]['folder_name'])->toBe('Legal')
        ->and($rows[0]['latest_version']['original_name'])->toBe('contract.txt');

    $values = $this->getJson('/api/v1/dms/documents?grid_values=latest_file_name')->assertOk()->json('data');
    expect($values)->toBe([['value' => 'contract.txt', 'count' => 2]]);

    $byFolder = $this->getJson('/api/v1/dms/documents?grid_values=folder.name')->assertOk()->json('data');
    expect(collect($byFolder)->pluck('count', 'value')->all())->toMatchArray(['Legal' => 1]);

    $filtered = $this->getJson('/api/v1/dms/documents?filter[folder.name][]=Legal')->assertOk()->json('data');
    expect(collect($filtered)->pluck('id')->all())->toBe([$a]);

    $this->getJson('/api/v1/dms/documents?sort_by=latest_file_name&sort_dir=desc')->assertOk();
    $this->getJson('/api/v1/dms/documents?filter_like[latest_file_name]=contr')->assertOk()->assertJsonCount(2, 'data');
});
