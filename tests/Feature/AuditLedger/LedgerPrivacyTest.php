<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\User;
use Enterprise\AuditLedger\Application\Commands\EraseAuditSubjectCommand;
use Enterprise\AuditLedger\Application\Commands\EraseAuditSubjectHandler;
use Enterprise\AuditLedger\Domain\Events\AuditSubjectErased;
use Enterprise\AuditLedger\Domain\Services\Pseudonymizer;
use Enterprise\AuditLedger\Domain\ValueObjects\PseudonymToken;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = AuditLedgerFixtures::tenant('Privacy Tenant');
    $this->tenantId = (string) $this->tenant->id;
    $this->base = '/api/v1/settings/audit-logs';
    $this->verifier = app(AuditChainVerificationService::class);
});

/**
 * Everything the ledger holds for a tenant as one string, to prove that a value is nowhere in it.
 */
function auditLedgerDump(string $tenantId): string
{
    return (string) json_encode([
        DB::table('enterprise_audit_ledger')->where('tenant_id', $tenantId)->get()->all(),
        DB::table('audit_subject_map')->where('tenant_id', $tenantId)->get()->all(),
    ]);
}

test('passwords, tokens, secrets and one time codes are never stored, not even pseudonymised', function (): void {
    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', null, [
        'password' => 'hunter2-password-value',
        'password_confirmation' => 'hunter2-password-value',
        'remember_token' => 'remember-token-value',
        'api_token' => 'api-token-value',
        'otp_code' => '834-291-otp',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP-secret',
        'client_secret' => 'client-secret-value',
        'nested' => ['smtp_password' => 'smtp-password-value', 'webhook_token' => 'webhook-token-value'],
        'name' => 'Visible',
    ]);

    $dump = auditLedgerDump($this->tenantId);

    foreach (['hunter2-password-value', 'remember-token-value', 'api-token-value', '834-291-otp', 'JBSWY3DPEHPK3PXP-secret', 'client-secret-value', 'smtp-password-value', 'webhook-token-value'] as $value) {
        expect($dump)->not->toContain($value);
    }

    expect($entry->changes['new']['password'])->toBe('[REDACTED]')
        ->and($entry->changes['new']['otp_code'])->toBe('[REDACTED]')
        ->and($entry->changes['new']['nested']['smtp_password'])->toBe('[REDACTED]')
        ->and($entry->changes['new']['name'])->toBe('Visible')
        ->and(DB::table('audit_subject_map')->where('tenant_id', $this->tenantId)->count())->toBe(0);
});

test('names that merely contain pin or token as a substring are kept', function (): void {
    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', null, ['shipping_address' => 'Via Roma 1', 'mapping_id' => 7, 'tokens_count' => 3]);

    expect($entry->changes['new'])->toBe(['mapping_id' => 7, 'shipping_address' => 'Via Roma 1', 'tokens_count' => 3]);
});

test('the fiscal code is pseudonymised and an auditor still reads it until the subject is erased', function (): void {
    $contactId = (string) Str::uuid7();
    $entry = AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['company_name' => 'Rossi', 'fiscal_code' => 'RSSMRA80A01H501U'], $contactId);

    expect(PseudonymToken::looksLikeToken($entry->changes['new']['fiscal_code']))->toBeTrue()
        ->and(auditLedgerDump($this->tenantId))->not->toContain('RSSMRA80A01H501U');

    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);

    $client->getJson($this->base.'?resource_type=Contact&action=CREATED')->assertOk()
        ->assertJsonPath('data.0.changes.new.fiscal_code', 'RSSMRA80A01H501U')
        ->assertJsonPath('data.0.changes.new.company_name', 'Rossi');

    app(EraseAuditSubjectHandler::class)->handle(new EraseAuditSubjectCommand($this->tenantId, 'App\\Models\\Contact', $contactId, (string) $this->owner->id));

    $client->getJson($this->base.'?resource_type=Contact&action=CREATED')->assertOk()
        ->assertJsonPath('data.0.changes.new.fiscal_code', Pseudonymizer::ERASED_LABEL)
        ->assertJsonPath('data.0.changes.new.company_name', 'Rossi');
});

test('erasing a subject keeps the chain valid, changes no stored entry and is itself recorded', function (): void {
    $contactId = (string) Str::uuid7();
    AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['fiscal_code' => 'RSSMRA80A01H501U', 'email' => 'mario@example.test'], $contactId, personal: ['email']);
    AuditLedgerFixtures::chain($this->tenantId, 3, $contactId);

    $before = DB::table('enterprise_audit_ledger')->where('tenant_id', $this->tenantId)->orderBy('sequence')->get(['sequence', 'record_hash', 'previous_hash', 'changes'])->all();
    Event::fake([AuditSubjectErased::class]);

    $erased = app(EraseAuditSubjectHandler::class)->handle(new EraseAuditSubjectCommand($this->tenantId, 'App\\Models\\Contact', $contactId, (string) $this->owner->id));

    $after = array_slice(DB::table('enterprise_audit_ledger')->where('tenant_id', $this->tenantId)->orderBy('sequence')->get(['sequence', 'record_hash', 'previous_hash', 'changes'])->all(), 0, count($before));

    expect($erased)->toBe(2)
        ->and($after)->toEqual($before)
        ->and($this->verifier->verifyTenantChain($this->tenantId)['valid'])->toBeTrue()
        ->and(DB::table('audit_subject_map')->where('tenant_id', $this->tenantId)->where('subject_id', $contactId)->count())->toBe(0)
        ->and(AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->where('action', 'AUDIT_SUBJECT_ERASED')->count())->toBe(1);

    Event::assertDispatched(AuditSubjectErased::class, fn (AuditSubjectErased $e): bool => $e->subjectId === $contactId && $e->erasedTokens === 2);
});

test('the erasure of one subject leaves the pseudonyms of another subject readable', function (): void {
    $first = (string) Str::uuid7();
    $second = (string) Str::uuid7();
    AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['fiscal_code' => 'AAAAAA00A00A000A'], $first);
    AuditLedgerFixtures::append($this->tenantId, 'CREATED', null, ['fiscal_code' => 'BBBBBB00B00B000B'], $second);

    app(EraseAuditSubjectHandler::class)->handle(new EraseAuditSubjectCommand($this->tenantId, 'App\\Models\\Contact', $first));

    $rows = AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'?resource_type=Contact&action=CREATED')->assertOk()->json('data');
    $byResource = array_column($rows, null, 'resource_id');

    expect($byResource[$first]['changes']['new']['fiscal_code'])->toBe(Pseudonymizer::ERASED_LABEL)
        ->and($byResource[$second]['changes']['new']['fiscal_code'])->toBe('BBBBBB00B00B000B');
});

test('the actor of an entry is a pseudonym that resolves to the name until the person is erased', function (): void {
    $actor = AuditLedgerFixtures::member($this->tenant, 'admin');
    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2], null, 'App\\Models\\Contact', (string) $actor->id);

    expect($entry->actor_type)->toBe('user')
        ->and(PseudonymToken::looksLikeToken($entry->actor_ref))->toBeTrue()
        ->and(auditLedgerDump($this->tenantId))->not->toContain((string) $actor->email);

    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);
    $client->getJson($this->base.'?action=UPDATED&actor='.$actor->id)->assertOk()->assertJsonPath('data.0.display_actor', $actor->name);

    $storedHash = $entry->record_hash;
    app(EraseAuditSubjectHandler::class)->handle(new EraseAuditSubjectCommand($this->tenantId, 'user', (string) $actor->id));

    $client->getJson($this->base.'?action=UPDATED&actor='.$actor->id)->assertOk()->assertJsonPath('data.0.display_actor', Pseudonymizer::ERASED_LABEL);

    expect(AuditLedgerEntry::query()->where('id', $entry->id)->value('record_hash'))->toBe($storedHash)
        ->and($this->verifier->verifyTenantChain($this->tenantId)['valid'])->toBeTrue();
});

test('an impersonation records the real operator next to the impersonated user', function (): void {
    $operator = AuditLedgerFixtures::member($this->tenant, 'owner');
    $target = AuditLedgerFixtures::member($this->tenant, 'member');
    $token = $target->createToken('impersonation:'.$operator->id)->accessToken;
    auth()->setUser($target->withAccessToken($token));

    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['a' => 1], ['a' => 2]);

    expect($entry->actor_ref)->not->toBeNull()
        ->and($entry->impersonator_ref)->not->toBeNull()
        ->and($entry->impersonator_ref)->not->toBe($entry->actor_ref);

    auth()->forgetGuards();
    $rows = AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson($this->base.'?action=UPDATED')->assertOk()->json('data');
    $row = collect($rows)->firstWhere('id', $entry->id);

    expect($row['display_actor'])->toBe($target->name)->and($row['display_impersonator'])->toBe($operator->name);
});

test('an api key is recorded as an api key actor on the api channel', function (): void {
    app('request')->attributes->set('api_key_scopes', ['reporting.read']);
    app('request')->attributes->set('api_key_id', 'key-123');

    $entry = AuditLedgerFixtures::append($this->tenantId, 'VIEWED', null, ['report' => 'sales']);

    expect($entry->actor_type)->toBe('api_key')->and($entry->actor_ref)->not->toBeNull()->and($entry->channel)->toBe('api');
});

test('the user account events carry pseudonyms and no password', function (): void {
    $user = User::create(['name' => 'Giulia Bianchi', 'email' => 'giulia@example.test', 'password' => bcrypt('Sup3r-Secret-Pw!'), 'tenant_id' => $this->tenantId]);

    $entry = AuditLedgerEntry::query()->where('resource_id', $user->id)->where('action', 'CREATED')->firstOrFail();

    expect(json_encode($entry->changes))->not->toContain('Giulia')->and(json_encode($entry->changes))->not->toContain('giulia@example.test')
        ->and(json_encode($entry->changes))->not->toContain('$2y$')
        ->and($entry->changes['new']['password'] ?? '[REDACTED]')->toBe('[REDACTED]')
        ->and(PseudonymToken::looksLikeToken($entry->changes['new']['name']))->toBeTrue()
        ->and(PseudonymToken::looksLikeToken($entry->changes['new']['email']))->toBeTrue();

    $user->update(['name' => 'Giulia Verdi']);
    $update = AuditLedgerEntry::query()->where('resource_id', $user->id)->where('action', 'UPDATED')->firstOrFail();

    expect(array_keys($update->changes['new']))->toBe(['name']);
});

test('a contact is recorded with pseudonymised personal data and its changes only', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenantId, 'type' => 'customer', 'country' => 'IT', 'first_name' => 'Mario', 'last_name' => 'Rossi',
        'email' => 'mario.rossi@example.test', 'fiscal_code' => 'RSSMRA80A01H501U', 'company_name' => 'Rossi SRL',
    ]);

    $created = AuditLedgerEntry::query()->where('resource_id', $contact->id)->where('action', 'CREATED')->firstOrFail();

    expect(auditLedgerDump($this->tenantId))->not->toContain('RSSMRA80A01H501U')->and(auditLedgerDump($this->tenantId))->not->toContain('mario.rossi@example.test')
        ->and(PseudonymToken::looksLikeToken($created->changes['new']['fiscal_code']))->toBeTrue()
        ->and(PseudonymToken::looksLikeToken($created->changes['new']['first_name']))->toBeTrue()
        ->and($created->changes['new']['company_name'])->toBe('Rossi SRL');

    $contact->update(['company_name' => 'Rossi SA']);
    $updated = AuditLedgerEntry::query()->where('resource_id', $contact->id)->where('action', 'UPDATED')->firstOrFail();

    expect($updated->changes)->toBe(['new' => ['company_name' => 'Rossi SA'], 'old' => ['company_name' => 'Rossi SRL']]);

    $contact->delete();
    expect(AuditLedgerEntry::query()->where('resource_id', $contact->id)->where('action', 'DELETED')->exists())->toBeTrue();
});
