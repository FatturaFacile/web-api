<?php

declare(strict_types=1);

use App\Models\Privacy\PrivacyRetentionPolicy;
use App\Models\Privacy\PrivacyRetentionRun;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Models\Lead;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = PrivacyFixtures::tenant('Retention AG');
    $this->admin = PrivacyFixtures::member($this->tenant);
    $this->headers = PrivacyFixtures::actAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a policy can only be saved for erasable tables that can be aged out', function (): void {
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/invoices', ['retention_days' => 365])->assertUnprocessable();
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/payroll_payslips', ['retention_days' => 365])->assertUnprocessable();
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/not_a_table', ['retention_days' => 365])->assertUnprocessable();
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/crm_leads', ['retention_days' => 0])->assertUnprocessable();

    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/crm_leads', ['retention_days' => 365, 'legal_basis_note' => 'Leads without activity'])
        ->assertOk()->assertJsonPath('data.entry_key', 'crm_leads')->assertJsonPath('data.retention_days', 365);

    $list = $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/retention-policies')->assertOk()->json();
    expect($list['data'])->toHaveCount(1)
        ->and(collect($list['available_tables'])->pluck('entry_key')->all())->toContain('crm_leads')->not->toContain('invoices');
});

test('a dry run counts the rows and changes nothing, a real run anonymises only the expired rows and is idempotent', function (): void {
    $old = PrivacyFixtures::lead($this->tenant, now()->subDays(400)->toDateTimeString());
    $recent = PrivacyFixtures::lead($this->tenant, now()->subDays(10)->toDateTimeString());
    $oldEmail = $old->email;
    $recentEmail = $recent->email;

    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/crm_leads', ['retention_days' => 365])->assertOk();

    // dry run is the default
    $dry = $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs')->assertOk()->json('data');
    expect($dry)->toHaveCount(1)
        ->and($dry[0]['dry_run'])->toBeTrue()
        ->and($dry[0]['matched_count'])->toBe(1)
        ->and($dry[0]['processed_count'])->toBe(0)
        ->and($dry[0]['details']['sample_ids'])->toBe([$old->id])
        ->and($old->fresh()->email)->toBe($oldEmail);

    $real = $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs', ['dry_run' => false])->assertOk()->json('data');
    expect($real[0]['dry_run'])->toBeFalse()
        ->and($real[0]['processed_count'])->toBe(1)
        ->and($old->fresh()->email)->toEndWith('@redacted.invalid')
        ->and($old->fresh()->first_name)->toBeNull()
        ->and($old->fresh()->anonymized_at)->not->toBeNull()
        ->and($recent->fresh()->email)->toBe($recentEmail);

    $again = $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs', ['dry_run' => false])->assertOk()->json('data');
    expect($again[0]['matched_count'])->toBe(0)->and($again[0]['processed_count'])->toBe(0);

    expect(PrivacyRetentionRun::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(3)
        ->and(AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('action', 'PRIVACY_RETENTION_APPLIED')->count())->toBe(2)
        ->and(AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('action', 'PRIVACY_RETENTION_DRY_RUN')->count())->toBe(1);

    $runs = $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/retention-runs')->assertOk()->json('data');
    expect($runs)->toHaveCount(3);
});

test('the scheduled command honours the dry run and applies the policies of every tenant, each on its own data', function (): void {
    $other = PrivacyFixtures::tenant('Other Retention AG');
    $mine = PrivacyFixtures::lead($this->tenant, now()->subDays(400)->toDateTimeString());
    $theirs = PrivacyFixtures::lead($other, now()->subDays(400)->toDateTimeString());
    $theirsEmail = $theirs->email;

    // only this tenant has a policy
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/crm_leads', ['retention_days' => 365])->assertOk();

    $this->artisan('privacy:apply-retention', ['--dry-run' => true])->assertSuccessful();
    expect($mine->fresh()->anonymized_at)->toBeNull();

    $this->artisan('privacy:apply-retention')->assertSuccessful();
    expect($mine->fresh()->anonymized_at)->not->toBeNull()
        ->and(Lead::withoutGlobalScopes()->find($theirs->id)->email)->toBe($theirsEmail)
        ->and(Lead::withoutGlobalScopes()->find($theirs->id)->anonymized_at)->toBeNull()
        ->and(PrivacyRetentionPolicy::withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(0);
});

test('policies and runs of a company are invisible to another company', function (): void {
    $this->withHeaders($this->headers)->putJson('/api/v1/settings/privacy/retention-policies/crm_leads', ['retention_days' => 365])->assertOk();
    $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs')->assertOk();

    $otherTenant = PrivacyFixtures::tenant('Intruder Retention AG');
    $otherHeaders = PrivacyFixtures::actAs(PrivacyFixtures::member($otherTenant), $otherTenant);

    expect($this->withHeaders($otherHeaders)->getJson('/api/v1/settings/privacy/retention-policies')->assertOk()->json('data'))->toBeEmpty()
        ->and($this->withHeaders($otherHeaders)->getJson('/api/v1/settings/privacy/retention-runs')->assertOk()->json('data'))->toBeEmpty();
    $this->withHeaders($otherHeaders)->deleteJson('/api/v1/settings/privacy/retention-policies/crm_leads')->assertNotFound();
});

test('the data map lists the registered tables with the rows of the company only', function (): void {
    PrivacyFixtures::contact($this->tenant);
    $other = PrivacyFixtures::tenant('Map Other AG');
    PrivacyFixtures::contact($other);
    PrivacyFixtures::contact($other);

    $map = $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/data-map')->assertOk()->json('data');
    $contacts = collect($map['tables'])->firstWhere('table', 'contacts');

    expect($contacts['rows'])->toBe(1)
        ->and($contacts['personal_columns'])->toContain('email', 'iban')
        ->and($contacts['undeclared_personal_columns'])->toBe([])
        ->and($contacts['retention'])->toBe('erasable')
        ->and(collect($map['tables'])->firstWhere('table', 'invoices')['retention'])->toBe('fiscal_record')
        ->and($map['unregistered_tables'])->toBeNull();

    $scanned = $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/data-map?scan=1')->assertOk()->json('data');
    expect($scanned['unregistered_tables'])->toBeArray();
});
