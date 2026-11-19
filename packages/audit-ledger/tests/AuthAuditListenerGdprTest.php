<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Tests;

use App\Models\Tenant;
use App\Models\User;
use Enterprise\AuditLedger\Listeners\AuthAuditListener;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\WithoutSeeding;
use Tests\TestCase;

uses(TestCase::class, WithoutSeeding::class, RefreshDatabase::class);

test('AuthAuditListener anonymizes IPv4 and IPv6 addresses according to GDPR policy', function (): void {
    expect(AuthAuditListener::anonymizeIp('203.0.113.42'))->toBe('203.0.113.0')
        ->and(AuthAuditListener::anonymizeIp('127.0.0.1'))->toBe('127.0.0.0')
        ->and(AuthAuditListener::anonymizeIp('2001:db8:85a3:1234:5678:8a2e:370:7334'))->toBe('2001:db8:85a3::')
        ->and(AuthAuditListener::anonymizeIp(null))->toBeNull()
        ->and(AuthAuditListener::anonymizeIp('not-an-ip'))->toBeNull();
});

test('AuthAuditListener redacts email PII on auth events and preserves hash chain after network metadata erasure', function (): void {
    $tenant = Tenant::create([
        'name' => 'GDPR Audit Tenant',
        'legal_name' => 'GDPR Audit Tenant AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $user = User::create([
        'name' => 'Claudia Meier',
        'email' => 'claudia.meier.gdpr@example.ch',
        'password' => bcrypt('SecretPassword123!'),
        'current_tenant_id' => $tenant->id,
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    request()->server->set('REMOTE_ADDR', '198.51.100.77');
    request()->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TestAgent/1.0');

    event(new Login('sanctum', $user, false));
    event(new Logout('sanctum', $user));
    event(new Failed('sanctum', null, ['email' => $user->email, 'password' => 'wrong-secret']));

    $entries = AuditLedgerEntry::query()->where('tenant_id', $tenant->id)->get();
    expect($entries)->toHaveCount(3);

    $serialized = json_encode($entries->toArray(), JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('claudia.meier.gdpr@example.ch')
        ->not->toContain('wrong-secret')
        ->not->toContain('198.51.100.77');

    $failedEntry = $entries->firstWhere('action', 'LOGIN_FAILED');
    expect($failedEntry)->not->toBeNull()
        ->and($failedEntry->ip_address)->toBe('198.51.100.0')
        ->and($failedEntry->payload_after['account_matched'])->toBeTrue()
        ->and($failedEntry->payload_after['identifier_hash'])->toHaveLength(16);

    // Purge network metadata for the data subject (GDPR Art. 17 right-to-erasure) and verify hash chain stays valid
    $purged = AuthAuditListener::purgeNetworkMetadata(userId: $user->id);
    expect($purged)->toBe(3);

    $afterPurge = AuditLedgerEntry::query()->where('tenant_id', $tenant->id)->get();
    foreach ($afterPurge as $entry) {
        expect($entry->ip_address)->toBeNull()
            ->and($entry->user_agent)->toBeNull();
    }

    $chainCheck = app(AuditChainVerificationService::class)->verifyTenantChain($tenant->id);
    expect($chainCheck['valid'])->toBeTrue()
        ->and($chainCheck['total_verified'])->toBe(3);
});
