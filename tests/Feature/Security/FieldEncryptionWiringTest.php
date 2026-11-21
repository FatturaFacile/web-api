<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Queries\Audit\VerifyAuditChainHandler;
use App\Queries\Audit\VerifyAuditChainQuery;
use App\Services\Security\FieldEncryptionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

const TEST_IBAN = 'CH9300762011623852957';

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = tfrTenant('Field Encryption Tenant');
    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Secure Client', 'type' => 'customer', 'country' => 'CH', 'iban' => TEST_IBAN]);
});

test('an encrypted field is stored as ciphertext, read back in clear and blind indexed', function (): void {
    $raw = DB::table('contacts')->where('id', $this->contact->id)->first();

    expect($raw->iban)->toStartWith('ff1:1:')->not->toContain('CH93')
        ->and(Contact::withoutGlobalScopes()->find($this->contact->id)->iban)->toBe(TEST_IBAN)
        ->and($raw->iban_bidx)->toBe(app(FieldEncryptionService::class)->blindIndex(TEST_IBAN, (string) $this->tenant->id, 'iban'));
});

test('the rotation command moves rows to the new DEK version and encrypts legacy plain values', function (): void {
    $legacy = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Legacy', 'type' => 'customer', 'country' => 'CH']);
    DB::table('contacts')->where('id', $legacy->id)->update(['iban' => 'CH5604835012345678009']);

    config()->set('field_encryption.current_version', 2);

    $this->artisan('security:rotate-field-encryption', ['--tenant' => (string) $this->tenant->id, '--dry-run' => true])->assertSuccessful();
    expect(DB::table('contacts')->where('id', $this->contact->id)->value('iban'))->toStartWith('ff1:1:');

    $this->artisan('security:rotate-field-encryption', ['--tenant' => (string) $this->tenant->id])->assertSuccessful();

    expect(DB::table('contacts')->where('id', $this->contact->id)->value('iban'))->toStartWith('ff1:2:')
        ->and(DB::table('contacts')->where('id', $legacy->id)->value('iban'))->toStartWith('ff1:2:')
        ->and(Contact::withoutGlobalScopes()->find($this->contact->id)->iban)->toBe(TEST_IBAN)
        ->and(Contact::withoutGlobalScopes()->find($legacy->id)->iban)->toBe('CH5604835012345678009')
        ->and(AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event_type', AuditEventType::KeyRotated->value)->exists())->toBeTrue()
        ->and(app(VerifyAuditChainHandler::class)->handle(new VerifyAuditChainQuery((string) $this->tenant->id))->isValid)->toBeTrue();
});

test('the API masks the IBAN without the unmask permission and the reveal endpoint audits the justification', function (): void {
    $member = tfrUserWithRole($this->tenant->id, 'member');
    DB::table('tenant_user')->where('user_id', $member->id)->update(['permissions' => json_encode(['contacts.view'])]);

    Sanctum::actingAs($member);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/security/pii/unmask', [
        'entity_type' => 'contact', 'entity_id' => $this->contact->id, 'field' => 'iban', 'justification' => 'Customer phone verification',
    ])->assertForbidden();

    Sanctum::actingAs($this->owner);
    $client = $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $client->postJson('/api/v1/security/pii/unmask', [
        'entity_type' => 'contact', 'entity_id' => $this->contact->id, 'field' => 'iban', 'justification' => 'short',
    ])->assertUnprocessable();

    $client->postJson('/api/v1/security/pii/unmask', [
        'entity_type' => 'contact', 'entity_id' => $this->contact->id, 'field' => 'iban', 'justification' => 'Customer phone verification',
    ])->assertOk()->assertJsonPath('data.value', TEST_IBAN);

    $log = AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event_type', AuditEventType::PiiUnmasked->value)->firstOrFail();
    expect($log->context_metadata['justification'])->toBe('Customer phone verification')
        ->and(json_encode($log->context_metadata))->not->toContain(TEST_IBAN);
});

test('tenant B cannot reveal tenant A data', function (): void {
    ['tenant' => $other, 'user' => $otherOwner] = tfrTenant('Other Tenant');

    Sanctum::actingAs($otherOwner);
    $this->withHeader('X-Tenant-Id', $other->id)->postJson('/api/v1/security/pii/unmask', [
        'entity_type' => 'contact', 'entity_id' => $this->contact->id, 'field' => 'iban', 'justification' => 'Trying another tenant',
    ])->assertNotFound();
});

test('the posture endpoint reports the chain, coverage and readiness', function (): void {
    Sanctum::actingAs($this->owner);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/security/posture')
        ->assertOk()
        ->assertJsonPath('data.audit_chain.status', 'verified')
        ->assertJsonPath('data.encrypted_fields_coverage.percent', 100)
        ->assertJsonStructure(['data' => ['active_dsar', 'legal_holds_active', 'open_incidents', 'readiness_score']]);
});
