<?php

declare(strict_types=1);

use App\Http\Requests\Admin\DecideTenantAccessRequest;
use Database\Seeders\AssociationsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\Discipline;
use Modules\Associations\Models\Family;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\SocialRole;
use Modules\Associations\Services\AssociationMailer;
use OpenApi\Annotations\Operation;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
});

afterEach(fn () => associationsTearDown());

/**
 * @return list<IlluminateRoute>
 */
function associationRoutes(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), static fn (IlluminateRoute $route): bool => str_starts_with($route->getActionName(), 'Modules\\Associations\\')));
}

test('staff routes carry the middleware of the module, public ones are throttled', function (): void {
    $routes = associationRoutes();

    expect(count($routes))->toBeGreaterThan(100);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        if (str_starts_with($route->uri(), 'api/v1/associations/')) {
            expect($middleware)->toContain('api', 'auth:sanctum', 'tenant', 'locale', 'module.access:associations');
        } else {
            expect(collect($middleware)->contains(fn (string $name): bool => str_starts_with($name, 'throttle:')))->toBeTrue($route->uri());
        }
    }
});

test('every action is documented on OpenAPI', function (): void {
    foreach (associationRoutes() as $route) {
        [$controller, $action] = str_contains($route->getActionName(), '@') ? explode('@', $route->getActionName()) : [$route->getActionName(), '__invoke'];
        $method = new ReflectionMethod($controller, $action);
        $attributes = array_filter($method->getAttributes(), static fn (ReflectionAttribute $attribute): bool => is_subclass_of($attribute->getName(), Operation::class));

        expect($attributes)->toHaveCount(1, "{$controller}::{$action} has no OpenAPI attribute");
    }
});

test('the documented path of every action is the route it serves', function (): void {
    foreach (associationRoutes() as $route) {
        [$controller, $action] = str_contains($route->getActionName(), '@') ? explode('@', $route->getActionName()) : [$route->getActionName(), '__invoke'];
        $attribute = array_values(array_filter((new ReflectionMethod($controller, $action))->getAttributes(), static fn (ReflectionAttribute $item): bool => is_subclass_of($item->getName(), Operation::class)))[0]->newInstance();

        expect(trim((string) $attribute->path, '/'))->toBe(rtrim($route->uri(), '/'), "{$controller}::{$action}");
    }
});

test('no request of the module accepts a tenant id', function (): void {
    foreach (glob(base_path('modules/31_Associations/src/Http/Requests/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain("'tenant_id'");
    }
});

test('the roles without a place in the module are kept out', function (string $role, string $method, string $uri): void {
    actAs($this, $role);

    $this->json($method, $uri)->assertForbidden();
})->with([
    ['member', 'GET', '/api/v1/associations/members'],
    ['instructor', 'GET', '/api/v1/associations/members'],
    ['instructor', 'GET', '/api/v1/associations/instalments'],
    ['instructor', 'GET', '/api/v1/associations/receipts'],
    ['instructor', 'GET', '/api/v1/associations/fee-assignments'],
    ['instructor', 'GET', '/api/v1/associations/courses'],
    ['sales', 'GET', '/api/v1/associations/members'],
    ['member', 'POST', '/api/v1/associations/members'],
]);

test('the accountant reads but does not manage, the secretary manages', function (): void {
    $course = Course::factory()->create();

    actAs($this, 'accountant');
    $this->getJson('/api/v1/associations/members')->assertOk();
    $this->postJson('/api/v1/associations/courses', ['title' => 'X', 'starts_on' => '2026-01-01'])->assertForbidden();
    $this->deleteJson("/api/v1/associations/courses/{$course->id}")->assertForbidden();
    $this->postJson('/api/v1/associations/catalogs/disciplines', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->postJson('/api/v1/associations/catalogs/fee-plans', ['code' => 'P', 'name' => 'P', 'frequency' => 'annual', 'amount' => '10.00'])->assertCreated();

    actAs($this, 'secretary');
    $this->postJson('/api/v1/associations/courses', ['title' => 'X', 'starts_on' => '2026-01-01'])->assertCreated();
    $this->postJson('/api/v1/associations/catalogs/disciplines', ['code' => 'X', 'name' => 'X'])->assertCreated();
});

test('a single permission opens the module for a user without a role in it', function (): void {
    actAs($this, 'member', ['associations.read']);

    $this->getJson('/api/v1/associations/members')->assertOk();
    $this->postJson('/api/v1/associations/members', ['first_name' => 'A', 'last_name' => 'B'])->assertForbidden();
});

test('the role in another company does not count here', function (): void {
    $member = Member::factory()->create();
    $user = actAs($this, 'admin', tenant: $this->otherTenant);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => '[]']);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson("/api/v1/associations/members/{$member->id}")->assertForbidden();
});

test('an unknown catalog is not found, and a catalog entry of another tenant neither', function (): void {
    actAs($this, 'secretary');
    $foreign = inTenant($this->otherTenant, fn () => Family::query()->create(['name' => 'X']));

    $this->getJson('/api/v1/associations/catalogs/nothing')->assertNotFound();
    $this->getJson("/api/v1/associations/catalogs/families/{$foreign->id}")->assertNotFound();
});

test('the accountant sees what fees and receipts need, not the notes and documents of the registry', function (): void {
    $member = Member::factory()->create(['internal_notes' => 'Note', 'identity_document' => 'CA123', 'emergency_contact' => 'Mother']);

    actAs($this, 'accountant');

    expect($this->getJson("/api/v1/associations/members/{$member->id}")->assertOk()->json('data'))
        ->toHaveKey('tax_code')
        ->not->toHaveKey('internal_notes')
        ->not->toHaveKey('identity_document')
        ->not->toHaveKey('emergency_contact');
});

test('the secretary is a role the platform can assign', function (): void {
    $assignable = collect((new DecideTenantAccessRequest)->rules()['role'])
        ->first(fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'in:'));

    expect(explode(',', substr((string) $assignable, 3)))->toContain('secretary')
        ->and(config('permissions.modules.associations.write'))->toContain('secretary');
});

test('the reference seeder writes generic rows, once', function (): void {
    inTenant(null, function (): void {
        $this->seed(AssociationsReferenceSeeder::class);
        $this->seed(AssociationsReferenceSeeder::class);
    });

    expect(RegistryType::query()->whereNull('tenant_id')->count())->toBe(8)
        ->and(SocialRole::query()->whereNull('tenant_id')->where('code', 'president')->count())->toBe(1);
});

test('a mail server that is down does not undo the work', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    app(AssociationMailer::class)->send('anna@example.com', 'Subject', ['Line']);

    expect(true)->toBeTrue();
});

test('catalog codes are unique per tenant only', function (): void {
    actAs($this, 'secretary');
    inTenant($this->otherTenant, fn () => Discipline::query()->create(['code' => 'NUO', 'name' => 'Swimming']));

    $this->postJson('/api/v1/associations/catalogs/disciplines', ['code' => 'NUO', 'name' => 'Swimming'])->assertCreated();
    $this->postJson('/api/v1/associations/catalogs/disciplines', ['code' => 'NUO', 'name' => 'Again'])->assertUnprocessable();
});
