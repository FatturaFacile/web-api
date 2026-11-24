<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\AuthorizationStatus;
use Modules\Associations\Models\Authorization;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    actAs($this, 'secretary');
});

afterEach(fn () => associationsTearDown());

test('personal and health data is encrypted at rest and readable through the model', function (): void {
    $member = Member::factory()->create([
        'tax_code' => 'RSSMRA85M01H501Q',
        'birth_date' => '1985-08-01',
        'birth_place' => 'Bergamo',
        'health_notes' => 'asthma',
        'allergies' => 'nuts',
        'identity_document' => 'CA12345',
    ]);

    $row = DB::table('assoc_members')->where('id', $member->id)->first();

    foreach (['tax_code' => 'RSSMRA', 'birth_date' => '1985', 'birth_place' => 'Bergamo', 'health_notes' => 'asthma', 'allergies' => 'nuts', 'identity_document' => 'CA12345'] as $column => $clear) {
        expect($row->{$column})->not->toContain($clear);
    }

    expect($member->fresh()->tax_code)->toBe('RSSMRA85M01H501Q')
        ->and($member->fresh()->health_notes)->toBe('asthma');
});

test('the immutable audit ledger never receives personal or health data in clear', function (): void {
    $member = Member::factory()->create(['tax_code' => 'RSSMRA85M01H501Q', 'health_notes' => 'asthma', 'birth_place' => 'Bergamo']);
    $member->update(['tax_code' => 'VRDGPP70A01F205X', 'health_notes' => 'diabetes']);
    $member->delete();

    $payloads = DB::table('enterprise_audit_ledger')->where('resource_id', $member->id)->get(['payload_before', 'payload_after'])->toJson();

    expect($payloads)->not->toBeEmpty()->not->toContain('RSSMRA')->not->toContain('VRDGPP')->not->toContain('asthma')->not->toContain('diabetes')->not->toContain('Bergamo');
});

test('health data is only for the roles that may see it', function (): void {
    $member = Member::factory()->create(['health_notes' => 'asthma']);

    expect($this->getJson("/api/v1/associations/members/{$member->id}")->json('data.health_notes'))->toBe('asthma');

    actAs($this, 'accountant');

    expect($this->getJson("/api/v1/associations/members/{$member->id}")->assertOk()->json('data'))->not->toHaveKey('health_notes');
});

test('a guardian is linked to a minor and never to an adult', function (): void {
    $minor = Member::factory()->minor()->create();
    $adult = Member::factory()->create();

    $this->postJson("/api/v1/associations/members/{$adult->id}/guardians", ['first_name' => 'Paola', 'last_name' => 'Neri'])->assertUnprocessable();

    $this->postJson("/api/v1/associations/members/{$minor->id}/guardians", ['first_name' => 'Paola', 'last_name' => 'Neri', 'email' => 'paola@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.is_primary', true);

    expect($this->getJson("/api/v1/associations/members/{$minor->id}")->json('data.is_minor'))->toBeTrue();
});

test('a guardian signs an authorization from a separate access, with the evidence of the signature', function (): void {
    $minor = Member::factory()->minor()->create();
    $guardian = Contact::create(['first_name' => 'Paola', 'last_name' => 'Neri', 'email' => 'paola@example.com']);
    Guardianship::query()->create(['minor_member_id' => $minor->id, 'guardian_contact_id' => $guardian->id, 'is_primary' => true]);

    $id = $this->postJson("/api/v1/associations/members/{$minor->id}/authorizations", ['title' => 'Trip', 'body' => 'I allow the trip of 12 May.'])->assertCreated()->json('data.id');
    $token = areaToken($guardian);

    $this->withHeaders(areaHeaders($token))
        ->postJson("/api/v1/association-area/members/{$minor->id}/authorizations/{$id}/sign", ['signed_name' => 'Paola Neri'])
        ->assertOk()->assertJsonMissingPath('data.signed_ip');

    $authorization = Authorization::query()->findOrFail($id);

    expect($authorization->status)->toBe(AuthorizationStatus::Signed)
        ->and($authorization->body_hash)->toBe(hash('sha256', 'I allow the trip of 12 May.'))
        ->and($authorization->signed_ip)->not->toBeNull()
        ->and($authorization->signed_with_token_id)->not->toBeNull()
        ->and($authorization->signed_at)->not->toBeNull();

    $this->withHeaders(areaHeaders($token))
        ->postJson("/api/v1/association-area/members/{$minor->id}/authorizations/{$id}/sign", ['signed_name' => 'Paola Neri'])
        ->assertConflict();
});

test('somebody else cannot sign for a minor', function (): void {
    $minor = Member::factory()->minor()->create();
    $id = $this->postJson("/api/v1/associations/members/{$minor->id}/authorizations", ['title' => 'Trip', 'body' => 'Text'])->json('data.id');
    $stranger = Member::factory()->create();

    $this->withHeaders(areaHeaders(areaToken($stranger->contact)))
        ->postJson("/api/v1/association-area/members/{$minor->id}/authorizations/{$id}/sign", ['signed_name' => 'Not Me'])
        ->assertNotFound();

    expect(Authorization::query()->findOrFail($id)->status)->toBe(AuthorizationStatus::Pending);
});

test('a guardian loses the view of a child who has come of age', function (): void {
    $child = Member::factory()->create(['birth_date' => now()->subYears(18)->subDay()->toDateString()]);
    $guardian = Contact::create(['first_name' => 'Paola', 'last_name' => 'Neri', 'email' => 'paola@example.com']);
    Guardianship::query()->create(['minor_member_id' => $child->id, 'guardian_contact_id' => $guardian->id]);

    $this->withHeaders(areaHeaders(areaToken($guardian)))->getJson("/api/v1/association-area/members/{$child->id}/fees")->assertNotFound();
});
