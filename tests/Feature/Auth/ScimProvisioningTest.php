<?php

declare(strict_types=1);

use App\Models\SsoProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Scim\ScimTokens;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->password = Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    [, $this->token] = app(ScimTokens::class)->rotate($this->tenant->id, null, null);
    [, $this->otherToken] = app(ScimTokens::class)->rotate($this->other->id, null, null);
});

function scimMember(Tenant $tenant, string $role, string $email, string $password): User
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

/**
 * @param  array<string, mixed>  $body
 */
function scimCall(string $method, string $path, ?string $token, array $body = []): TestResponse
{
    $request = test()->withHeaders(['Accept' => 'application/scim+json'] + ($token === null ? [] : ['Authorization' => 'Bearer '.$token]));

    return $request->json($method, '/api/v1/scim/v2'.$path, $body);
}

test('the administrator creates a SCIM token, sees it once, and rotation revokes the previous one', function (): void {
    $admin = scimMember($this->tenant, 'admin', 'laura.verdi@example.com', $this->password);
    Sanctum::actingAs($admin);

    $first = $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/security/scim/token')->assertCreated();
    $plain = $first->json('data.token');

    expect($plain)->toStartWith('scim_')
        ->and(DB::table('scim_tokens')->where('tenant_id', $this->tenant->id)->where('token_hash', $plain)->exists())->toBeFalse()
        ->and(DB::table('scim_tokens')->where('tenant_id', $this->tenant->id)->where('token_hash', hash('sha256', $plain))->exists())->toBeTrue();

    $status = $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/scim')->assertOk();
    expect($status->json('data.configured'))->toBeTrue()
        ->and($status->json('data.token_hint'))->toBe(substr($plain, -4))
        ->and($status->getContent())->not->toContain($plain);

    scimCall('GET', '/Users', $plain)->assertOk();

    $second = $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/security/scim/token')->assertCreated()->json('data.token');
    scimCall('GET', '/Users', $plain)->assertUnauthorized();
    scimCall('GET', '/Users', $second)->assertOk();

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->deleteJson('/api/v1/settings/security/scim/token')->assertOk();
    scimCall('GET', '/Users', $second)->assertUnauthorized();

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->pluck('action')->all();
    expect($actions)->toContain('SCIM_TOKEN_ROTATED', 'SCIM_TOKEN_REVOKED');
});

test('only owners and administrators manage the SCIM token', function (): void {
    Sanctum::actingAs(scimMember($this->tenant, 'member', 'marco.neri@example.com', $this->password));

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/settings/security/scim/token')->assertForbidden();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/scim')->assertForbidden();
});

test('a missing, wrong, expired or user token is refused', function (): void {
    scimCall('GET', '/Users', null)->assertUnauthorized()->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error');
    scimCall('GET', '/Users', 'scim_'.Str::random(48))->assertUnauthorized();

    $member = scimMember($this->tenant, 'admin', 'laura.verdi@example.com', $this->password);
    scimCall('GET', '/Users', $member->createToken('api')->plainTextToken)->assertUnauthorized();

    DB::table('scim_tokens')->where('tenant_id', $this->tenant->id)->update(['expires_at' => now()->subMinute()]);
    scimCall('GET', '/Users', $this->token)->assertUnauthorized();
});

test('a SCIM token does not open the application API, and a SCIM request needs no tenant header', function (): void {
    $this->withToken($this->token)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertUnauthorized();
    scimCall('GET', '/ServiceProviderConfig', $this->token)->assertOk()->assertJsonPath('patch.supported', true);
    scimCall('GET', '/ResourceTypes', $this->token)->assertOk()->assertJsonPath('Resources.0.id', 'User');
});

test('create, get, list and filter users', function (): void {
    $created = scimCall('POST', '/Users', $this->token, [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => 'Anna.Rossi@example.com',
        'externalId' => 'idp-123',
        'name' => ['givenName' => 'Anna', 'familyName' => 'Rossi'],
        'active' => true,
    ])->assertCreated();

    $id = $created->json('id');
    expect($created->json('userName'))->toBe('anna.rossi@example.com')
        ->and($created->json('externalId'))->toBe('idp-123')
        ->and($created->json('active'))->toBeTrue();

    $user = User::findOrFail($id);
    expect($user->getRoleInTenant($this->tenant->id))->toBe('member')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->belongsToTenant($this->other->id))->toBeFalse()
        ->and(DB::table('tenant_user')->where('user_id', $id)->value('provisioned_via'))->toBe('scim');

    scimCall('GET', '/Users/'.$id, $this->token)->assertOk()->assertJsonPath('name.givenName', 'Anna');
    scimCall('GET', '/Users', $this->token)->assertOk()->assertJsonPath('totalResults', 1);
    scimCall('GET', '/Users?filter='.rawurlencode('userName eq "ANNA.rossi@example.com"'), $this->token)->assertJsonPath('totalResults', 1);
    scimCall('GET', '/Users?filter='.rawurlencode('externalId eq "nobody"'), $this->token)->assertJsonPath('totalResults', 0);
    scimCall('GET', '/Users?filter='.rawurlencode('userName co "x"'), $this->token)->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
    expect(AuditLedgerEntry::query()->where('action', 'SCIM_USER_CREATED')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('a duplicate userName and an account that exists in another tenant are a 409', function (): void {
    scimMember($this->other, 'member', 'carla.bianchi@example.com', $this->password);
    scimMember($this->tenant, 'member', 'dario.gialli@example.com', $this->password);

    scimCall('POST', '/Users', $this->token, ['userName' => 'dario.gialli@example.com'])->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
    scimCall('POST', '/Users', $this->token, ['userName' => 'carla.bianchi@example.com'])->assertStatus(409);

    expect(DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('the role of a provisioned user comes from a valid roles entry or the default, never an owner', function (): void {
    $accountant = scimCall('POST', '/Users', $this->token, ['userName' => 'a@example.com', 'roles' => [['value' => 'accountant']]])->assertCreated()->json('id');
    $notOwner = scimCall('POST', '/Users', $this->token, ['userName' => 'b@example.com', 'roles' => [['value' => 'owner']]])->assertCreated()->json('id');

    expect(User::find($accountant)->getRoleInTenant($this->tenant->id))->toBe('accountant')
        ->and(User::find($notOwner)->getRoleInTenant($this->tenant->id))->toBe('member');
});

test('a tenant with an SSO provider only provisions addresses of its allowed domains', function (): void {
    SsoProvider::factory()->create(['tenant_id' => $this->tenant->id, 'allowed_email_domains' => ['example.com']]);

    scimCall('POST', '/Users', $this->token, ['userName' => 'x@evil.test'])->assertStatus(400);
    scimCall('POST', '/Users', $this->token, ['userName' => 'x@example.com'])->assertCreated();
});

test('invalid payloads are a 400', function (): void {
    scimCall('POST', '/Users', $this->token, [])->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
    scimCall('POST', '/Users', $this->token, ['userName' => 'not-an-email'])->assertStatus(400);
});

test('users of another tenant cannot be read, changed or deleted with this tenant token', function (): void {
    $foreign = scimMember($this->other, 'member', 'carla.bianchi@example.com', $this->password);
    $own = scimMember($this->tenant, 'member', 'dario.gialli@example.com', $this->password);

    scimCall('GET', '/Users/'.$foreign->id, $this->token)->assertNotFound();
    scimCall('PATCH', '/Users/'.$foreign->id, $this->token, ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]])->assertNotFound();
    scimCall('PUT', '/Users/'.$foreign->id, $this->token, ['userName' => $foreign->email, 'active' => false])->assertNotFound();
    scimCall('DELETE', '/Users/'.$foreign->id, $this->token)->assertNotFound();
    scimCall('GET', '/Users/not-a-uuid', $this->token)->assertNotFound();

    $list = scimCall('GET', '/Users', $this->token)->assertOk();
    expect($list->json('totalResults'))->toBe(1)
        ->and($list->json('Resources.0.id'))->toBe($own->id)
        ->and(DB::table('tenant_user')->where('user_id', $foreign->id)->value('suspended_at'))->toBeNull();

    // The same user ids seen with the token of their own tenant exist.
    scimCall('GET', '/Users/'.$foreign->id, $this->otherToken)->assertOk();
});

test('patching active suspends and restores the membership; the Entra string form works', function (): void {
    $user = scimMember($this->tenant, 'member', 'dario.gialli@example.com', $this->password);

    scimCall('PATCH', '/Users/'.$user->id, $this->token, ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]])
        ->assertOk()->assertJsonPath('active', false);
    expect(DB::table('tenant_user')->where('user_id', $user->id)->value('suspended_at'))->not->toBeNull()
        ->and($user->fresh()->belongsToTenant($this->tenant->id))->toBeFalse();

    scimCall('PATCH', '/Users/'.$user->id, $this->token, ['Operations' => [['op' => 'replace', 'value' => ['active' => true, 'name' => ['givenName' => 'Dario']]]]])
        ->assertOk()->assertJsonPath('active', true)->assertJsonPath('name.givenName', 'Dario');
    expect($user->fresh()->belongsToTenant($this->tenant->id))->toBeTrue();

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->pluck('action')->all();
    expect($actions)->toContain('SCIM_USER_DEACTIVATED', 'SCIM_USER_UPDATED');
});

test('a patch cannot change the userName and an unknown path is refused', function (): void {
    $user = scimMember($this->tenant, 'member', 'dario.gialli@example.com', $this->password);

    scimCall('PATCH', '/Users/'.$user->id, $this->token, ['Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 'takeover@example.com']]])
        ->assertStatus(400)->assertJsonPath('scimType', 'mutability');
    scimCall('PATCH', '/Users/'.$user->id, $this->token, ['Operations' => [['op' => 'replace', 'path' => 'password', 'value' => 'x']]])
        ->assertStatus(400)->assertJsonPath('scimType', 'noTarget');
    scimCall('PATCH', '/Users/'.$user->id, $this->token, [])->assertStatus(400);
    scimCall('PUT', '/Users/'.$user->id, $this->token, ['userName' => 'takeover@example.com'])->assertStatus(400);

    expect($user->fresh()->email)->toBe('dario.gialli@example.com');
});

test('delete deactivates: the SSO accesses of the tenant end, the user and the other tenants stay', function (): void {
    $user = scimMember($this->tenant, 'member', 'dario.gialli@example.com', $this->password);
    $user->tenants()->attach($this->other->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $ssoToken = $user->createToken('sso');
    $ssoToken->accessToken->forceFill(['auth_method' => 'sso', 'auth_tenant_id' => $this->tenant->id])->save();
    $passwordToken = $user->createToken('password');

    scimCall('DELETE', '/Users/'.$user->id, $this->token)->assertNoContent();
    scimCall('DELETE', '/Users/'.$user->id, $this->token)->assertNoContent();

    expect(User::find($user->id))->not->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->tokens()->count())->toBe(1)
        ->and($user->fresh()->belongsToTenant($this->tenant->id))->toBeFalse()
        ->and($user->fresh()->belongsToTenant($this->other->id))->toBeTrue()
        ->and(AuditLedgerEntry::query()->where('action', 'SCIM_USER_DEACTIVATED')->count())->toBe(1);

    $this->withToken($passwordToken->plainTextToken)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertForbidden();
});

test('deleting the only membership revokes every access of the user', function (): void {
    $user = scimMember($this->tenant, 'member', 'dario.gialli@example.com', $this->password);
    $user->createToken('one');
    $user->createToken('two');

    scimCall('DELETE', '/Users/'.$user->id, $this->token)->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $this->password])->assertForbidden();
});

test('profile attributes of a user who belongs to several tenants are not written by one tenant', function (): void {
    $user = scimMember($this->tenant, 'member', 'dario.gialli@example.com', $this->password);
    $user->forceFill(['first_name' => 'Dario'])->save();
    $user->tenants()->attach($this->other->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    scimCall('PATCH', '/Users/'.$user->id, $this->token, ['Operations' => [
        ['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Hacked'],
        ['op' => 'replace', 'path' => 'externalId', 'value' => 'ext-9'],
    ]])->assertOk()->assertJsonPath('externalId', 'ext-9');

    expect($user->fresh()->first_name)->toBe('Dario');
});

test('paging returns the requested window', function (): void {
    foreach (range(1, 5) as $index) {
        scimMember($this->tenant, 'member', "user{$index}@example.com", $this->password);
    }

    $page = scimCall('GET', '/Users?startIndex=2&count=2', $this->token)->assertOk();

    expect($page->json('totalResults'))->toBe(5)
        ->and($page->json('startIndex'))->toBe(2)
        ->and($page->json('itemsPerPage'))->toBe(2)
        ->and($page->json('Resources'))->toHaveCount(2);
});
