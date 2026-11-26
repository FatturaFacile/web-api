<?php

declare(strict_types=1);

use App\Models\ScimGroupMapping;
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
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
    [, $this->token] = app(ScimTokens::class)->rotate($this->tenant->id, null, null);
    [, $this->otherToken] = app(ScimTokens::class)->rotate($this->other->id, null, null);
    $this->admin = scimGroupMember($this->tenant, 'admin', 'laura.verdi@example.com');
    $this->member = scimGroupMember($this->tenant, 'member', 'marco.neri@example.com');
    $this->owner = scimGroupMember($this->tenant, 'owner', 'olga.bianchi@example.com');
});

function scimGroupMember(Tenant $tenant, string $role, string $email): User
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
 * @param  array<string, mixed>  $body
 */
function scimGroupCall(string $method, string $path, ?string $token, array $body = []): TestResponse
{
    $request = test()->withHeaders(['Accept' => 'application/scim+json'] + ($token === null ? [] : ['Authorization' => 'Bearer '.$token]));

    return $request->json($method, '/api/v1/scim/v2'.$path, $body);
}

function scimGroupMap(Tenant $tenant, string $name, string $role): ScimGroupMapping
{
    $mapping = new ScimGroupMapping(['group_name' => $name, 'role' => $role]);
    $mapping->forceFill(['tenant_id' => $tenant->id])->save();

    return $mapping;
}

function scimGroupRole(Tenant $tenant, User $user): ?string
{
    return DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('role');
}

/**
 * @param  list<User>  $members
 * @return array<string, mixed>
 */
function scimGroupBody(string $name, array $members = []): array
{
    return [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
        'displayName' => $name,
        'members' => array_map(fn (User $user): array => ['value' => $user->id], $members),
    ];
}

function scimPatch(string $op, string $path, mixed $value = null): array
{
    return ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [array_filter(['op' => $op, 'path' => $path, 'value' => $value], fn (mixed $part): bool => $part !== null)]];
}

test('the group endpoints need the bearer token of a tenant', function (): void {
    scimGroupCall('GET', '/Groups', null)->assertUnauthorized()->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error');
    scimGroupCall('GET', '/Groups', 'scim_not-a-token')->assertUnauthorized();
    scimGroupCall('POST', '/Groups', null, scimGroupBody('Accounting'))->assertUnauthorized();
    scimGroupCall('GET', '/Schemas', null)->assertUnauthorized();
});

test('a group is created, read back with its members, listed and filtered by displayName', function (): void {
    $created = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('ERP Accounting', [$this->member]) + ['externalId' => 'idp-1'])
        ->assertCreated()
        ->assertJsonPath('displayName', 'ERP Accounting')
        ->assertJsonPath('externalId', 'idp-1')
        ->assertJsonPath('members.0.value', $this->member->id)
        ->assertJsonPath('members.0.type', 'User');
    $id = $created->json('id');

    scimGroupCall('GET', '/Groups/'.$id, $this->token)->assertOk()->assertJsonPath('members.0.display', $this->member->name);
    scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Sales'))->assertCreated();

    scimGroupCall('GET', '/Groups', $this->token)->assertOk()->assertJsonPath('totalResults', 2)->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:ListResponse');
    scimGroupCall('GET', '/Groups?filter='.rawurlencode('displayName eq "erp accounting"'), $this->token)->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.id', $id);
    scimGroupCall('GET', '/Groups?filter='.rawurlencode('displayName eq "nope"'), $this->token)->assertJsonPath('totalResults', 0);
    scimGroupCall('GET', '/Groups?startIndex=2&count=1', $this->token)->assertJsonPath('itemsPerPage', 1)->assertJsonPath('startIndex', 2);
    scimGroupCall('GET', '/Groups?filter='.rawurlencode('members pr'), $this->token)->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');

    expect(AuditLedgerEntry::query()->where('action', 'SCIM_GROUP_CREATED')->count())->toBe(2);
});

test('a group name is unique in the tenant but free in another one', function (): void {
    scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting'))->assertCreated();
    scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('ACCOUNTING'))->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
    scimGroupCall('POST', '/Groups', $this->otherToken, scimGroupBody('Accounting'))->assertCreated();
    scimGroupCall('POST', '/Groups', $this->token, ['displayName' => ''])->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
});

test('only members of the tenant can join a group', function (): void {
    $stranger = scimGroupMember($this->other, 'member', 'sara.gialli@example.com');

    scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting', [$stranger]))->assertStatus(400)->assertJsonPath('scimType', 'invalidValue');
    scimGroupCall('POST', '/Groups', $this->token, ['displayName' => 'Accounting', 'members' => [['value' => 'not-a-uuid']]])->assertStatus(400);
    expect(DB::table('scim_groups')->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('PATCH adds, removes and replaces members and renames the group', function (): void {
    $other = scimGroupMember($this->tenant, 'member', 'anna.rossi@example.com');
    $id = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting', [$this->member]))->json('id');

    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('Add', 'members', [['value' => $other->id]]))
        ->assertOk()->assertJsonCount(2, 'members');
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('remove', 'members[value eq "'.$this->member->id.'"]'))
        ->assertOk()->assertJsonCount(1, 'members')->assertJsonPath('members.0.value', $other->id);
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('replace', 'members', [['value' => $this->member->id]]))
        ->assertOk()->assertJsonCount(1, 'members')->assertJsonPath('members.0.value', $this->member->id);
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('remove', 'members', [['value' => $this->member->id]]))
        ->assertOk()->assertJsonCount(0, 'members');
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('replace', 'displayName', 'Finance'))
        ->assertOk()->assertJsonPath('displayName', 'Finance');
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, ['Operations' => [['op' => 'add', 'value' => ['displayName' => 'Treasury', 'members' => [['value' => $other->id]]]]]])
        ->assertOk()->assertJsonPath('displayName', 'Treasury')->assertJsonCount(1, 'members');

    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('replace', 'colour', 'red'))->assertStatus(400)->assertJsonPath('scimType', 'noTarget');
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, ['Operations' => []])->assertStatus(400)->assertJsonPath('scimType', 'invalidSyntax');
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('remove', 'displayName'))->assertStatus(400);

    scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Sales'))->assertCreated();
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('replace', 'displayName', 'sales'))->assertStatus(409);

    expect(AuditLedgerEntry::query()->where('action', 'SCIM_GROUP_MEMBERS_CHANGED')->count())->toBeGreaterThanOrEqual(4);
});

test('PUT replaces the group and removes the members that are not sent', function (): void {
    $other = scimGroupMember($this->tenant, 'member', 'anna.rossi@example.com');
    $id = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting', [$this->member, $other]))->json('id');

    scimGroupCall('PUT', '/Groups/'.$id, $this->token, scimGroupBody('Accounting Team', [$other]))
        ->assertOk()->assertJsonPath('displayName', 'Accounting Team')->assertJsonCount(1, 'members')->assertJsonPath('members.0.value', $other->id);
    scimGroupCall('PUT', '/Groups/'.$id, $this->token, ['members' => []])->assertStatus(400);
});

test('a mapped group gives its members the role and takes it back, and never touches the owner', function (): void {
    scimGroupMap($this->tenant, 'ERP Accounting', 'accountant');

    $id = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('erp accounting', [$this->member, $this->owner]))->assertCreated()->json('id');

    expect(scimGroupRole($this->tenant, $this->member))->toBe('accountant')
        ->and(scimGroupRole($this->tenant, $this->owner))->toBe('owner');

    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('remove', 'members[value eq "'.$this->member->id.'"]'))->assertOk();
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('remove', 'members[value eq "'.$this->owner->id.'"]'))->assertOk();

    expect(scimGroupRole($this->tenant, $this->member))->toBe('member')
        ->and(scimGroupRole($this->tenant, $this->owner))->toBe('owner');
    expect(AuditLedgerEntry::query()->where('action', 'SCIM_ROLE_APPLIED')->count())->toBe(2);
});

test('the oldest mapping wins and the role goes when the last mapped group goes', function (): void {
    scimGroupMap($this->tenant, 'Sales', 'sales');
    $this->travel(2)->seconds();
    scimGroupMap($this->tenant, 'Accounting', 'accountant');
    $sales = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Sales', [$this->member]))->json('id');
    $accounting = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting', [$this->member]))->json('id');

    expect(scimGroupRole($this->tenant, $this->member))->toBe('sales');

    scimGroupCall('DELETE', '/Groups/'.$sales, $this->token)->assertNoContent();
    expect(scimGroupRole($this->tenant, $this->member))->toBe('accountant');

    scimGroupCall('DELETE', '/Groups/'.$accounting, $this->token)->assertNoContent();
    expect(scimGroupRole($this->tenant, $this->member))->toBe('member');
    scimGroupCall('GET', '/Groups/'.$accounting, $this->token)->assertNotFound();
});

test('a role that an administrator changed by hand after the grant is not taken back', function (): void {
    scimGroupMap($this->tenant, 'Accounting', 'accountant');
    $id = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting', [$this->member]))->json('id');

    DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->member->id)->update(['role' => 'sales_manager']);

    scimGroupCall('DELETE', '/Groups/'.$id, $this->token)->assertNoContent();

    expect(scimGroupRole($this->tenant, $this->member))->toBe('sales_manager');
    $row = DB::table('tenant_user')->where('user_id', $this->member->id)->first();
    expect($row->scim_granted_role)->toBeNull()->and($row->scim_base_role)->toBeNull();
});

test('a group without a mapping never changes a role', function (): void {
    $id = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Unmapped', [$this->member, $this->admin]))->json('id');
    scimGroupCall('DELETE', '/Groups/'.$id, $this->token)->assertNoContent();

    expect(scimGroupRole($this->tenant, $this->member))->toBe('member')->and(scimGroupRole($this->tenant, $this->admin))->toBe('admin');
});

test('a group of another tenant does not exist', function (): void {
    $id = scimGroupCall('POST', '/Groups', $this->otherToken, scimGroupBody('Accounting'))->json('id');

    scimGroupCall('GET', '/Groups/'.$id, $this->token)->assertNotFound();
    scimGroupCall('PATCH', '/Groups/'.$id, $this->token, scimPatch('replace', 'displayName', 'x'))->assertNotFound();
    scimGroupCall('PUT', '/Groups/'.$id, $this->token, scimGroupBody('x'))->assertNotFound();
    scimGroupCall('DELETE', '/Groups/'.$id, $this->token)->assertNotFound();
    scimGroupCall('GET', '/Groups/not-a-uuid', $this->token)->assertNotFound();
    scimGroupCall('GET', '/Groups', $this->token)->assertJsonPath('totalResults', 0);
});

test('the discovery documents describe users and groups', function (): void {
    scimGroupCall('GET', '/ResourceTypes', $this->token)->assertOk()
        ->assertJsonPath('totalResults', 2)->assertJsonPath('Resources.0.id', 'User')->assertJsonPath('Resources.1.id', 'Group')->assertJsonPath('Resources.1.endpoint', '/Groups');

    $schemas = scimGroupCall('GET', '/Schemas', $this->token)->assertOk()->assertJsonPath('totalResults', 2);
    expect(collect($schemas->json('Resources'))->pluck('id')->all())->toBe([
        'urn:ietf:params:scim:schemas:core:2.0:User',
        'urn:ietf:params:scim:schemas:core:2.0:Group',
    ]);

    scimGroupCall('GET', '/Schemas/urn:ietf:params:scim:schemas:core:2.0:Group', $this->token)->assertOk()->assertJsonPath('name', 'Group')->assertJsonPath('attributes.0.name', 'displayName');
    scimGroupCall('GET', '/Schemas/urn:unknown', $this->token)->assertNotFound();

    scimGroupCall('GET', '/ServiceProviderConfig', $this->token)->assertOk()
        ->assertJsonPath('patch.supported', true)->assertJsonPath('bulk.supported', false)->assertJsonPath('filter.supported', true);
});

test('an inactive user loses the membership and every session', function (): void {
    $created = scimGroupCall('POST', '/Users', $this->token, ['userName' => 'new.hire@example.com', 'displayName' => 'New Hire'])->assertCreated();
    $user = User::query()->findOrFail($created->json('id'));
    $token = $user->createToken('sso');
    $token->accessToken->forceFill(['auth_tenant_id' => $this->tenant->id])->save();
    $user->createToken('password');
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    scimGroupCall('PATCH', '/Users/'.$user->id, $this->token, scimPatch('replace', 'active', false))->assertOk()->assertJsonPath('active', false);

    expect(DB::table('tenant_user')->where('user_id', $user->id)->value('suspended_at'))->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect($user->fresh()->tenants()->count())->toBe(0);
});

test('every SCIM write lands on the provisioning log, failures included', function (): void {
    $user = scimGroupCall('POST', '/Users', $this->token, ['userName' => 'new.hire@example.com'])->assertCreated();
    $group = scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting', [$this->member]))->assertCreated();
    scimGroupCall('PATCH', '/Groups/'.$group->json('id'), $this->token, scimPatch('replace', 'displayName', 'Finance'))->assertOk();
    scimGroupCall('POST', '/Users', $this->token, ['userName' => 'new.hire@example.com'])->assertStatus(409);
    scimGroupCall('DELETE', '/Groups/'.$group->json('id'), $this->token)->assertNoContent();
    scimGroupCall('GET', '/Groups', $this->token)->assertOk();

    Sanctum::actingAs($this->admin);
    $headers = ['X-Tenant-Id' => $this->tenant->id];

    $logs = $this->withHeaders($headers)->getJson('/api/v1/settings/security/scim/logs')->assertOk();
    expect($logs->json('meta.total'))->toBe(5)
        ->and($logs->json('data.0.operation'))->toBe('delete')
        ->and($logs->json('data.0.resource'))->toBe('group')
        ->and(collect($logs->json('data'))->firstWhere('status', 'failure')['error'])->toContain('already exists');

    $this->withHeaders($headers)->getJson('/api/v1/settings/security/scim/logs?status=failure')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.resource', 'user');
    $this->withHeaders($headers)->getJson('/api/v1/settings/security/scim/logs?resource=group&operation=patch')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.resource_name', 'Finance');
    $this->withHeaders($headers)->getJson('/api/v1/settings/security/scim/logs?status=bogus')->assertUnprocessable();

    expect(AuditLedgerEntry::query()->where('action', 'SCIM_OPERATION_FAILED')->count())->toBe(1);

    $sync = $this->withHeaders($headers)->getJson('/api/v1/settings/security/scim')->assertOk()->json('data.sync');
    expect($sync['users_provisioned'])->toBe(1)
        ->and($sync['groups_provisioned'])->toBe(0)
        ->and($sync['last_sync_at'])->not->toBeNull()
        ->and($sync['last_error']['resource'])->toBe('user')
        ->and($sync['last_error']['message'])->toContain('already exists');

    // Another tenant sees nothing of it.
    Sanctum::actingAs(scimGroupMember($this->other, 'admin', 'other.admin@example.com'));
    $this->withHeaders(['X-Tenant-Id' => $this->other->id])->getJson('/api/v1/settings/security/scim/logs')->assertOk()->assertJsonPath('meta.total', 0);
});

test('administrators manage the group mappings; nobody else does, and the owner role is refused', function (): void {
    $headers = ['X-Tenant-Id' => $this->tenant->id];
    $path = '/api/v1/settings/security/scim/group-mappings';

    Sanctum::actingAs($this->member);
    $this->withHeaders($headers)->getJson($path)->assertForbidden();
    $this->withHeaders($headers)->putJson($path, ['group_name' => 'Accounting', 'role' => 'accountant'])->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->withHeaders($headers)->putJson($path, ['group_name' => 'Accounting', 'role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->withHeaders($headers)->putJson($path, ['group_name' => 'Accounting', 'role' => 'nonsense'])->assertUnprocessable();
    $this->withHeaders($headers)->putJson($path, ['role' => 'sales'])->assertUnprocessable()->assertJsonValidationErrors('group_name');

    $created = $this->withHeaders($headers)->putJson($path, ['group_name' => 'Accounting', 'role' => 'accountant'])->assertCreated()->assertJsonPath('data.role', 'accountant');
    $this->withHeaders($headers)->putJson($path, ['group_name' => 'accounting', 'role' => 'sales'])->assertOk()->assertJsonPath('data.role', 'sales')->assertJsonPath('data.id', $created->json('data.id'));
    expect(ScimGroupMapping::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);

    scimGroupCall('POST', '/Groups', $this->token, scimGroupBody('Accounting', [$this->member]))->assertCreated();
    expect(scimGroupRole($this->tenant, $this->member))->toBe('sales');

    $list = $this->withHeaders($headers)->getJson($path)->assertOk();
    expect($list->json('data.0.member_count'))->toBe(1)
        ->and($list->json('meta.roles'))->toContain('accountant')->not->toContain('owner');

    // Changing the mapping moves the current members at once; removing it gives their role back.
    $this->withHeaders($headers)->putJson($path, ['group_name' => 'Accounting', 'role' => 'accountant'])->assertOk();
    expect(scimGroupRole($this->tenant, $this->member))->toBe('accountant');

    $this->withHeaders($headers)->deleteJson($path.'/'.$created->json('data.id'))->assertOk();
    expect(scimGroupRole($this->tenant, $this->member))->toBe('member');
    $this->withHeaders($headers)->deleteJson($path.'/'.$created->json('data.id'))->assertNotFound();

    expect(AuditLedgerEntry::query()->whereIn('action', ['SCIM_GROUP_MAPPING_SAVED', 'SCIM_GROUP_MAPPING_REMOVED'])->count())->toBe(4);
});

test('a mapping of another tenant cannot be removed', function (): void {
    $mapping = scimGroupMap($this->other, 'Accounting', 'accountant');

    Sanctum::actingAs($this->admin);
    $this->withHeaders(['X-Tenant-Id' => $this->tenant->id])->deleteJson('/api/v1/settings/security/scim/group-mappings/'.$mapping->id)->assertNotFound();
    expect(ScimGroupMapping::withoutGlobalScopes()->whereKey($mapping->id)->exists())->toBeTrue();
});
