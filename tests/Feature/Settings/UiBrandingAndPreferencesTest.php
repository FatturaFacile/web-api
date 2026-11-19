<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function uiActor(Tenant $tenant, string $role, string $email): User
{
    $user = User::create(['name' => 'Ui '.$role, 'email' => $email, 'password' => bcrypt(Str::random(20)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
});

describe('tenant branding', function (): void {
    test('defaults to no colours and the system theme', function (): void {
        uiActor($this->tenant, 'admin', 'admin@alfa.test');

        $this->getJson('/api/v1/tenant/branding')->assertOk()->assertJsonPath('data', [
            'brand_primary_color' => null,
            'brand_secondary_color' => null,
            'brand_logo_url' => null,
            'default_theme' => 'system',
        ]);
    });

    test('an administrator stores colours upper-cased, a logo and a default theme', function (): void {
        uiActor($this->tenant, 'admin', 'admin@alfa.test');

        $this->patchJson('/api/v1/tenant/branding', [
            'brand_primary_color' => '#1f6feb',
            'brand_secondary_color' => '#F59E0B',
            'brand_logo_url' => 'https://cdn.example.com/logo.svg',
            'default_theme' => 'dark',
        ])->assertOk()->assertJsonPath('data.brand_primary_color', '#1F6FEB');

        $this->getJson('/api/v1/tenant/branding')
            ->assertJsonPath('data.default_theme', 'dark')
            ->assertJsonPath('data.brand_logo_url', 'https://cdn.example.com/logo.svg');
    });

    test('null clears a value and a partial update keeps the others', function (): void {
        uiActor($this->tenant, 'admin', 'admin@alfa.test');
        $this->patchJson('/api/v1/tenant/branding', ['brand_primary_color' => '#112233', 'brand_secondary_color' => '#445566'])->assertOk();

        $this->patchJson('/api/v1/tenant/branding', ['brand_primary_color' => null])->assertOk()
            ->assertJsonPath('data.brand_primary_color', null)
            ->assertJsonPath('data.brand_secondary_color', '#445566');
    });

    test('rejects malformed colours, unsafe logos and unknown themes', function (): void {
        uiActor($this->tenant, 'admin', 'admin@alfa.test');

        $this->patchJson('/api/v1/tenant/branding', [
            'brand_primary_color' => 'red',
            'brand_secondary_color' => '#FFF',
            'brand_logo_url' => 'javascript:alert(1)',
            'default_theme' => 'sepia',
        ])->assertUnprocessable()->assertJsonValidationErrors(['brand_primary_color', 'brand_secondary_color', 'brand_logo_url', 'default_theme']);

        $this->patchJson('/api/v1/tenant/branding', ['brand_logo_url' => 'data:image/svg+xml;base64,AAAA'])->assertUnprocessable();
        $this->patchJson('/api/v1/tenant/branding', ['brand_logo_url' => 'http://insecure.example.com/logo.png'])->assertUnprocessable();
    });

    test('every member reads the branding but only the system module writes it', function (): void {
        uiActor($this->tenant, 'technician', 'tech@alfa.test');

        $this->getJson('/api/v1/tenant/branding')->assertOk();
        $this->patchJson('/api/v1/tenant/branding', ['brand_primary_color' => '#112233'])->assertForbidden();
    });

    test('is isolated per tenant', function (): void {
        $admin = uiActor($this->tenant, 'admin', 'admin@alfa.test');
        $admin->tenants()->attach($this->other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
        $this->patchJson('/api/v1/tenant/branding', ['brand_primary_color' => '#112233'])->assertOk();

        $this->withHeader('X-Tenant-Id', $this->other->id)->getJson('/api/v1/tenant/branding')
            ->assertOk()->assertJsonPath('data.brand_primary_color', null);
    });

    test('requires authentication', function (): void {
        $this->getJson('/api/v1/tenant/branding')->assertUnauthorized();
    });
});

describe('user interface preferences', function (): void {
    test('defaults to the system theme and comfortable density', function (): void {
        uiActor($this->tenant, 'technician', 'tech@alfa.test');

        $this->getJson('/api/v1/user/preferences')->assertOk()->assertJsonPath('data', [
            'theme_preference' => 'system',
            'ui_density' => 'comfortable',
            'reduced_motion' => null,
        ]);
    });

    test('any member stores their own preferences', function (): void {
        $user = uiActor($this->tenant, 'technician', 'tech@alfa.test');

        $this->patchJson('/api/v1/user/preferences', ['theme_preference' => 'dark', 'ui_density' => 'compact', 'reduced_motion' => true])
            ->assertOk()->assertJsonPath('data.ui_density', 'compact');

        expect($user->refresh()->theme_preference)->toBe('dark')->and($user->reduced_motion)->toBeTrue();

        $this->patchJson('/api/v1/user/preferences', ['reduced_motion' => null])->assertOk()
            ->assertJsonPath('data.reduced_motion', null)
            ->assertJsonPath('data.theme_preference', 'dark');
    });

    test('rejects values outside the enums', function (): void {
        uiActor($this->tenant, 'technician', 'tech@alfa.test');

        $this->patchJson('/api/v1/user/preferences', ['theme_preference' => 'neon', 'ui_density' => 'tiny', 'reduced_motion' => 'maybe'])
            ->assertUnprocessable()->assertJsonValidationErrors(['theme_preference', 'ui_density', 'reduced_motion']);
    });

    test('never touches the preferences of another user', function (): void {
        $first = uiActor($this->tenant, 'technician', 'one@alfa.test');
        $this->patchJson('/api/v1/user/preferences', ['ui_density' => 'spacious'])->assertOk();

        uiActor($this->tenant, 'technician', 'two@alfa.test');
        $this->getJson('/api/v1/user/preferences')->assertJsonPath('data.ui_density', 'comfortable');
        expect($first->refresh()->ui_density)->toBe('spacious');
    });

    test('requires authentication', function (): void {
        $this->patchJson('/api/v1/user/preferences', ['ui_density' => 'compact'])->assertUnauthorized();
    });
});
