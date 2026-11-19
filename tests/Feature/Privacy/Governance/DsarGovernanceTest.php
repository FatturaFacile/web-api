<?php

declare(strict_types=1);

use App\Models\ContactPerson;
use App\Models\Invoice;
use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 09:00:00');
    Storage::fake('local');
    $this->tenant = PrivacyFixtures::tenant('Dsar AG');
    $this->headers = PrivacyFixtures::actAs(PrivacyFixtures::member($this->tenant), $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function dsarCreate(array $extra = []): array
{
    return test()->withHeaders(test()->headers)->postJson('/api/v1/privacy/dsar', [
        'subject_type' => 'customer_contact', 'subject_email' => 'hans@meier.example', 'subject_full_name' => 'Hans Meier',
        'request_type' => 'erasure', ...$extra,
    ])->assertCreated()->json('data');
}

function dsarSeedHelpdesk(string $tenantId, string $contactId): string
{
    $id = (string) Str::uuid7();
    DB::table('esm_tickets')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'contact_id' => $contactId, 'ticket_number' => 'TCK-'.Str::random(6),
        'subject' => 'Hans Meier cannot log in', 'description' => 'Call him on +41791234567', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

test('a dsar gets its number and a statutory deadline of 30 days, extendable once by up to 60', function (): void {
    $dsar = dsarCreate();
    expect($dsar['request_number'])->toBe('DSAR-2026-0001')
        ->and($dsar['status'])->toBe('open')
        ->and($dsar['days_remaining'])->toBe(30)
        ->and(dsarCreate()['request_number'])->toBe('DSAR-2026-0002');

    $extended = $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/extend", ['days' => 60, 'justification' => 'Complex request'])->assertOk()->json('data');
    expect($extended['days_remaining'])->toBe(90)->and($extended['deadline_extended'])->toBeTrue();
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/extend", ['days' => 5, 'justification' => 'again'])->assertUnprocessable();
});

test('erasure needs a verified identity', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $dsar = dsarCreate(['subject_entity_id' => (string) $contact->id]);

    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/execute-erasure")->assertUnprocessable();
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/verify-identity", ['method' => 'id_document'])->assertOk()->assertJsonPath('data.status', 'identity_verified');
});

test('erasure anonymises crm and helpdesk data but keeps invoices intact and refuses partially citing the retention law', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    $invoice = PrivacyFixtures::invoice($this->tenant, $contact, '2024-05-01');
    $before = DB::table('invoices')->where('id', $invoice->id)->first();
    $person = app(TenantContext::class)->runWithTenant($this->tenant, fn () => ContactPerson::create([
        'tenant_id' => (string) $this->tenant->id, 'contact_id' => $contact->id, 'first_name' => 'Anna', 'last_name' => 'Keller', 'email' => 'anna@meier.example', 'phone' => '+41790000000',
    ]));
    $ticketId = dsarSeedHelpdesk((string) $this->tenant->id, (string) $contact->id);

    $dsar = dsarCreate(['subject_entity_id' => (string) $contact->id]);
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/verify-identity", ['method' => 'id_document'])->assertOk();

    // dry run: reports the impact and changes nothing
    $preview = $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/execute-erasure?dry_run=true")->assertOk()->json('data');
    expect($preview['dry_run'])->toBeTrue()
        ->and($preview['status'])->toBe('partially_refused_fiscal_retention')
        ->and($preview['protected'][0]['table'])->toBe('invoices')
        ->and($preview['fiscal_lock']['retention_ends_at'])->toBe('2034-12-31')
        ->and(collect($preview['to_anonymize'])->pluck('table')->all())->toContain('contact_persons', 'esm_tickets');
    expect(DB::table('esm_tickets')->where('id', $ticketId)->value('subject'))->toBe('Hans Meier cannot log in');

    $done = $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/execute-erasure")->assertOk()->json('data');
    expect($done['status'])->toBe('partially_refused_fiscal_retention')
        ->and($done['refusal_legal_justification'])->toContain('958f')->toContain('nLPD')->toContain('2034-12-31');

    expect(DB::table('esm_tickets')->where('id', $ticketId)->value('subject'))->toStartWith('ANON-')
        ->and(DB::table('esm_tickets')->where('id', $ticketId)->value('description'))->toStartWith('ANON-');
    $row = DB::table('contact_persons')->where('id', $person->id)->first();
    expect($row->first_name)->toStartWith('ANON-')->and($row->email)->toStartWith('ANON-')->and($row->email)->toEndWith('@redacted.invalid');

    // the fiscal record and the legal identity on it are untouched
    expect((array) DB::table('invoices')->where('id', $invoice->id)->first())->toBe((array) $before)
        ->and(DB::table('contacts')->where('id', $contact->id)->value('first_name'))->toBe('Hans');

    $stored = PrivacySubjectRequestDsar::withoutGlobalScope(TenantScope::class)->findOrFail($dsar['id']);
    expect($stored->status->value)->toBe('partially_refused_fiscal_retention')->and($stored->completed_at)->not->toBeNull();

    // idempotent: running again is refused because the request is closed
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/execute-erasure")->assertUnprocessable();
});

test('a customer whose invoices are past the ten year window is erased completely', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    PrivacyFixtures::invoice($this->tenant, $contact, '2010-05-01');
    $dsar = dsarCreate(['subject_entity_id' => (string) $contact->id]);
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/verify-identity", ['method' => 'id_document'])->assertOk();

    $done = $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/execute-erasure")->assertOk()->json('data');

    expect($done['status'])->toBe('completed')->and($done['refusal_legal_justification'])->toBeNull()
        ->and(DB::table('contacts')->where('id', $contact->id)->value('first_name'))->toStartWith('ANON-');
});

test('the portability package contains the json export and the pdf report and is built well under 60 seconds', function (): void {
    $contact = PrivacyFixtures::contact($this->tenant);
    PrivacyFixtures::invoice($this->tenant, $contact);
    dsarSeedHelpdesk((string) $this->tenant->id, (string) $contact->id);
    $this->withHeaders($this->headers)->postJson('/api/v1/privacy/consents', [
        'subject_type' => 'customer_contact', 'subject_identifier' => 'hans@meier.example', 'purpose_code' => 'NEWSLETTER', 'consent_granted' => true, 'policy_version' => 'v1', 'proof_channel' => 'web_form',
    ])->assertCreated();

    $dsar = dsarCreate(['subject_entity_id' => (string) $contact->id, 'request_type' => 'access_portability']);
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/generate-package")->assertUnprocessable();
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/verify-identity", ['method' => 'id_document'])->assertOk();

    $started = microtime(true);
    $this->withHeaders($this->headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/generate-package")->assertOk();
    expect(microtime(true) - $started)->toBeLessThan(60);

    $shown = $this->withHeaders($this->headers)->getJson("/api/v1/privacy/dsar/{$dsar['id']}")->assertOk()->json('data');
    expect($shown['package_status'])->toBe('ready');

    $zipPath = Storage::disk('local')->path($shown['export_artifact_path']);
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    $json = json_decode((string) $zip->getFromName('dsar_export_DSAR-2026-0001.json'), true);
    expect(substr((string) $zip->getFromName('dsar_report_DSAR-2026-0001.pdf'), 0, 4))->toBe('%PDF')
        ->and($zip->getFromName('manifest.sig'))->toContain('HMAC-SHA256')
        ->and($json['modules'])->toHaveKeys(['contacts', 'invoices', 'esm_tickets'])
        ->and($json['consent_history'])->toHaveCount(1);
    $zip->close();

    $this->withHeaders($this->headers)->get("/api/v1/privacy/dsar/{$dsar['id']}/download-package")->assertOk();
});

test('a tenant cannot read or act on the dsars of another tenant', function (): void {
    $dsar = dsarCreate();
    $other = PrivacyFixtures::tenant('Other AG');
    $headers = PrivacyFixtures::actAs(PrivacyFixtures::member($other), $other);

    expect($this->withHeaders($headers)->getJson('/api/v1/privacy/dsar')->json('data'))->toHaveCount(0);
    $this->withHeaders($headers)->getJson("/api/v1/privacy/dsar/{$dsar['id']}")->assertNotFound();
    $this->withHeaders($headers)->postJson("/api/v1/privacy/dsar/{$dsar['id']}/verify-identity", ['method' => 'x'])->assertStatus(422);  // the app maps a missing model of a POST to 422; the point is that the row is not reachable
});

test('invoices model is the one the guard reads', function (): void {
    expect((new Invoice)->getTable())->toBe('invoices');
});
