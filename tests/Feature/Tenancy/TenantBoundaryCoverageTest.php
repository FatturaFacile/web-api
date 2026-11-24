<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Country;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

/**
 * Covers the branches of the tenant boundary that the module tests never reached (measured with pcov): the request
 * middleware IdentifyTenant and the BelongsToTenant trait.
 */
beforeEach(function (): void {
    $this->tenantA = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->tenantB = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA', 'country' => 'CH', 'default_currency' => 'CHF']);
});

function boundaryMember(Tenant $tenant, string $role = 'owner', bool $active = true): User
{
    $user = User::create([
        'name' => 'Member '.Str::random(4),
        'email' => Str::random(12).'@example.test',
        'password' => bcrypt(Str::password(24)),
        'locale' => 'it',
        'is_active' => $active,
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

test('a deactivated account is refused whatever token it holds', function (): void {
    Sanctum::actingAs(boundaryMember($this->tenantA, active: false));

    $this->withHeader('X-Tenant-Id', $this->tenantA->id)->getJson('/api/v1/crm/contacts')->assertForbidden();
});

test('the declared user must be the authenticated user', function (): void {
    $user = boundaryMember($this->tenantA);
    Sanctum::actingAs($user);

    $this->withHeaders(['X-Tenant-Id' => $this->tenantA->id, 'X-User-Id' => (string) Str::uuid()])->getJson('/api/v1/crm/contacts')->assertForbidden();
    $this->withHeaders(['X-Tenant-Id' => $this->tenantA->id, 'X-User-Id' => (string) $user->id])->getJson('/api/v1/crm/contacts')->assertOk();
});

test('an unknown tenant answers 404 and a tenant the user does not belong to answers 403', function (): void {
    Sanctum::actingAs(boundaryMember($this->tenantA));

    $this->withHeader('X-Tenant-Id', (string) Str::uuid())->getJson('/api/v1/crm/contacts')->assertNotFound();
    $this->withHeader('X-Tenant-Id', $this->tenantB->id)->getJson('/api/v1/crm/contacts')->assertForbidden();
});

test('the tenant can be given as a query parameter and defaults to the first tenant of the user', function (): void {
    Contact::withoutGlobalScopes()->create(['tenant_id' => $this->tenantA->id, 'company_name' => 'Solo Alfa', 'country' => 'CH']);
    Sanctum::actingAs(boundaryMember($this->tenantA));

    $this->getJson('/api/v1/crm/contacts?tenant_id='.$this->tenantA->id)->assertOk()->assertJsonFragment(['company_name' => 'Solo Alfa']);
    $this->getJson('/api/v1/crm/contacts')->assertOk()->assertJsonFragment(['company_name' => 'Solo Alfa']);
    $this->getJson('/api/v1/crm/contacts?tenant_id='.$this->tenantB->id)->assertForbidden();
});

test('a suspended company is closed to its members', function (): void {
    $this->tenantA->update(['status' => 'suspended']);
    Sanctum::actingAs(boundaryMember($this->tenantA));

    $this->withHeader('X-Tenant-Id', $this->tenantA->id)->getJson('/api/v1/crm/contacts')->assertForbidden();
});

test('a company under restore accepts reads and refuses writes', function (): void {
    $this->tenantA->forceFill(['is_maintenance' => true])->save();
    Sanctum::actingAs(boundaryMember($this->tenantA));

    $this->withHeader('X-Tenant-Id', $this->tenantA->id)->getJson('/api/v1/crm/contacts')->assertOk();
    $this->withHeader('X-Tenant-Id', $this->tenantA->id)->postJson('/api/v1/crm/contacts', ['company_name' => 'X', 'country' => 'CH'])->assertStatus(503)->assertHeader('Retry-After', '60');
});

test('a read-only role reads and cannot write', function (): void {
    Sanctum::actingAs(boundaryMember($this->tenantA, 'accountant_readonly'));

    $this->withHeader('X-Tenant-Id', $this->tenantA->id)->getJson('/api/v1/crm/contacts')->assertOk();
    $this->withHeader('X-Tenant-Id', $this->tenantA->id)->postJson('/api/v1/crm/contacts', ['company_name' => 'X', 'country' => 'CH'])->assertForbidden();
});

test('the tenant context does not outlive the request', function (): void {
    Sanctum::actingAs(boundaryMember($this->tenantA));
    $this->withHeader('X-Tenant-Id', $this->tenantA->id)->getJson('/api/v1/crm/contacts')->assertOk();

    expect(app(TenantContext::class)->isExplicit())->toBeFalse();
});

test('a record created under an explicit tenant takes that tenant whatever the payload says', function (): void {
    $context = app(TenantContext::class);
    $context->setTenant($this->tenantA);

    $contact = Contact::create(['tenant_id' => $this->tenantB->id, 'company_name' => 'Forged', 'country' => 'CH']);

    expect($contact->tenant_id)->toBe($this->tenantA->id);
});

test('without an explicit tenant the console may set the tenant of a record, and the record is invisible to other tenants', function (): void {
    $contact = Contact::create(['tenant_id' => $this->tenantB->id, 'company_name' => 'By the console', 'country' => 'CH']);
    expect($contact->tenant_id)->toBe($this->tenantB->id);

    app(TenantContext::class)->setTenant($this->tenantA);
    expect(Contact::query()->whereKey($contact->id)->exists())->toBeFalse();
});

function boundaryCountry(string $code, ?Tenant $tenant = null): Country
{
    $attributes = ['iso_code_2' => $code, 'iso_code_3' => $code.'X', 'numeric_code' => 'N'.$code, 'phone_prefix' => '+999', 'currency_code' => 'CHF'];

    return $tenant === null ? Country::createGlobal($attributes) : Country::query()->create($attributes + ['tenant_id' => $tenant->id]);
}

test('generic records, with no tenant, are readable by a tenant for a model that allows them, but read-only', function (): void {
    $generic = boundaryCountry('ZA');
    app(TenantContext::class)->setTenant($this->tenantA);

    expect(Country::query()->whereKey($generic->id)->exists())->toBeTrue()
        ->and(fn () => Country::query()->whereKey($generic->id)->first()->update(['currency_code' => 'EUR']))->toThrow(AccessDeniedHttpException::class)
        ->and(fn () => Country::query()->whereKey($generic->id)->first()->delete())->toThrow(AccessDeniedHttpException::class);

    app(TenantContext::class)->clear();
    expect(Country::query()->whereKey($generic->id)->first()->update(['currency_code' => 'EUR']))->toBeTrue();
});

test('a null tenant is invisible for a model that does not allow global records', function (): void {
    DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'company_name' => 'Orphan', 'country' => 'CH', 'created_at' => now(), 'updated_at' => now()]);

    app(TenantContext::class)->setTenant($this->tenantA);
    expect(Contact::query()->where('company_name', 'Orphan')->exists())->toBeFalse();

    app(TenantContext::class)->clear();
    expect(Contact::query()->where('company_name', 'Orphan')->exists())->toBeFalse()
        ->and(Contact::withoutGlobalScopes()->where('company_name', 'Orphan')->exists())->toBeTrue();
});

test('the forTenant scope returns the rows of the tenant and, only for a model that allows them, the generic ones', function (): void {
    $own = Contact::withoutGlobalScopes()->create(['tenant_id' => $this->tenantA->id, 'company_name' => 'Own', 'country' => 'CH']);
    $foreign = Contact::withoutGlobalScopes()->create(['tenant_id' => $this->tenantB->id, 'company_name' => 'Foreign', 'country' => 'CH']);

    expect(Contact::withoutGlobalScopes()->forTenant($this->tenantA->id)->pluck('id')->all())->toBe([$own->id])
        ->and(Contact::withoutGlobalScopes()->forTenant(null)->pluck('id')->all())->toBe([])
        ->and($foreign->id)->not->toBeEmpty();

    $genericCountry = boundaryCountry('ZB');
    $ownCountry = boundaryCountry('ZC', $this->tenantA);
    $foreignCountry = boundaryCountry('ZD', $this->tenantB);

    $forA = Country::withoutGlobalScopes()->forTenant($this->tenantA->id)->pluck('id')->all();
    $genericOnly = Country::withoutGlobalScopes()->forTenant(null)->pluck('id')->all();

    expect($forA)->toContain($ownCountry->id, $genericCountry->id)->not->toContain($foreignCountry->id)
        ->and($genericOnly)->toContain($genericCountry->id)->not->toContain($ownCountry->id, $foreignCountry->id);
});
