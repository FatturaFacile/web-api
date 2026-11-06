<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
});

function moduleUser(string $role, array $permissions = []): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach(test()->tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', test()->tenant->id);

    return $user;
}

test('every route of a business module declares its access rule', function (): void {
    $prefixes = ['crm', 'invoicing', 'subscriptions', 'sub', 'projects', 'eam', 'esm', 'manufacturing', 'treasury', 'general-ledger', 'procurement', 'inventory', 'fiscal-retail', 'tessera-sanitaria', 'exports', 'reporting', 'analytics', 'communication', 'integrations', 'document-trust', 'payment-links'];
    $unprotected = [];

    foreach (app('router')->getRoutes() as $route) {
        $segment = explode('/', $route->uri())[2] ?? '';
        $isModuleRoute = str_starts_with($route->getActionName(), 'Modules\\') && in_array($segment, $prefixes, true);

        if ($isModuleRoute && ! collect($route->gatherMiddleware())->contains(fn (string $name): bool => str_starts_with($name, 'module.access:'))) {
            $unprotected[] = $route->uri();
        }
    }

    expect($unprotected)->toBe([]);
});

test('salaries and pay data are closed to ordinary members', function (string $uri): void {
    moduleUser('member');

    $this->getJson($uri)->assertForbidden();
})->with(['/api/v1/hr/employees', '/api/v1/treasury/payment-batches']);

test('managers of the module get in', function (string $role, string $uri): void {
    moduleUser($role);

    expect($this->getJson($uri)->status())->not->toBe(403);
})->with([
    ['admin', '/api/v1/hr/employees'],
    ['payroll', '/api/v1/hr/employees'],
    ['accountant', '/api/v1/treasury/payment-batches'],
]);

test('members can read shared modules but not write them', function (): void {
    moduleUser('member');

    expect($this->getJson('/api/v1/invoicing/invoices')->status())->not->toBe(403);
    $this->postJson('/api/v1/invoicing/invoices', [])->assertForbidden();
});

test('a single permission on the membership opens one module only', function (): void {
    moduleUser('member', ['treasury.read']);

    expect($this->getJson('/api/v1/treasury/payment-batches')->status())->not->toBe(403);
    $this->postJson('/api/v1/treasury/payment-batches', [])->assertForbidden();
    $this->getJson('/api/v1/hr/employees')->assertForbidden();
});

test('a ledger grant opens only the access it names', function (string $grant, bool $canWrite): void {
    moduleUser('member', [$grant]);

    expect($this->getJson('/api/v1/general-ledger/tax-settings')->status())->not->toBe(403)
        ->and($this->putJson('/api/v1/general-ledger/tax-settings', [])->status() !== 403)->toBe($canWrite);
})->with([
    'every ledger action' => ['gl.*', true],
    'posting only' => ['gl.journals.post', false],
]);

test('employees keep their own time records without HR rights', function (): void {
    moduleUser('member');

    expect($this->getJson('/api/v1/hr/time/events')->status())->not->toBe(403);
});

test('backups are closed to everybody but owners and administrators', function (): void {
    moduleUser('accountant');

    $this->getJson('/api/v1/system/backups')->assertForbidden();
    $this->postJson('/api/v1/system/backups')->assertForbidden();
    $this->postJson('/api/v1/system/backups/'.Str::uuid().'/restore')->assertForbidden();
});
