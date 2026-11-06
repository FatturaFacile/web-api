<?php

declare(strict_types=1);

use App\Models\Contact;
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

    $insert = fn (?string $tenantId, string $name): string => tap((string) Str::uuid(), fn (string $id) => DB::table('contacts')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'company_name' => $name,
        'country' => 'CH',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->genericId = $insert(null, 'Generic');
    $insert($this->tenant->id, 'Own');
    $insert($this->otherTenant->id, 'Foreign');
});

afterEach(fn () => app(TenantContext::class)->clear());

test('queries return the rows of the tenant together with the generic rows', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);

    expect(Contact::orderBy('company_name')->pluck('company_name')->all())->toBe(['Generic', 'Own']);
});

test('without a tenant only generic rows are returned', function (): void {
    app(TenantContext::class)->setTenant(null);

    expect(Contact::pluck('company_name')->all())->toBe(['Generic']);
});

test('tenant users cannot change or delete generic rows', function (string $action): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $contact = Contact::findOrFail($this->genericId);

    $action === 'update' ? $contact->update(['company_name' => 'Changed']) : $contact->delete();
})->with(['update', 'delete'])->throws(AccessDeniedHttpException::class);

test('system code without a tenant can maintain generic rows', function (): void {
    app(TenantContext::class)->clear();

    Contact::withoutGlobalScopes()->findOrFail($this->genericId)->update(['company_name' => 'Maintained']);

    expect(DB::table('contacts')->where('id', $this->genericId)->value('company_name'))->toBe('Maintained');
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
