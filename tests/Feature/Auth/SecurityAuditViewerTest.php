<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\Security\SecurityAuditCatalog;
use App\Services\Tenancy\AccessAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    $this->admin = auditViewerMember($this->tenant, 'admin', 'laura.verdi@example.com');
    $this->member = auditViewerMember($this->tenant, 'member', 'marco.neri@example.com');
    $this->outsider = auditViewerMember($this->other, 'member', 'sara.gialli@example.com');
    $this->headers = ['X-Tenant-Id' => $this->tenant->id];
    $this->path = '/api/v1/settings/security/audit';
});

function auditViewerMember(Tenant $tenant, string $role, string $email): User
{
    $user = User::create([
        'name' => Str::before($email, '@'),
        'email' => $email,
        'password' => Hash::make(Str::password(20)),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

/**
 * Seeds a mix of events: five security events of the tenant on three different days, an access change that is not a
 * security event, and an event of another tenant.
 */
function auditViewerSeed(): void
{
    $audit = app(RegistrationAudit::class);
    $access = app(AccessAudit::class);
    $tenant = test()->tenant;
    $member = test()->member;

    // Far from midnight, and a minute apart, so the order and the day filters do not depend on when the test runs.
    test()->travelTo(now()->startOfDay()->addHours(10));
    $audit->record(RegistrationAudit::LOGIN_FAILED, $member->id, ['reason' => 'invalid_credentials'], $tenant->id);
    test()->travel(1)->days();
    $audit->record(RegistrationAudit::SSO_LOGIN_SUCCEEDED, $member->id, ['protocol' => 'saml', 'provider' => (string) Str::uuid()], $tenant->id);
    test()->travel(1)->minutes();
    $audit->record(RegistrationAudit::SSO_LOGIN_FAILED, $member->id, ['protocol' => 'oidc', 'reason' => 'nonce_mismatch'], $tenant->id);
    test()->travel(1)->days();
    $audit->record(RegistrationAudit::LOGIN_SUCCEEDED, $member->id, ['auth_method' => 'password', 'mfa_method' => 'totp'], $tenant->id);
    test()->travel(1)->minutes();
    $access->record('access.security.login_succeeded', $tenant->id, User::class, $member->id, null, ['authMethod' => 'passkey']);
    test()->travel(1)->minutes();
    $access->record('access.member.suspended', $tenant->id, User::class, $member->id, ['suspended' => false], ['suspended' => true]);
    test()->travel(1)->minutes();
    $audit->record(RegistrationAudit::LOGIN_FAILED, test()->outsider->id, ['reason' => 'invalid_credentials'], test()->other->id);
}

test('only administrators read the security audit', function (): void {
    $this->getJson($this->path)->assertUnauthorized();

    Sanctum::actingAs($this->member);
    $this->withHeaders($this->headers)->getJson($this->path)->assertForbidden();
    $this->withHeaders($this->headers)->getJson($this->path.'/event-types')->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->withHeaders($this->headers)->getJson($this->path)->assertOk()->assertJsonPath('meta.total', 0);
});

test('it lists only the security events of the tenant, newest first, with the structured fields', function (): void {
    auditViewerSeed();
    Sanctum::actingAs($this->admin);

    $response = $this->withHeaders($this->headers)->getJson($this->path)->assertOk()->assertJsonPath('meta.total', 5)->assertJsonPath('meta.per_page', 25);
    $items = collect($response->json('data'));

    expect($items->pluck('event_type')->all())->toBe(['ACCESS.SECURITY.LOGIN_SUCCEEDED', 'LOGIN_SUCCEEDED', 'SSO_LOGIN_FAILED', 'SSO_LOGIN_SUCCEEDED', 'LOGIN_FAILED'])
        ->and($items->every(fn (array $item): bool => $item['tenant_id'] === $this->tenant->id))->toBeTrue();

    expect(array_keys($items->first()))->toEqualCanonicalizing([
        'id', 'occurred_at', 'tenant_id', 'user_id', 'actor_user_id', 'user', 'event_type', 'category', 'translation_key', 'status',
        'ip_address', 'user_agent', 'auth_method', 'mfa_method', 'reason',
    ]);

    $failed = $items->firstWhere('event_type', 'LOGIN_FAILED');
    expect($failed['status'])->toBe('failure')
        ->and($failed['auth_method'])->toBe('password')
        ->and($failed['reason'])->toBe('invalid_credentials')
        ->and($failed['user_id'])->toBe($this->member->id)
        ->and($failed['user']['email'])->toBe($this->member->email)
        ->and($failed['translation_key'])->toBe('security.audit.event.login_failed');

    $password = $items->firstWhere('event_type', 'LOGIN_SUCCEEDED');
    expect($password['status'])->toBe('success')->and($password['mfa_method'])->toBe('totp')->and($password['auth_method'])->toBe('password');

    $passkey = $items->firstWhere('event_type', 'ACCESS.SECURITY.LOGIN_SUCCEEDED');
    expect($passkey['auth_method'])->toBe('passkey')->and($passkey['user_id'])->toBe($this->member->id)
        ->and($passkey['translation_key'])->toBe('security.audit.event.access_security_login_succeeded');

    expect($items->firstWhere('event_type', 'SSO_LOGIN_SUCCEEDED')['auth_method'])->toBe('sso_saml')
        ->and($items->firstWhere('event_type', 'SSO_LOGIN_FAILED')['auth_method'])->toBe('sso_oidc')
        ->and($items->firstWhere('event_type', 'SSO_LOGIN_FAILED')['reason'])->toBe('nonce_mismatch');

    // Never the details beyond the standard fields.
    expect(json_encode($response->json('data')))->not->toContain('provider');
});

test('another tenant sees none of it', function (): void {
    auditViewerSeed();
    $admin = auditViewerMember($this->other, 'admin', 'other.admin@example.com');
    Sanctum::actingAs($admin);

    $response = $this->withHeaders(['X-Tenant-Id' => $this->other->id])->getJson($this->path)->assertOk()->assertJsonPath('meta.total', 1);
    expect($response->json('data.0.user_id'))->toBe($this->outsider->id)
        ->and($response->json('data.0.tenant_id'))->toBe($this->other->id);

    $this->withHeaders(['X-Tenant-Id' => $this->other->id])->getJson($this->path.'?user_id='.$this->member->id)->assertOk()->assertJsonPath('meta.total', 0);
});

test('the filters narrow the list', function (): void {
    auditViewerSeed();
    Sanctum::actingAs($this->admin);
    $get = fn (string $query) => $this->withHeaders($this->headers)->getJson($this->path.'?'.$query)->assertOk();

    expect($get('event_type=LOGIN_FAILED')->json('meta.total'))->toBe(1)
        ->and($get('event_type=login_failed,sso_login_failed')->json('meta.total'))->toBe(2)
        ->and($get('status=failure')->json('meta.total'))->toBe(2)
        ->and($get('status=success')->json('meta.total'))->toBe(3)
        ->and($get('auth_method=sso_saml')->json('meta.total'))->toBe(1)
        ->and($get('auth_method=sso_oidc')->json('meta.total'))->toBe(1)
        ->and($get('auth_method=passkey')->json('meta.total'))->toBe(1)
        ->and($get('auth_method=password')->json('meta.total'))->toBe(2)
        ->and($get('user_id='.$this->member->id)->json('meta.total'))->toBe(5)
        ->and($get('user_id='.$this->admin->id)->json('meta.total'))->toBe(0)
        ->and($get('status=failure&auth_method=sso_oidc')->json('meta.total'))->toBe(1)
        ->and($get('per_page=5&page=1')->json('meta.last_page'))->toBe(1);

    $day = fn (int $offset): string => now()->subDays($offset)->toDateString();

    expect($get('from='.$day(0))->json('meta.total'))->toBe(2)
        ->and($get('to='.$day(1))->json('meta.total'))->toBe(3)
        ->and($get('from='.$day(1).'&to='.$day(1))->json('meta.total'))->toBe(2)
        ->and($get('from='.$day(5).'&to='.$day(4))->json('meta.total'))->toBe(0);
});

test('bad filters are refused', function (): void {
    Sanctum::actingAs($this->admin);
    $get = fn (string $query) => $this->withHeaders($this->headers)->getJson($this->path.'?'.$query);

    $get('event_type=NOT_AN_EVENT')->assertUnprocessable()->assertJsonValidationErrors('event_type');
    $get('status=maybe')->assertUnprocessable()->assertJsonValidationErrors('status');
    $get('auth_method=carrier-pigeon')->assertUnprocessable()->assertJsonValidationErrors('auth_method');
    $get('user_id=nope')->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $get('from=2028-03-02&to=2028-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $get('per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('the event types come with their translation keys, categories and status', function (): void {
    Sanctum::actingAs($this->admin);

    $types = collect($this->withHeaders($this->headers)->getJson($this->path.'/event-types')->assertOk()->json('data'));

    $locked = $types->firstWhere('event_type', 'LOGIN_LOCKED');
    expect($locked)->toMatchArray(['translation_key' => 'security.audit.event.login_locked', 'category' => 'lockout', 'status' => 'failure'])
        ->and($types->firstWhere('event_type', 'SCIM_GROUP_CREATED')['category'])->toBe('scim')
        ->and($types->firstWhere('event_type', 'LOGIN_UNLOCKED')['status'])->toBe('success')
        ->and($types->firstWhere('event_type', 'ACCESS.SECURITY.PASSKEY_REGISTERED')['category'])->toBe('passkey')
        ->and($types->pluck('event_type')->all())->toBe(SecurityAuditCatalog::types())
        ->and($types->pluck('event_type')->unique()->count())->toBe($types->count());
});
