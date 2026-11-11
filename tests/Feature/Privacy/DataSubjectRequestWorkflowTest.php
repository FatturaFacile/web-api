<?php

declare(strict_types=1);

use App\Models\Privacy\PrivacyDataSubjectRequest;
use App\Models\Privacy\PrivacySensitiveAccessLog;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Models\Consent;
use Modules\CRM\Services\ConsentService;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = PrivacyFixtures::tenant('Workflow AG');
    $this->admin = PrivacyFixtures::member($this->tenant);
    $this->headers = PrivacyFixtures::actAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function dsrRegister(string $type, string $subjectType, string $subjectId, array $extra = []): array
{
    return test()->withHeaders(test()->headers)->postJson('/api/v1/settings/privacy/data-subject-requests', [
        'request_type' => $type,
        'regulation' => 'both',
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        ...$extra,
    ])->assertCreated()->json('data');
}

function dsrStep(string $id, string $step, array $body = [], int $status = 200): array
{
    return test()->withHeaders(test()->headers)->postJson("/api/v1/settings/privacy/data-subject-requests/{$id}/{$step}", $body)->assertStatus($status)->json();
}

test('a request goes through received, identity verified, in progress and completed, with its history', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $dsr = dsrRegister('access', 'contact', $contact->id, ['requester_reference' => 'TCK-1']);

    expect($dsr['status'])->toBe('received')
        ->and($dsr['deadline_days'])->toBe(30)
        ->and($dsr['is_overdue'])->toBeFalse()
        ->and($dsr['requester_reference'])->toBe('TCK-1');

    // nothing starts before the identity of the requester is verified
    dsrStep($dsr['id'], 'start', [], 422);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'id_document'])['data'];
    dsrStep($dsr['id'], 'start');

    // an access request cannot be completed before the export was produced
    dsrStep($dsr['id'], 'complete', [], 422);
    $this->withHeaders($this->headers)->get("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}/export")->assertOk();
    $done = dsrStep($dsr['id'], 'complete', ['response_summary' => 'Export sent through the secure channel.'])['data'];

    expect($done['status'])->toBe('completed')
        ->and($done['identity_verification_method'])->toBe('id_document')
        ->and(array_column($done['events'], 'event'))->toBe(['received', 'identity_verified', 'started', 'exported', 'completed']);

    // closed requests cannot be moved again
    dsrStep($dsr['id'], 'reject', ['reason' => 'late'], 422);

    expect(AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('action', 'PRIVACY_DSR_COMPLETED')->count())->toBe(1);
});

test('the deadline length is a tenant setting and overdue requests are flagged', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);

    $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/settings')->assertOk()->assertJsonPath('data.dsr_deadline_days', 30)->assertJsonPath('data.to_be_confirmed', true);
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/settings', ['dsr_deadline_days' => 0])->assertUnprocessable();
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/settings', ['dsr_deadline_days' => 10])->assertOk()->assertJsonPath('data.dsr_deadline_days', 10);

    $dsr = dsrRegister('access', 'contact', $contact->id);
    expect($dsr['deadline_days'])->toBe(10);

    $this->travel(11)->days();
    $overdue = $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/data-subject-requests?overdue=1')->assertOk()->json('data');
    expect($overdue)->toHaveCount(1)->and($overdue[0]['is_overdue'])->toBeTrue()->and($overdue[0]['days_remaining'])->toBeLessThan(0);

    // one extension only, with a reason, and it moves the effective deadline
    dsrStep($dsr['id'], 'extend', ['days' => 20], 422);
    $extended = dsrStep($dsr['id'], 'extend', ['days' => 20, 'reason' => 'Complex request'])['data'];
    expect($extended['is_overdue'])->toBeFalse();
    dsrStep($dsr['id'], 'extend', ['days' => 5, 'reason' => 'Again'], 422);
});

test('a rejection needs a reason and closes the request', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $dsr = dsrRegister('rectification', 'contact', $contact->id);

    dsrStep($dsr['id'], 'reject', [], 422);
    $rejected = dsrStep($dsr['id'], 'reject', ['reason' => 'Identity could not be verified'])['data'];

    expect($rejected['status'])->toBe('rejected')->and($rejected['rejection_reason'])->toBe('Identity could not be verified')->and($rejected['days_remaining'])->toBeNull();
});

test('rectification is completed by hand with a written response', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $dsr = dsrRegister('rectification', 'contact', $contact->id);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'known_account']);
    dsrStep($dsr['id'], 'start');

    dsrStep($dsr['id'], 'complete', [], 422);
    expect(dsrStep($dsr['id'], 'complete', ['response_summary' => 'Address corrected by the account manager.'])['data']['status'])->toBe('completed');
});

test('erasure keeps accounting records, pseudonymises the contact data and records why', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    PrivacyFixtures::invoice($this->tenant, $contact, now()->subYear()->toDateString());
    $contact->addresses()->create(['type' => 'billing', 'street' => 'Bahnhofstrasse', 'house_number' => '1', 'postal_code' => '8000', 'city' => 'Zurich', 'country' => 'CH']);

    $dsr = dsrRegister('erasure', 'contact', $contact->id);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'id_document']);
    dsrStep($dsr['id'], 'start');
    $done = dsrStep($dsr['id'], 'complete', ['response_summary' => 'Erasure executed, accounting records retained.'])['data'];

    $retained = collect($done['erasure_summary']['retained_records'])->keyBy('table');
    expect($done['status'])->toBe('partially_completed_legal_hold')
        ->and($retained->has('invoices'))->toBeTrue()
        ->and($retained['invoices']['retention'])->toBe('fiscal_record')
        ->and($retained['invoices']['reason'])->not->toBe('')
        ->and($retained->has('contact_addresses'))->toBeTrue()
        ->and($contact->fresh()->phone)->toBeNull()
        ->and($contact->fresh()->iban)->toBeNull()
        ->and($contact->fresh()->company_name)->toBe('Hans Meier Consulting')
        ->and($contact->invoices()->count())->toBe(1);
});

test('erasure of a contact with no statutory record anonymises it and its addresses', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $contact->addresses()->create(['type' => 'billing', 'street' => 'Bahnhofstrasse', 'house_number' => '1', 'postal_code' => '8000', 'city' => 'Zurich', 'country' => 'CH']);

    $dsr = dsrRegister('erasure', 'contact', $contact->id);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'id_document']);
    dsrStep($dsr['id'], 'start');
    $done = dsrStep($dsr['id'], 'complete')['data'];

    expect($done['status'])->toBe('completed')
        ->and($contact->fresh()->company_name)->toBe('REDACTED CONTACT')
        ->and($contact->fresh()->anonymized_at)->not->toBeNull()
        ->and($contact->addresses()->first()->street)->toBe('REDACTED')
        ->and($contact->addresses()->first()->city)->toBe('REDACTED');
});

test('erasure of a lead anonymises it', function (): void {
    $lead = PrivacyFixtures::lead($this->tenant);
    $email = $lead->email;

    $dsr = dsrRegister('erasure', 'lead', $lead->id);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'known_account']);
    dsrStep($dsr['id'], 'start');
    dsrStep($dsr['id'], 'complete');

    $lead = $lead->fresh();
    expect($lead->email)->not->toBe($email)
        ->and($lead->email)->toEndWith('@redacted.invalid')
        ->and($lead->first_name)->toBeNull()
        ->and($lead->anonymized_at)->not->toBeNull();
});

test('restriction marks the data as restricted without erasing it', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $dsr = dsrRegister('restriction', 'contact', $contact->id);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'id_document']);
    dsrStep($dsr['id'], 'start');
    dsrStep($dsr['id'], 'complete');

    $contact = $contact->fresh();
    expect($contact->processing_restricted_at)->not->toBeNull()->and($contact->phone)->toBe('+41791234567')->and($contact->anonymized_at)->toBeNull();
});

test('an objection to marketing withdraws the granted consents', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $consents = app(ConsentService::class);
    $consents->record((string) $this->tenant->id, ['contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'web_form'], null);

    $dsr = dsrRegister('objection', 'contact', $contact->id);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'known_account']);
    dsrStep($dsr['id'], 'start');
    dsrStep($dsr['id'], 'complete');

    expect($consents->effectiveStatus($contact->id, null, 'email', 'marketing'))->toBe(Consent::Withdrawn);
});

test('the export zip holds JSON and CSV of the subject across modules, logs the access and never leaks other subjects', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $other = PrivacyFixtures::contact($this->tenant, 'Other Customer');
    PrivacyFixtures::invoice($this->tenant, $contact);
    PrivacyFixtures::invoice($this->tenant, $other);
    app(ConsentService::class)->record((string) $this->tenant->id, ['contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'newsletter', 'status' => 'granted', 'source' => 'web_form'], null);

    $dsr = dsrRegister('portability', 'contact', $contact->id);

    // not before the identity check and the start of the work
    $this->withHeaders($this->headers)->get("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}/export")->assertUnprocessable();
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'id_document']);
    dsrStep($dsr['id'], 'start');

    $response = $this->withHeaders($this->headers)->get("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}/export")->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }

    $json = json_decode((string) $zip->getFromName('export.json'), true);
    $contactsCsv = (string) $zip->getFromName('contacts.csv');
    $zip->close();

    expect($names)->toContain('export.json', 'contacts.csv', 'invoices.csv', 'crm_consents.csv')
        ->and($json['subject_id'])->toBe($contact->id)
        ->and($json['summary']['invoices'])->toBe(1)
        ->and($contactsCsv)->toContain($contact->email)
        ->and($contactsCsv)->not->toContain('Other Customer')
        ->and(json_encode($json))->not->toContain($other->email);

    expect(PrivacySensitiveAccessLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('data_category', 'full_subject_export')->count())->toBe(1);
});

test('the export neutralises spreadsheet formulas', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant, '=HYPERLINK("http://evil.example")');
    $dsr = dsrRegister('access', 'contact', $contact->id);
    dsrStep($dsr['id'], 'verify-identity', ['method' => 'id_document']);
    dsrStep($dsr['id'], 'start');

    $path = $this->withHeaders($this->headers)->get("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}/export")->assertOk()->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $csv = (string) $zip->getFromName('contacts.csv');
    $zip->close();

    expect($csv)->toContain("'=HYPERLINK");
});

test('a request cannot be registered for a subject of another company', function (): void {
    $otherTenant = PrivacyFixtures::tenant('Other AG');
    $foreign = PrivacyFixtures::contact($otherTenant);

    $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/data-subject-requests', [
        'request_type' => 'access',
        'subject_type' => 'contact',
        'subject_id' => $foreign->id,
    ])->assertNotFound();
});

test('the data subject requests of a company are invisible to another company', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $dsr = dsrRegister('access', 'contact', $contact->id);

    $otherTenant = PrivacyFixtures::tenant('Intruder AG');
    $intruder = PrivacyFixtures::member($otherTenant);
    $otherHeaders = PrivacyFixtures::actAs($intruder, $otherTenant);

    $this->withHeaders($otherHeaders)->getJson("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}")->assertNotFound();
    $this->withHeaders($otherHeaders)->postJson("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}/verify-identity", ['method' => 'x'])->assertNotFound();
    $this->withHeaders($otherHeaders)->get("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}/export")->assertNotFound();
    expect($this->withHeaders($otherHeaders)->getJson('/api/v1/settings/privacy/data-subject-requests')->assertOk()->json('data'))->toBeEmpty()
        ->and(PrivacyDataSubjectRequest::withoutGlobalScopes()->whereKey($dsr['id'])->value('status'))->toBe('received');
});

test('only owners and administrators reach the privacy workflow', function (): void {
    $member = PrivacyFixtures::member($this->tenant, 'sales');
    $headers = PrivacyFixtures::actAs($member, $this->tenant);

    $this->withHeaders($headers)->getJson('/api/v1/settings/privacy/data-map')->assertForbidden();
    $this->withHeaders($headers)->getJson('/api/v1/settings/privacy/settings')->assertForbidden();
});
