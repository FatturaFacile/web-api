<?php

declare(strict_types=1);

use App\Models\Privacy\PrivacyDataBreach;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 09:00:00');
    $this->tenant = PrivacyFixtures::tenant('Breach AG');
    $this->admin = PrivacyFixtures::member($this->tenant);
    $this->headers = PrivacyFixtures::actAs($this->admin, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function breachRegister(array $extra = []): array
{
    return test()->withHeaders(test()->headers)->postJson('/api/v1/settings/privacy/breaches', [
        'title' => 'Misdirected export',
        'severity' => 'high',
        'data_categories' => ['email'],
        ...$extra,
    ])->assertCreated()->json('data');
}

function breachStep(string $id, string $step, array $body = [], int $status = 200): array
{
    return test()->withHeaders(test()->headers)->postJson("/api/v1/settings/privacy/breaches/{$id}/{$step}", $body)->assertStatus($status)->json();
}

test('a breach tracks the hours left to the 72 hour notification and flags it when overdue', function (): void {
    $breach = breachRegister(['discovered_at' => now()->subHours(48)->toIso8601String(), 'description' => 'Wrong recipient', 'affected_subjects_count' => 3]);

    expect($breach['hours_remaining'])->toEqual(24.0)
        ->and($breach['is_notification_overdue'])->toBeFalse()
        ->and($breach['requires_authority_notification'])->toBeTrue()
        ->and($breach['affected_subjects_count'])->toBe(3);

    $this->travel(30)->hours();
    $shown = $this->withHeaders($this->headers)->getJson("/api/v1/settings/privacy/breaches/{$breach['id']}")->assertOk()->json('data');
    expect($shown['hours_remaining'])->toEqual(-6.0)->and($shown['is_notification_overdue'])->toBeTrue();

    $overdue = $this->withHeaders($this->headers)->getJson('/api/v1/settings/privacy/breaches')->assertOk()->json('data');
    expect($overdue[0]['is_notification_overdue'])->toBeTrue();
});

test('a notification after the 72 hours needs the reason for the delay', function (): void {
    $breach = breachRegister(['discovered_at' => now()->subHours(100)->toIso8601String()]);

    breachStep($breach['id'], 'notify-authority', ['regulation' => 'GDPR'], 422);
    $notified = breachStep($breach['id'], 'notify-authority', ['regulation' => 'GDPR', 'delay_reason' => 'Scope of the incident became clear later'])['data'];

    expect($notified['status'])->toBe('notified')
        ->and($notified['gdpr_authority_notified_at'])->not->toBeNull()
        ->and($notified['is_notification_overdue'])->toBeTrue()
        ->and($notified['delay_reason'])->toBe('Scope of the incident became clear later')
        ->and($notified['hours_remaining'])->toBeNull();

    // once only
    breachStep($breach['id'], 'notify-authority', ['regulation' => 'GDPR', 'delay_reason' => 'again'], 422);
});

test('a timely notification to the Swiss FDPIC is recorded without delay reason', function (): void {
    $breach = breachRegister(['discovered_at' => now()->subHours(10)->toIso8601String()]);

    $notified = breachStep($breach['id'], 'notify-authority', ['regulation' => 'nLPD'])['data'];

    expect($notified['fdpic_notified_at'])->not->toBeNull()->and($notified['is_notification_overdue'])->toBeFalse();
});

test('a breach closes only after the authority and the data subjects were notified', function (): void {
    $breach = breachRegister(['discovered_at' => now()->subHours(1)->toIso8601String()]);

    breachStep($breach['id'], 'contain', ['mitigation_measures' => 'Access revoked']);
    breachStep($breach['id'], 'close', [], 422);

    breachStep($breach['id'], 'notify-authority', ['regulation' => 'GDPR']);
    breachStep($breach['id'], 'close', [], 422);

    breachStep($breach['id'], 'notify-subjects');
    $closed = breachStep($breach['id'], 'close')['data'];

    expect($closed['status'])->toBe('closed')->and($closed['closed_at'])->not->toBeNull()->and($closed['contained_at'])->not->toBeNull();
    breachStep($breach['id'], 'contain', [], 422);

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('module_code', 'PRIVACY')->pluck('action')->all();
    expect($actions)->toContain('PRIVACY_BREACH_REGISTERED', 'PRIVACY_BREACH_CONTAINED', 'PRIVACY_BREACH_AUTHORITY_NOTIFIED', 'PRIVACY_BREACH_SUBJECTS_NOTIFIED', 'PRIVACY_BREACH_CLOSED');
});

test('a low risk breach can be closed with a documented decision that no notification is required', function (): void {
    $breach = breachRegister(['severity' => 'medium']);

    breachStep($breach['id'], 'notification-not-required', [], 422);
    $decided = breachStep($breach['id'], 'notification-not-required', ['reason' => 'Data were encrypted and the key was not exposed'])['data'];

    expect($decided['requires_authority_notification'])->toBeFalse()->and($decided['authority_not_required_reason'])->not->toBeNull();
    expect(breachStep($breach['id'], 'close')['data']['status'])->toBe('closed');
});

test('breaches of a company cannot be read or changed by another company', function (): void {
    $breach = breachRegister();

    $otherTenant = PrivacyFixtures::tenant('Intruder Breach AG');
    $otherHeaders = PrivacyFixtures::actAs(PrivacyFixtures::member($otherTenant), $otherTenant);

    $this->withHeaders($otherHeaders)->getJson("/api/v1/settings/privacy/breaches/{$breach['id']}")->assertNotFound();
    $this->withHeaders($otherHeaders)->postJson("/api/v1/settings/privacy/breaches/{$breach['id']}/close")->assertNotFound();
    expect($this->withHeaders($otherHeaders)->getJson('/api/v1/settings/privacy/breaches')->assertOk()->json('data'))->toBeEmpty()
        ->and(PrivacyDataBreach::withoutGlobalScopes()->whereKey($breach['id'])->value('status'))->toBe('investigating');
});
