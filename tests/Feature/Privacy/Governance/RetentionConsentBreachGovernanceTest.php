<?php

declare(strict_types=1);

use App\Models\Privacy\ConsentLedgerEntry;
use App\Models\Privacy\PrivacyRetentionRunLog;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Lead;
use Modules\HumanResources\Models\JobApplication;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 09:00:00');
    $this->tenant = PrivacyFixtures::tenant('Retention AG');
    $this->headers = PrivacyFixtures::actAs(PrivacyFixtures::member($this->tenant), $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function appFirstName(string $id): string
{
    return JobApplication::withoutGlobalScopes()->findOrFail($id)->first_name;
}

function leadEmail(string $id): string
{
    return Lead::withoutGlobalScopes()->findOrFail($id)->email;
}

test('the retention purge anonymises expired rejected cvs and inactive leads, keeps recent ones and never touches fiscal records', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa/seed-defaults')->assertOk();

    $old = JobApplication::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'rejected']);
    $recent = JobApplication::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'rejected']);
    $active = JobApplication::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
    DB::table('hr_job_applications')->where('id', $old->id)->update(['decided_at' => now()->subMonths(8), 'updated_at' => now()->subMonths(8)]);
    DB::table('hr_job_applications')->where('id', $recent->id)->update(['decided_at' => now()->subMonth()]);
    DB::table('hr_job_applications')->where('id', $active->id)->update(['updated_at' => now()->subYears(2)]);

    $oldLead = PrivacyFixtures::lead($this->tenant, now()->subYears(3)->toDateTimeString());
    $freshLead = PrivacyFixtures::lead($this->tenant, now()->subMonth()->toDateTimeString());
    $contact = PrivacyFixtures::contact($this->tenant);
    $invoice = PrivacyFixtures::invoice($this->tenant, $contact, '2017-01-01');
    $invoiceBefore = (array) DB::table('invoices')->where('id', $invoice->id)->first();

    $dry = $this->withHeaders($this->headers)->postJson('/api/v1/privacy/retention-rules/run-now?dry_run=1')->assertCreated()->json('data');
    expect($dry['dry_run'])->toBeTrue()->and($dry['anonymized_count'])->toBe(0)->and($dry['evaluated_count'])->toBeGreaterThanOrEqual(3);
    expect(appFirstName($old->id))->not->toStartWith('ANON-');

    $run = $this->withHeaders($this->headers)->postJson('/api/v1/privacy/retention-rules/run-now')->assertCreated()->json('data');
    expect($run['anonymized_count'])->toBe(2)->and($run['statutory_skipped_count'])->toBe(1);

    expect(appFirstName($old->id))->toStartWith('ANON-')
        ->and(appFirstName($recent->id))->not->toStartWith('ANON-')
        ->and(appFirstName($active->id))->not->toStartWith('ANON-')
        ->and(leadEmail($oldLead->id))->toStartWith('ANON-')
        ->and(leadEmail($freshLead->id))->not->toStartWith('ANON-')
        ->and((array) DB::table('invoices')->where('id', $invoice->id)->first())->toBe($invoiceBefore);

    // immutable audit trail of the run
    $log = PrivacyRetentionRunLog::withoutGlobalScope(TenantScope::class)->findOrFail($run['id']);
    expect(fn () => $log->update(['purged_count' => 99]))->toThrow(LogicException::class);

    // second run is idempotent
    $again = $this->withHeaders($this->headers)->postJson('/api/v1/privacy/retention-rules/run-now')->json('data');
    expect($again['anonymized_count'])->toBe(0);
});

test('the console command runs the purge for tenants with rules and fiscal rules cannot be edited', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa/seed-defaults')->assertOk();
    $this->artisan('privacy:run-retention-purge', ['--dry-run' => true])->assertSuccessful();
    expect(PrivacyRetentionRunLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenant->id)->count())->toBe(1);

    $rules = $this->withHeaders($this->headers)->getJson('/api/v1/privacy/retention-rules')->assertOk()->json('data');
    $fiscal = collect($rules)->firstWhere('module_code', 'MOD_026_INVOICES');
    $leads = collect($rules)->firstWhere('module_code', 'MOD_034_LEADS');
    $this->withHeaders($this->headers)->putJson("/api/v1/privacy/retention-rules/{$fiscal['id']}", ['retention_days' => 30])->assertUnprocessable();
    $this->withHeaders($this->headers)->putJson("/api/v1/privacy/retention-rules/{$leads['id']}", ['retention_days' => 365])->assertOk()->assertJsonPath('data.retention_days', 365);
});

test('the consent ledger chains entries by hash, is append only and detects tampering', function (): void {
    $record = fn (bool $granted) => $this->withHeaders($this->headers)->postJson('/api/v1/privacy/consents', [
        'subject_type' => 'lead', 'subject_identifier' => 'Lead@Example.com', 'purpose_code' => 'MARKETING_EMAIL', 'consent_granted' => $granted, 'policy_version' => '2026-01', 'proof_channel' => 'double_opt_in_email',
    ])->assertCreated()->json('data');

    $first = $record(true);
    $second = $record(false);
    expect($first['previous_entry_hash'])->toBe(ConsentLedgerEntry::GenesisHash)
        ->and($second['previous_entry_hash'])->toBe($first['entry_hash'])
        ->and($second['sequence'])->toBe(2);

    $list = $this->withHeaders($this->headers)->getJson('/api/v1/privacy/consents?subject_identifier=lead@example.com&consent_granted=0')->assertOk();
    expect($list->json('data'))->toHaveCount(1)->and($list->json('meta_chain.is_intact'))->toBeTrue();

    $entry = ConsentLedgerEntry::withoutGlobalScope(TenantScope::class)->findOrFail($first['id']);
    expect(fn () => $entry->update(['consent_granted' => false]))->toThrow(LogicException::class);

    DB::table('privacy_consent_ledger')->where('id', $first['id'])->update(['consent_granted' => false]);
    $tampered = $this->withHeaders($this->headers)->getJson('/api/v1/privacy/consents')->json('meta_chain');
    expect($tampered['is_intact'])->toBeFalse()->and($tampered['first_broken_sequence'])->toBe(1);
});

test('a breach gets a 72 hour deadline, a live countdown and a dossier for the competent authorities', function (): void {
    $payload = ['title' => 'Misdirected export', 'severity' => 'high', 'risk_to_rights_and_freedoms' => 'likely', 'jurisdiction' => 'both_ch_it', 'affected_subjects_count' => 12, 'detected_at' => now()->subHours(10)->toIso8601String()];
    $breach = $this->withHeaders($this->headers)->postJson('/api/v1/privacy/breaches', $payload)->assertCreated()->json('data');

    expect($breach['incident_code'])->toBe('BRE-2026-0001')
        ->and($breach['seconds_remaining'])->toBe(62 * 3600)
        ->and($breach['status'])->toBe('investigating');

    expect($this->withHeaders($this->headers)->getJson('/api/v1/privacy/breaches/active-countdown')->json('data'))->toHaveCount(1);

    $dossier = $this->withHeaders($this->headers)->getJson("/api/v1/privacy/breaches/{$breach['id']}/notification-dossier")->assertOk()->json('data');
    expect(collect($dossier['authorities'])->pluck('code')->all())->toBe(['IT_GDPR_Garante', 'CH_nLPD_FDPIC']);

    $updated = $this->withHeaders($this->headers)->putJson("/api/v1/privacy/breaches/{$breach['id']}", ['authority_notified_at' => now()->toIso8601String(), 'authority_reference_number' => 'GP-123'])->assertOk()->json('data');
    expect($updated['status'])->toBe('notified_authority')->and($updated['seconds_remaining'])->toBeNull();
    expect($this->withHeaders($this->headers)->getJson('/api/v1/privacy/breaches/active-countdown')->json('data'))->toHaveCount(0);

    $other = PrivacyFixtures::tenant('Other AG');
    $headers = PrivacyFixtures::actAs(PrivacyFixtures::member($other), $other);
    expect($this->withHeaders($headers)->getJson('/api/v1/privacy/breaches')->json('data'))->toHaveCount(0);
    $this->withHeaders($headers)->putJson("/api/v1/privacy/breaches/{$breach['id']}", ['title' => 'x'])->assertNotFound();
});
