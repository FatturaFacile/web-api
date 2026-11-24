<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function impUser(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => Str::password(20), 'email_verified_at' => now()]);
    $tenant->users()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function impAct(User $user, Tenant $tenant): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    Artisan::call('permissions:sync');
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->owner = impUser($this->tenant, 'owner');
    $this->member = impUser($this->tenant, 'member');
});

test('an owner impersonates a member for 30 minutes at most and both ends reach the audit ledger', function (): void {
    impAct($this->owner, $this->tenant);

    $this->postJson("/api/v1/settings/users/{$this->member->id}/impersonate", ['consent_reference' => 'ticket-1042'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'expires_at']]);

    $token = $this->member->tokens()->where('name', 'like', 'impersonation:%')->firstOrFail();
    expect($token->expires_at->diffInMinutes(now(), true))->toBeLessThanOrEqual(30);

    $this->deleteJson("/api/v1/settings/users/{$this->member->id}/impersonate")->assertOk()->assertJsonPath('data.closed', 1);

    expect($this->member->tokens()->count())->toBe(0)
        ->and(DB::table('enterprise_audit_ledger')->where('action', 'ACCESS.SECURITY.IMPERSONATION_STARTED')->count())->toBe(1)
        ->and(DB::table('enterprise_audit_ledger')->where('action', 'ACCESS.SECURITY.IMPERSONATION_ENDED')->count())->toBe(1);
});

test('a requested lifetime above 30 minutes is refused', function (): void {
    impAct($this->owner, $this->tenant);

    $this->postJson("/api/v1/settings/users/{$this->member->id}/impersonate", ['consent_reference' => 'ticket-1', 'ttl_minutes' => 120])
        ->assertUnprocessable();
});

test('a recorded consent is required', function (): void {
    impAct($this->owner, $this->tenant);

    $this->postJson("/api/v1/settings/users/{$this->member->id}/impersonate", [])->assertUnprocessable();
});

test('an administrator, an owner target and oneself are refused', function (): void {
    $admin = impUser($this->tenant, 'admin');
    impAct($admin, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$this->member->id}/impersonate", ['consent_reference' => 'x'])->assertForbidden();

    impAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$admin->id}/impersonate", ['consent_reference' => 'x'])->assertCreated();
    $this->postJson("/api/v1/settings/users/{$this->owner->id}/impersonate", ['consent_reference' => 'x'])->assertForbidden();

    $secondOwner = impUser($this->tenant, 'owner');
    $this->postJson("/api/v1/settings/users/{$secondOwner->id}/impersonate", ['consent_reference' => 'x'])->assertForbidden();
});

test('a member of another tenant cannot be impersonated', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $stranger = impUser($other, 'member');
    impAct($this->owner, $this->tenant);

    $this->postJson("/api/v1/settings/users/{$stranger->id}/impersonate", ['consent_reference' => 'x'])->assertNotFound();
});

test('the access review lists the members of the tenant only, flagging inactivity after 90 days', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    impUser($other, 'member');
    $this->member->createToken('phone')->accessToken->forceFill(['last_used_at' => now()->subDays(120)])->save();
    $this->owner->createToken('web')->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();

    impAct($this->owner, $this->tenant);
    $rows = collect($this->getJson('/api/v1/settings/access-review')->assertOk()->json('data'))->keyBy('user_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$this->member->id]['inactive'])->toBeTrue()
        ->and($rows[$this->owner->id]['inactive'])->toBeFalse();
});

test('a member cannot read the access review', function (): void {
    impAct($this->member, $this->tenant);

    $this->getJson('/api/v1/settings/access-review')->assertForbidden();
});

test('the profile sessions paths list and revoke the own accesses', function (): void {
    config(['auth.token_mode.enabled' => true]);
    $token = $this->member->createToken('phone');

    app('auth')->forgetGuards();
    $this->withToken($token->plainTextToken)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/profile/sessions')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token->plainTextToken)->withHeader('X-Tenant-Id', $this->tenant->id)
        ->deleteJson('/api/v1/profile/sessions/'.$token->accessToken->id)->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token->plainTextToken)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/profile/sessions')->assertUnauthorized();
});

test('every IAM route is decided by a permission key, except the ones of the own account', function (): void {
    $own = ['api.v1.iam.passkeys.index', 'api.v1.iam.passkeys.options', 'api.v1.iam.passkeys.store', 'api.v1.iam.passkeys.destroy',
        'api.v1.iam.2fa.totp.enable', 'api.v1.iam.2fa.totp.confirm', 'api.v1.iam.2fa.totp.disable',
        'api.v1.iam.profile.sessions.index', 'api.v1.iam.profile.sessions.destroy', 'api.v1.iam.profile.permissions'];

    $open = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'api.v1.iam.'))
        ->reject(fn ($route): bool => in_array($route->getName(), $own, true))
        ->reject(fn ($route): bool => collect($route->gatherMiddleware())->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'can:')))
        ->map(fn ($route): ?string => $route->getName())
        ->values()->all();

    expect($open)->toBe([]);
});

test('the permission snapshot tells an impersonated access apart', function (): void {
    config(['auth.token_mode.enabled' => true]);
    impAct($this->owner, $this->tenant);
    $plain = $this->postJson("/api/v1/settings/users/{$this->member->id}/impersonate", ['consent_reference' => 'ticket-7'])->json('data.token');

    app('auth')->forgetGuards();
    $this->withToken($plain)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/profile/permissions')
        ->assertOk()->assertJsonPath('data.impersonation.active', true)->assertJsonPath('data.impersonation.actor_id', $this->owner->id);

    impAct($this->member, $this->tenant);
    $this->getJson('/api/v1/profile/permissions')->assertOk()->assertJsonPath('data.impersonation.active', false);
});
