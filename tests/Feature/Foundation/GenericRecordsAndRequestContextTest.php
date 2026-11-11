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

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->otherTenant = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => bcrypt('secret123')]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $insert = fn (?string $tenantId, string $code): string => tap((string) Str::uuid(), fn (string $id) => DB::table('countries')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'iso_code_2' => $code,
        'iso_code_3' => $code.'X',
        'numeric_code' => 'N'.$code,
        'phone_prefix' => '+999',
        'currency_code' => 'CHF',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->genericId = $insert(null, 'ZG');
    $insert($this->tenant->id, 'ZO');
    $insert($this->otherTenant->id, 'ZF');

    $this->orphanContactId = (string) Str::uuid();
    DB::table('contacts')->insert(['id' => $this->orphanContactId, 'tenant_id' => null, 'company_name' => 'Orphan', 'country' => 'CH', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('queries return the rows of the tenant together with the generic rows of a model that allows them', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);

    expect(Country::whereIn('iso_code_2', ['ZG', 'ZO', 'ZF'])->orderBy('iso_code_2')->pluck('iso_code_2')->all())->toBe(['ZG', 'ZO']);
});

test('without a tenant only generic rows are returned', function (): void {
    app(TenantContext::class)->setTenant(null);

    expect(Country::whereIn('iso_code_2', ['ZG', 'ZO', 'ZF'])->pluck('iso_code_2')->all())->toBe(['ZG']);
});

test('a null tenant row of a model that does not allow generic records is invisible to everybody', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    expect(Contact::where('company_name', 'Orphan')->exists())->toBeFalse();

    app(TenantContext::class)->setTenant(null);
    expect(Contact::where('company_name', 'Orphan')->exists())->toBeFalse();
});

test('tenant users cannot change or delete generic rows', function (string $action): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $country = Country::findOrFail($this->genericId);

    $action === 'update' ? $country->update(['currency_code' => 'EUR']) : $country->delete();
})->with(['update', 'delete'])->throws(AccessDeniedHttpException::class);

test('system code without a tenant can maintain generic rows', function (): void {
    app(TenantContext::class)->clear();
    Country::findOrFail($this->genericId)->update(['currency_code' => 'EUR']);

    expect(DB::table('countries')->where('id', $this->genericId)->value('currency_code'))->toBe('EUR');
});

describe('with the request context required', function (): void {
    beforeEach(function (): void {
        config(['tenancy.require_request_context' => true]);
        Sanctum::actingAs($this->user);
    });

    test('a route without tenant_id and user_id is refused', function (): void {
        $this->getJson('/api/v1/auth/profile')->assertStatus(400);
        $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertStatus(400);
    });

    test('module routes are refused too', function (): void {
        $this->getJson('/api/v1/general-ledger/accounts')->assertStatus(400);
    });

    test('the context is accepted as headers', function (): void {
        $this->withHeaders(['X-Tenant-Id' => $this->tenant->id, 'X-User-Id' => $this->user->id])
            ->getJson('/api/v1/auth/profile')->assertOk();
    });

    test('the context is accepted as query parameters', function (): void {
        $this->getJson("/api/v1/auth/profile?tenant_id={$this->tenant->id}&user_id={$this->user->id}")->assertOk();
    });

    test('a user_id different from the token is forbidden', function (): void {
        $this->withHeaders(['X-Tenant-Id' => $this->tenant->id, 'X-User-Id' => (string) Str::uuid()])
            ->getJson('/api/v1/auth/profile')->assertForbidden();
    });

    test('a tenant the user does not belong to is forbidden', function (): void {
        $this->withHeaders(['X-Tenant-Id' => $this->otherTenant->id, 'X-User-Id' => $this->user->id])
            ->getJson('/api/v1/auth/profile')->assertForbidden();
    });

    test('with the header the user_id query parameter stays a list filter', function (): void {
        $this->withHeaders(['X-Tenant-Id' => $this->tenant->id, 'X-User-Id' => $this->user->id])
            ->getJson('/api/v1/expenses?user_id='.Str::uuid())->assertOk();
    });
});
