<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Facility\Models\FacilityBuilding;
use Modules\Facility\Models\FacilitySpace;

/*
| Shared helpers of the facility feature tests. Required by every test file of the folder.
*/

if (! function_exists('facTenant')) {
    function facTenant(string $name = 'Sede Rossi'): Tenant
    {
        return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    }

    /**
     * @param  list<string>|null  $permissions
     */
    function facMember(Tenant $tenant, string $role, ?array $permissions = null): User
    {
        $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@facility-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => $permissions === null ? null : json_encode($permissions)]);

        return $user;
    }

    function facAct(User $user, Tenant $tenant): void
    {
        app(TenantContext::class)->setTenant($tenant);
        Sanctum::actingAs($user);
        test()->flushHeaders();
        test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
    }

    function facBuilding(Tenant $tenant, array $attributes = []): FacilityBuilding
    {
        return FacilityBuilding::factory()->create(['tenant_id' => $tenant->id, ...$attributes]);
    }

    /**
     * @param  'meeting_room'|'desk'|'parking'|'office'  $kind
     * @param  array<string, mixed>  $attributes
     */
    function facSpace(Tenant $tenant, string $kind = 'meeting_room', array $attributes = []): FacilitySpace
    {
        $building = $attributes['building_id'] ?? facBuilding($tenant)->id;
        $factory = FacilitySpace::factory();
        $factory = match ($kind) {
            'desk' => $factory->desk(),
            'parking' => $factory->parking(),
            'meeting_room' => $factory->meetingRoom(6),
            default => $factory->state(['kind' => $kind, 'is_bookable' => true]),
        };

        return $factory->create(['tenant_id' => $tenant->id, 'building_id' => $building, ...$attributes]);
    }
}
