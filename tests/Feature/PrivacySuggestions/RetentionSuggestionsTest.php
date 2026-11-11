<?php

declare(strict_types=1);

use App\Models\Privacy\PrivacyRetentionPolicy;
use App\Services\Privacy\PersonalDataRegistry;
use App\Services\Privacy\RetentionSuggestions;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = PrivacyFixtures::tenant('Suggestion AG');
    $this->admin = PrivacyFixtures::member($this->tenant);
    $this->headers = PrivacyFixtures::actAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the suggestions cover exactly the tables that can have a policy and are only data', function (): void {
    $registry = new PersonalDataRegistry;
    $capable = collect($registry->entries())->filter(fn ($entry): bool => $entry->supportsRetentionPolicy())->keys()->sort()->values()->all();

    expect(collect(array_keys(RetentionSuggestions::Suggested))->sort()->values()->all())->toBe($capable);

    $list = $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/retention-suggestions')->assertOk()->json('data');
    expect($list)->toHaveCount(count($capable));
    foreach ($list as $suggestion) {
        expect($suggestion['suggested_retention_days'])->toBeInt()->toBeGreaterThan(0)
            ->and($suggestion['requires_confirmation'])->toBeTrue()
            ->and($suggestion['applied_automatically'])->toBeFalse()
            ->and($suggestion['current_policy'])->toBeNull()
            ->and($suggestion['basis'])->toContain('not a legal requirement');
    }

    // listing never creates a policy and the scheduled command has nothing to apply
    expect(PrivacyRetentionPolicy::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    $this->artisan('privacy:apply-retention')->assertSuccessful();
});

test('a suggestion is adopted only on explicit confirmation, inactive by default, and never overwrites a policy', function (): void {
    $url = '/api/v1/settings/privacy/retention-suggestions/crm_leads/adopt';

    $this->withHeaders($this->headers)->postJson($url, [])->assertUnprocessable();
    $this->withHeaders($this->headers)->postJson($url, ['confirm' => false])->assertUnprocessable();
    expect(PrivacyRetentionPolicy::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);

    $policy = $this->withHeaders($this->headers)->postJson($url, ['confirm' => true])->assertCreated()->json('data');
    expect($policy['retention_days'])->toBe(730)->and($policy['is_active'])->toBeFalse();

    // inactive: a run does nothing
    $runs = $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-runs', ['dry_run' => false])->assertOk()->json('data');
    expect($runs)->toBe([]);

    $listed = collect($this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/retention-suggestions')->json('data'))->firstWhere('entry_key', 'crm_leads');
    expect($listed['current_policy'])->toBe(['retention_days' => 730, 'is_active' => false]);

    // a second adoption does not overwrite
    $this->withHeaders($this->headers)->postJson($url, ['confirm' => true, 'retention_days' => 10])->assertUnprocessable();
    // tables without a suggestion are refused
    $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-suggestions/invoices/adopt', ['confirm' => true])->assertUnprocessable();

    expect(AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('action', 'PRIVACY_RETENTION_POLICY_SAVED')->count())->toBe(1);
});

test('the suggestions are per company: the policy of another company does not show', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/v1/settings/privacy/retention-suggestions/crm_leads/adopt', ['confirm' => true, 'retention_days' => 400, 'activate' => true])->assertCreated();

    $other = PrivacyFixtures::tenant('Other Suggestion AG');
    $otherHeaders = PrivacyFixtures::actAs(PrivacyFixtures::member($other), $other);
    $listed = collect($this->withHeaders($otherHeaders)->getJson('/api/v1/settings/privacy/retention-suggestions')->assertOk()->json('data'))->firstWhere('entry_key', 'crm_leads');

    expect($listed['current_policy'])->toBeNull();
    $this->withHeaders($otherHeaders)->postJson('/api/v1/settings/privacy/retention-suggestions/crm_leads/adopt', ['confirm' => true])->assertCreated();
});

test('only owners and administrators reach the suggestions', function (): void {
    $member = PrivacyFixtures::member($this->tenant, 'sales');
    $headers = PrivacyFixtures::actAs($member, $this->tenant);

    $this->withHeaders($headers)->getJson('/api/v1/settings/privacy/retention-suggestions')->assertForbidden();
    $this->withHeaders($headers)->postJson('/api/v1/settings/privacy/retention-suggestions/crm_leads/adopt', ['confirm' => true])->assertForbidden();
});
