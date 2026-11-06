<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Associations\Enums\RegistryKind;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\CustomField;
use Modules\Associations\Models\ImportBatch;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\SocialRole;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
});

afterEach(fn () => associationsTearDown());

test('a person is registered on a new contact, without copying its data', function (): void {
    $response = $this->postJson('/api/v1/associations/members', [
        'first_name' => 'Anna',
        'last_name' => 'Rossi',
        'email' => 'anna@example.com',
        'birth_date' => '1990-05-01',
        'tax_code' => 'RSSNNA90E41H501X',
    ])->assertCreated();

    $member = Member::query()->findOrFail($response->json('data.id'));
    $contact = Contact::query()->findOrFail($member->contact_id);

    expect($contact->email)->toBe('anna@example.com')
        ->and($contact->tenant_id)->toBe($this->tenant->id)
        ->and(array_keys((array) DB::table('assoc_members')->where('id', $member->id)->first()))->not->toContain('first_name')
        ->and($response->json('data.first_name'))->toBe('Anna');
});

test('a person can be registered on an existing contact, once', function (): void {
    $contact = Contact::create(['first_name' => 'Luca', 'last_name' => 'Verdi']);

    $this->postJson('/api/v1/associations/members', ['contact_id' => $contact->id])->assertCreated();
    $this->postJson('/api/v1/associations/members', ['contact_id' => $contact->id])->assertConflict();
});

test('a contact of another tenant cannot be linked', function (): void {
    $foreign = inTenant($this->otherTenant, fn () => Contact::create(['first_name' => 'Altro', 'last_name' => 'Ente']));

    $this->postJson('/api/v1/associations/members', ['contact_id' => $foreign->id])->assertUnprocessable();
});

test('the same tax code cannot be registered twice in a tenant but can in another', function (): void {
    $this->postJson('/api/v1/associations/members', ['first_name' => 'A', 'last_name' => 'B', 'tax_code' => 'RSSMRA80A01H501U'])->assertCreated();
    $this->postJson('/api/v1/associations/members', ['first_name' => 'C', 'last_name' => 'D', 'tax_code' => 'rssmra80a01h501u'])->assertConflict();

    inTenant($this->otherTenant, fn () => Member::factory()->create(['tax_code' => 'RSSMRA80A01H501U']));

    expect(Member::withoutGlobalScopes()->count())->toBe(2);
});

test('types have validity periods and a history per year', function (): void {
    $member = Member::factory()->create();
    $type = RegistryType::factory()->create(['kind' => RegistryKind::Volunteer]);
    $lastYear = AssociativeYear::factory()->create(['starts_on' => now()->subYear()->startOfYear(), 'ends_on' => now()->subYear()->endOfYear()]);
    $thisYear = AssociativeYear::factory()->create();

    $this->postJson("/api/v1/associations/members/{$member->id}/registry-types", [
        'registry_type_id' => $type->id, 'year_id' => $lastYear->id, 'valid_from' => $lastYear->starts_on->toDateString(), 'valid_to' => $lastYear->ends_on->toDateString(),
    ])->assertCreated();
    $this->postJson("/api/v1/associations/members/{$member->id}/registry-types", ['registry_type_id' => $type->id, 'year_id' => $thisYear->id, 'valid_from' => $thisYear->starts_on->toDateString()])->assertCreated();

    expect($this->getJson("/api/v1/associations/members/{$member->id}")->json('data.registry_assignments'))->toHaveCount(2)
        ->and($this->getJson("/api/v1/associations/members?registry_type_id={$type->id}")->json('data'))->toHaveCount(1);
});

test('generic registry types are visible to every tenant and read-only', function (): void {
    $generic = inTenant(null, fn () => RegistryType::factory()->create(['tenant_id' => null, 'code' => 'generic-type']));

    expect(collect($this->getJson('/api/v1/associations/catalogs/registry-types')->json('data'))->pluck('id'))->toContain($generic->id);

    $this->putJson("/api/v1/associations/catalogs/registry-types/{$generic->id}", ['code' => 'changed', 'name' => 'Changed'])->assertForbidden();
    $this->deleteJson("/api/v1/associations/catalogs/registry-types/{$generic->id}")->assertForbidden();
});

test('an office with a single holder cannot be given twice in the same period', function (): void {
    $role = SocialRole::query()->create(['code' => 'president', 'name' => 'President', 'is_single_holder' => true]);
    [$first, $second] = Member::factory()->count(2)->create();

    $this->postJson("/api/v1/associations/members/{$first->id}/social-roles", ['social_role_id' => $role->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30'])->assertCreated();
    $this->postJson("/api/v1/associations/members/{$second->id}/social-roles", ['social_role_id' => $role->id, 'valid_from' => '2026-03-01'])->assertConflict();
    $this->postJson("/api/v1/associations/members/{$second->id}/social-roles", ['social_role_id' => $role->id, 'valid_from' => '2026-07-01'])->assertCreated();
});

test('custom fields are validated against their configuration and stored encrypted', function (): void {
    CustomField::query()->create(['code' => 'shirt', 'label' => 'Shirt', 'type' => 'select', 'options' => ['S', 'M'], 'is_required' => true]);

    $this->postJson('/api/v1/associations/members', ['first_name' => 'A', 'last_name' => 'B'])->assertUnprocessable();
    $this->postJson('/api/v1/associations/members', ['first_name' => 'A', 'last_name' => 'B', 'custom_fields' => ['shirt' => 'XXL']])->assertUnprocessable();
    $this->postJson('/api/v1/associations/members', ['first_name' => 'A', 'last_name' => 'B', 'custom_fields' => ['shirt' => 'M', 'other' => 1]])->assertUnprocessable();

    $id = $this->postJson('/api/v1/associations/members', ['first_name' => 'A', 'last_name' => 'B', 'custom_fields' => ['shirt' => 'M']])->assertCreated()->json('data.id');

    expect(DB::table('assoc_members')->where('id', $id)->value('custom_fields'))->not->toContain('shirt')
        ->and(Member::query()->findOrFail($id)->custom_fields)->toBe(['shirt' => 'M']);
});

test('documents stay on the private disk and are downloaded through authorization', function (): void {
    Storage::fake('private');
    config(['filesystems.private_disk' => 'private']);
    $member = Member::factory()->create();

    $id = $this->post("/api/v1/associations/members/{$member->id}/documents", [
        'file' => UploadedFile::fake()->create('card.pdf', 10, 'application/pdf'),
        'title' => 'Identity card',
        'expires_on' => now()->addDays(10)->toDateString(),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    expect(DB::table('assoc_member_documents')->where('id', $id)->value('path'))->toStartWith("tenants/{$this->tenant->id}/associations/")
        ->and($this->getJson("/api/v1/associations/members/{$member->id}/documents")->json('data.0'))->not->toHaveKey('path')
        ->and($this->getJson('/api/v1/associations/member-documents/expiring?days=30')->json('data'))->toHaveCount(1);

    $this->get("/api/v1/associations/member-documents/{$id}/download")->assertOk();

    actAs($this, 'member');
    $this->get("/api/v1/associations/member-documents/{$id}/download", ['Accept' => 'application/json'])->assertForbidden();
});

test('the preview reports errors and duplicates and changes nothing, then the import applies the accepted rows', function (): void {
    Member::factory()->create(['tax_code' => 'DPLCDE80A01H501Z']);
    $type = RegistryType::factory()->create(['code' => 'athlete']);
    $before = Member::query()->count();

    $csv = "first_name;last_name;email;tax_code;birth_date;registry_type\n"
        ."Nuova;Persona;nuova@example.com;NVOPRS90A01H501A;1990-01-01;athlete\n"
        ."Doppia;Persona;;DPLCDE80A01H501Z;1980-01-01;\n"
        .";SenzaNome;;;;\n"
        ."Nuova;Bis;altra@example.com;NVOPRS90A01H501A;;\n";

    $preview = $this->post('/api/v1/associations/registry-imports', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)], ['Accept' => 'application/json'])->assertCreated();

    expect($preview->json('data.summary'))->toMatchArray(['total' => 4, 'valid' => 1, 'invalid' => 1, 'duplicates' => 2])
        ->and($preview->json('data.rows.1.duplicate.reason'))->toBe('tax_code')
        ->and($preview->json('data.rows.3.duplicate.reason'))->toBe('tax_code_in_file')
        ->and(Member::query()->count())->toBe($before);

    $id = $preview->json('data.id');

    $this->postJson("/api/v1/associations/registry-imports/{$id}/commit", ['on_duplicate' => 'skip'])->assertOk()
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.skipped', 3);

    $created = Member::query()->where('id', '!=', Member::query()->orderBy('created_at')->first()->id)->firstOrFail();

    expect(Member::query()->count())->toBe($before + 1)
        ->and($created->registryAssignments()->where('registry_type_id', $type->id)->exists())->toBeTrue()
        ->and(ImportBatch::query()->findOrFail($id)->rows)->toBe([]);

    $this->postJson("/api/v1/associations/registry-imports/{$id}/commit")->assertConflict();
});

test('a confirmed import can update the duplicates instead of skipping them', function (): void {
    $member = Member::factory()->create(['tax_code' => 'DPLCDE80A01H501Z']);
    $csv = "first_name,last_name,tax_code,birth_place\nNew,Name,DPLCDE80A01H501Z,Milano\n";

    $id = $this->post('/api/v1/associations/registry-imports', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)], ['Accept' => 'application/json'])->json('data.id');

    $this->postJson("/api/v1/associations/registry-imports/{$id}/commit", ['on_duplicate' => 'update'])->assertJsonPath('data.updated', 1);

    expect($member->refresh()->birth_place)->toBe('Milano')
        ->and($member->contact->refresh()->first_name)->toBe('New');
});

test('the export is CSV, neutralizes formulas and leaves out health data', function (): void {
    $contact = Contact::create(['first_name' => '=HYPERLINK("http://x")', 'last_name' => 'Rossi']);
    Member::factory()->create(['contact_id' => $contact->id, 'health_notes' => 'asthma']);

    $content = (string) $this->get('/api/v1/associations/members/export')->assertOk()->getContent();

    expect($content)->toContain("'=HYPERLINK")->not->toContain('asthma');
});

test('a member of another tenant is invisible', function (): void {
    $foreign = inTenant($this->otherTenant, fn () => Member::factory()->create());

    $this->getJson("/api/v1/associations/members/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/associations/members/{$foreign->id}", ['first_name' => 'X'])->assertNotFound();
    $this->deleteJson("/api/v1/associations/members/{$foreign->id}")->assertNotFound();
    expect($this->getJson('/api/v1/associations/members')->json('data'))->toBe([]);
});
