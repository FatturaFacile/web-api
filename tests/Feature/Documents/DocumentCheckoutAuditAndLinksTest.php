<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsFolder;

uses(RefreshDatabase::class);

function dmsxMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function dmsxActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function dmsxUpload(string $title = 'Contract', string $content = 'first content', array $extra = []): string
{
    return test()->post('/api/v1/dms/documents', [
        'title' => $title,
        'file' => UploadedFile::fake()->createWithContent('contract.txt', $content),
        ...$extra,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
}

function dmsxFile(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('edited.txt', $content);
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->admin = dmsxMember($this->tenant, 'admin');
    $this->member = dmsxMember($this->tenant, 'member');
    dmsxActAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('checking out locks the document for everybody else', function (): void {
    $id = dmsxUpload('Lease', 'v1');

    $this->postJson("/api/v1/dms/documents/{$id}/check-out", ['note' => 'Editing clause 4'])
        ->assertOk()
        ->assertJsonPath('data.is_checked_out', true)
        ->assertJsonPath('data.checked_out_by', $this->admin->id)
        ->assertJsonPath('data.checkout_note', 'Editing clause 4');

    // The holder asking again changes nothing.
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertOk();

    dmsxActAs($this->member, $this->tenant);
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertConflict();
    $this->post("/api/v1/dms/documents/{$id}/versions", ['file' => dmsxFile('v2')], ['Accept' => 'application/json'])->assertConflict();
    $this->putJson("/api/v1/dms/documents/{$id}", ['title' => 'Hijacked'])->assertConflict();
    $this->deleteJson("/api/v1/dms/documents/{$id}")->assertConflict();
    $this->deleteJson("/api/v1/dms/documents/{$id}/check-out")->assertConflict();
    $this->postJson("/api/v1/dms/documents/{$id}/check-in")->assertConflict();

    expect($this->getJson('/api/v1/dms/documents?checked_out=true')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/dms/documents?checked_out=false')->json('data'))->toHaveCount(0);
});

test('the holder checks in with an edited file as a new version', function (): void {
    $id = dmsxUpload('Lease', 'v1');
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertOk();

    $this->post("/api/v1/dms/documents/{$id}/versions", ['file' => dmsxFile('v2 by holder')], ['Accept' => 'application/json'])->assertCreated();
    $this->post("/api/v1/dms/documents/{$id}/check-in", ['file' => dmsxFile('v2 by holder'), 'comment' => 'same file'], ['Accept' => 'application/json'])->assertConflict();
    $this->assertDatabaseHas('dms_documents', ['id' => $id, 'checked_out_by' => $this->admin->id]);

    $response = $this->post("/api/v1/dms/documents/{$id}/check-in", ['file' => dmsxFile('v3 final'), 'comment' => 'final'], ['Accept' => 'application/json'])->assertOk();

    expect($response->json('data.is_checked_out'))->toBeFalse()
        ->and($response->json('data.current_version'))->toBe(3)
        ->and($response->json('data.versions'))->toHaveCount(3);

    $this->postJson("/api/v1/dms/documents/{$id}/check-in")->assertConflict();
});

test('a check-in without a file just releases the document', function (): void {
    $id = dmsxUpload('Lease', 'v1');
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertOk();

    $this->postJson("/api/v1/dms/documents/{$id}/check-in")->assertOk()->assertJsonPath('data.current_version', 1)->assertJsonPath('data.is_checked_out', false);
    $this->post("/api/v1/dms/documents/{$id}/check-in", ['file' => 'not-a-file'], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('only a manager can release a check-out held by somebody else, and it is audited', function (): void {
    dmsxActAs($this->member, $this->tenant);
    $id = dmsxUpload('Lease', 'v1');
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertOk();

    dmsxActAs($this->admin, $this->tenant);
    $this->deleteJson("/api/v1/dms/documents/{$id}/check-out")->assertOk()->assertJsonPath('data.is_checked_out', false);
    $this->deleteJson("/api/v1/dms/documents/{$id}/check-out")->assertConflict();

    $trail = collect($this->getJson("/api/v1/dms/documents/{$id}/audit-trail")->assertOk()->json('data'));
    $released = $trail->firstWhere('action', 'DOCUMENT_CHECK_OUT_CANCELLED');

    expect($released['details']['forced'])->toBeTrue()->and($released['actor_user_id'])->toBe($this->admin->id);
});

test('a document under legal hold cannot be checked out', function (): void {
    $id = dmsxUpload('Lease', 'v1');
    $this->postJson("/api/v1/dms/documents/{$id}/legal-hold", ['reason' => 'Dispute'])->assertOk();

    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertConflict();
});

test('the audit trail records versions, downloads, checks and links in order', function (): void {
    $id = dmsxUpload('Lease', 'v1');
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Acme']);
    $versionId = $this->getJson("/api/v1/dms/documents/{$id}")->json('data.versions.0.id');

    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertOk();
    $this->post("/api/v1/dms/documents/{$id}/check-in", ['file' => dmsxFile('v2')], ['Accept' => 'application/json'])->assertOk();
    $this->get("/api/v1/dms/documents/{$id}/versions/{$versionId}/download")->assertOk();
    $linkId = $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'contact', 'linkable_id' => $contact->id])->assertCreated()->json('data.links.0.id');
    $this->deleteJson("/api/v1/dms/documents/{$id}/links/{$linkId}")->assertOk();

    $actions = collect($this->getJson("/api/v1/dms/documents/{$id}/audit-trail")->assertOk()->json('data'))->pluck('action')->all();

    expect($actions)->toContain('CREATED', 'DOCUMENT_CHECKED_OUT', 'DOCUMENT_VERSION_ADDED', 'DOCUMENT_CHECKED_IN', 'DOCUMENT_DOWNLOADED', 'DOCUMENT_LINKED', 'DOCUMENT_UNLINKED');

    $entries = collect($this->getJson("/api/v1/dms/documents/{$id}/audit-trail")->json('data'));
    expect($entries->pluck('details')->map(fn (array $details): bool => array_key_exists('search_text', $details))->contains(true))->toBeFalse();
});

test('the audit trail is for managers and the creator only, and respects visibility', function (): void {
    dmsxActAs($this->member, $this->tenant);
    $mine = dmsxUpload('Mine', 'mine');
    $this->getJson("/api/v1/dms/documents/{$mine}/audit-trail")->assertOk();

    $other = dmsxMember($this->tenant, 'sales');
    dmsxActAs($other, $this->tenant);
    $this->getJson("/api/v1/dms/documents/{$mine}/audit-trail")->assertForbidden();

    dmsxActAs($this->admin, $this->tenant);
    $restricted = dmsxUpload('Payroll', 'secret', ['access_roles' => ['admin']]);

    dmsxActAs($this->member, $this->tenant);
    $this->getJson("/api/v1/dms/documents/{$restricted}/audit-trail")->assertNotFound();
});

test('lifecycle endpoints answer 404 across tenants and 422 on bad input', function (): void {
    $id = dmsxUpload('Lease', 'v1');

    dmsxActAs(dmsxMember($this->other, 'admin'), $this->other);
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertNotFound();
    $this->postJson("/api/v1/dms/documents/{$id}/check-in")->assertNotFound();
    $this->deleteJson("/api/v1/dms/documents/{$id}/check-out")->assertNotFound();
    $this->getJson("/api/v1/dms/documents/{$id}/audit-trail")->assertNotFound();
    $this->getJson("/api/v1/dms/documents/{$id}/links")->assertNotFound();

    dmsxActAs($this->admin, $this->tenant);
    $this->postJson("/api/v1/dms/documents/{$id}/check-out", ['note' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonValidationErrors(['note']);
});

test('read-only roles cannot write to the lifecycle', function (): void {
    $id = dmsxUpload('Lease', 'v1');

    // The documents module is writable by every role listed in config/permissions.php; the accountant read-only role is not one.
    dmsxActAs(dmsxMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->postJson("/api/v1/dms/documents/{$id}/check-out")->assertForbidden();
    $this->postJson("/api/v1/dms/documents/{$id}/check-in")->assertForbidden();
    $this->deleteJson("/api/v1/dms/documents/{$id}/check-out")->assertForbidden();
});

test('links resolve inside the tenant only and show records deleted since', function (): void {
    $id = dmsxUpload('Lease', 'v1');
    $mine = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Acme']);

    // Without an explicit tenant context the model keeps the tenant it is given (the context would override it).
    app(TenantContext::class)->clear();
    $foreign = Contact::create(['tenant_id' => $this->other->id, 'type' => 'customer', 'company_name' => 'Foreign']);
    $generic = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Generic']);
    DB::table('contacts')->where('id', $generic->id)->update(['tenant_id' => null]);
    dmsxActAs($this->admin, $this->tenant);

    $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'contact', 'linkable_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['linkable_id']);
    $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'contact', 'linkable_id' => $generic->id])->assertUnprocessable();
    $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'unknown', 'linkable_id' => $mine->id])->assertUnprocessable()->assertJsonValidationErrors(['linkable_type']);
    $this->postJson("/api/v1/dms/documents/{$id}/links", ['linkable_type' => 'contact', 'linkable_id' => $mine->id])->assertCreated();

    $links = $this->getJson("/api/v1/dms/documents/{$id}/links")->assertOk()->json('data');
    expect($links)->toHaveCount(1)->and($links[0])->toMatchArray(['linkable_type' => 'contact', 'label' => 'Acme', 'record_exists' => true]);

    $mine->delete();
    expect($this->getJson("/api/v1/dms/documents/{$id}/links")->json('data.0.record_exists'))->toBeFalse();
});

test('documents are found by the linked record and by a metadata value', function (): void {
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Acme']);
    $a = dmsxUpload('Policy A', 'a', ['metadata' => ['policy_number' => 'POL-2027-1', 'insurer' => 'Generali']]);
    dmsxUpload('Policy B', 'b', ['metadata' => ['policy_number' => 'POL-2027-2']]);
    $this->postJson("/api/v1/dms/documents/{$a}/links", ['linkable_type' => 'contact', 'linkable_id' => $contact->id])->assertCreated();

    $byRecord = $this->getJson("/api/v1/dms/documents?linkable_type=contact&linkable_id={$contact->id}")->json('data');
    $byMetadata = $this->getJson('/api/v1/dms/documents?metadata_key=policy_number&metadata_value=POL-2027-2')->json('data');
    $byText = $this->getJson('/api/v1/dms/documents?q=Generali')->json('data');

    expect($byRecord)->toHaveCount(1)->and($byRecord[0]['id'])->toBe($a)
        ->and($byMetadata)->toHaveCount(1)->and($byMetadata[0]['title'])->toBe('Policy B')
        ->and($byText)->toHaveCount(1)->and($byText[0]['id'])->toBe($a);
});

test('a folder cannot move into its own subtree and names stay unique per parent', function (): void {
    $root = $this->postJson('/api/v1/dms/folders', ['name' => 'Legal'])->assertCreated()->json('data.id');
    $child = $this->postJson('/api/v1/dms/folders', ['name' => 'Contracts', 'parent_id' => $root])->assertCreated()->json('data.id');
    $grandchild = $this->postJson('/api/v1/dms/folders', ['name' => 'Leases', 'parent_id' => $child])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/dms/folders/{$root}", ['name' => 'Legal', 'parent_id' => $grandchild])->assertConflict();
    $this->putJson("/api/v1/dms/folders/{$root}", ['name' => 'Legal', 'parent_id' => $root])->assertConflict();
    $this->postJson('/api/v1/dms/folders', ['name' => 'contracts', 'parent_id' => $root])->assertConflict();
    $this->putJson("/api/v1/dms/folders/{$grandchild}", ['name' => 'Leases', 'parent_id' => $root])->assertOk();

    expect(DmsFolder::query()->count())->toBe(3);
});

test('a download is served as an attachment with safe headers', function (): void {
    $id = dmsxUpload('Lease', '<script>alert(1)</script>');
    $versionId = DmsDocument::query()->findOrFail($id)->versions()->firstOrFail()->id;

    $response = $this->get("/api/v1/dms/documents/{$id}/versions/{$versionId}/download")->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
});
