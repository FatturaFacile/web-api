<?php

declare(strict_types=1);

use App\Mail\AccountLockedMail;
use App\Mail\AccountUnlockedMail;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// These tests exercise the optional bearer-token mode, which is off by default: they turn it on and ask for it.
beforeEach(fn () => config(['auth.token_mode.enabled' => true]));

beforeEach(function (): void {
    $this->password = Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    $this->admin = lockoutMember($this->tenant, 'admin', 'laura.verdi@example.com', $this->password);
    $this->member = lockoutMember($this->tenant, 'member', 'marco.neri@example.com', $this->password);
    $this->outsider = lockoutMember($this->other, 'member', 'sara.gialli@example.com', $this->password);
});

function lockoutMember(Tenant $tenant, string $role, string $email, string $password): User
{
    $user = User::create([
        'name' => Str::before($email, '@'),
        'email' => $email,
        'password' => Hash::make($password),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function lockoutFail(User $user, int $times): void
{
    for ($attempt = 0; $attempt < $times; $attempt++) {
        test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-'.Str::random(8), 'token_mode' => true])->assertUnauthorized();
    }
}

function lockoutMinutes(User $user): int
{
    return (int) round(now()->diffInSeconds($user->fresh()->login_locked_until, false) / 60);
}

test('an administrator unlocks a member: counters cleared, audited, member told', function (): void {
    Mail::fake();
    $this->member->forceFill(['failed_login_attempts' => 2, 'login_locked_until' => now()->addHour(), 'lockout_streak' => 3])->save();

    Sanctum::actingAs($this->admin);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/users/'.$this->member->id.'/unlock')
        ->assertOk()
        ->assertJsonPath('data.id', $this->member->id)
        ->assertJsonPath('data.locked', false)
        ->assertJsonPath('data.locked_until', null)
        ->assertJsonPath('data.failed_attempts', 0);

    $fresh = $this->member->fresh();
    expect($fresh->login_locked_until)->toBeNull()->and($fresh->failed_login_attempts)->toBe(0)->and($fresh->lockout_streak)->toBe(0);

    $entry = AuditLedgerEntry::query()->where('action', 'LOGIN_UNLOCKED')->sole();
    expect($entry->tenant_id)->toBe($this->tenant->id)
        ->and($entry->payload_after['unlocked_by'])->toBe($this->admin->id)
        ->and($entry->payload_after['was_locked'])->toBeTrue()
        ->and($entry->payload_after['user_id'])->toBe($this->member->id);

    Mail::assertQueued(AccountUnlockedMail::class, fn (AccountUnlockedMail $mail): bool => $mail->hasTo($this->member->email));
});

test('only owners and administrators unlock, and only members of their own tenant', function (): void {
    Mail::fake();
    $this->member->forceFill(['login_locked_until' => now()->addHour()])->save();
    $this->outsider->forceFill(['login_locked_until' => now()->addHour()])->save();

    Sanctum::actingAs($this->member);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/users/'.$this->member->id.'/unlock')->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/users/'.$this->outsider->id.'/unlock')->assertNotFound();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/users/'.Str::uuid().'/unlock')->assertNotFound();

    expect($this->member->fresh()->login_locked_until)->not->toBeNull()->and($this->outsider->fresh()->login_locked_until)->not->toBeNull();
    expect(AuditLedgerEntry::query()->where('action', 'LOGIN_UNLOCKED')->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('the member list shows who is locked', function (): void {
    $this->member->forceFill(['failed_login_attempts' => 1, 'login_locked_until' => now()->addMinutes(30)])->save();

    Sanctum::actingAs($this->admin);
    $members = collect($this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/users')->assertOk()->json('data'));

    $locked = $members->firstWhere('id', $this->member->id);
    expect($locked['locked'])->toBeTrue()
        ->and($locked['locked_until'])->not->toBeNull()
        ->and($locked['failed_attempts'])->toBe(1)
        ->and($members->firstWhere('id', $this->admin->id)['locked'])->toBeFalse()
        ->and($members->firstWhere('id', $this->admin->id)['locked_until'])->toBeNull();

    $this->member->forceFill(['login_locked_until' => now()->subMinute()])->save();
    $expired = collect($this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/users')->json('data'))->firstWhere('id', $this->member->id);
    expect($expired['locked'])->toBeFalse()->and($expired['locked_until'])->toBeNull();
});

test('each consecutive lockout doubles the lock up to the cap, and a sign-in starts it over', function (): void {
    Mail::fake();
    TenantSecurityPolicy::factory()->create([
        'tenant_id' => $this->tenant->id, 'lockout_max_attempts' => 3, 'lockout_minutes' => 10, 'lockout_backoff' => true, 'lockout_max_minutes' => 50,
    ]);

    $durations = [];

    foreach ([1, 2, 3, 4] as $round) {
        lockoutFail($this->member, 3);
        $durations[] = lockoutMinutes($this->member);
        expect($this->member->fresh()->lockout_streak)->toBe($round);
        $this->travel(61)->minutes();
    }

    expect($durations)->toBe([10, 20, 40, 50]);

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk();
    expect($this->member->fresh()->lockout_streak)->toBe(0);

    lockoutFail($this->member, 3);
    expect(lockoutMinutes($this->member))->toBe(10);

    Mail::assertQueued(AccountLockedMail::class, 5);
});

test('without the progressive lockout the lock keeps the base duration', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'lockout_max_attempts' => 3, 'lockout_minutes' => 10]);

    foreach ([1, 2, 3] as $round) {
        lockoutFail($this->member, 3);
        expect(lockoutMinutes($this->member))->toBe(10);
        $this->travel(11)->minutes();
    }
});

test('a lockout is audited with its length and streak, and the user gets an email', function (): void {
    Mail::fake();
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'lockout_max_attempts' => 3, 'lockout_minutes' => 15, 'lockout_backoff' => true]);

    lockoutFail($this->member, 3);

    $entry = AuditLedgerEntry::query()->where('action', 'LOGIN_LOCKED')->sole();
    expect($entry->tenant_id)->toBe($this->tenant->id)
        ->and($entry->payload_after['minutes'])->toBe(15)
        ->and($entry->payload_after['streak'])->toBe(1)
        ->and($entry->payload_after['status'])->toBe('failure')
        ->and($entry->payload_after['auth_method'])->toBe('password');

    Mail::assertQueued(AccountLockedMail::class, fn (AccountLockedMail $mail): bool => $mail->hasTo($this->member->email) && $mail->minutes === 15);
});

test('failed and successful password sign-ins are audited with the standard keys', function (): void {
    lockoutFail($this->member, 1);
    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk();

    $failed = AuditLedgerEntry::query()->where('action', 'LOGIN_FAILED')->where('payload_after->auth_method', 'password')->sole();
    expect($failed->tenant_id)->toBe($this->tenant->id)
        ->and($failed->payload_after)->toMatchArray(['reason' => 'invalid_credentials', 'auth_method' => 'password', 'status' => 'failure', 'user_id' => $this->member->id]);

    $succeeded = AuditLedgerEntry::query()->where('action', 'LOGIN_SUCCEEDED')->sole();
    expect($succeeded->payload_after)->toMatchArray(['auth_method' => 'password', 'status' => 'success']);
});

test('the policy takes the progressive lockout and the breached password check', function (): void {
    Sanctum::actingAs($this->admin);
    $headers = ['X-Tenant-Id' => $this->tenant->id];

    $this->withHeaders($headers)->getJson('/api/v1/settings/security/policy')->assertOk()
        ->assertJsonPath('data.lockout.backoff', false)->assertJsonPath('data.lockout.max_minutes', 1440)->assertJsonPath('data.password.check_breached', false);

    $this->withHeaders($headers)->putJson('/api/v1/settings/security/policy', [
        'lockout' => ['max_attempts' => 5, 'minutes' => 10, 'backoff' => true, 'max_minutes' => 240],
        'password' => ['check_breached' => true],
    ])->assertOk()
        ->assertJsonPath('data.lockout.backoff', true)->assertJsonPath('data.lockout.max_minutes', 240)->assertJsonPath('data.password.check_breached', true);

    $policy = TenantSecurityPolicy::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
    expect($policy->lockout_backoff)->toBeTrue()->and($policy->lockout_max_minutes)->toBe(240)->and($policy->password_check_breached)->toBeTrue();

    $this->withHeaders($headers)->putJson('/api/v1/settings/security/policy', ['lockout' => ['minutes' => 60, 'max_minutes' => 30]])
        ->assertUnprocessable()->assertJsonValidationErrors('lockout.max_minutes');
    $this->withHeaders($headers)->putJson('/api/v1/settings/security/policy', ['lockout' => ['backoff' => 'maybe'], 'password' => ['check_breached' => 'maybe']])
        ->assertUnprocessable()->assertJsonValidationErrors(['lockout.backoff', 'password.check_breached']);
});
