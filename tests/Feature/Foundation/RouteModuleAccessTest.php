<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Enterprise\MultiTenant\Http\Middleware\EnsureModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Authenticated routes that write without a module rule, each with the reason. Anything else that a signed-in member
 * can call with POST, PUT, PATCH or DELETE must declare module.access, so a forgotten check in a controller can never
 * open an action to every role.
 *
 * @return array<string, string>
 */
function writeRoutesWithoutModule(): array
{
    return [
        // The account of the user themselves.
        'POST api/v1/auth/logout' => 'own session',
        'POST api/v1/auth/refresh' => 'own session',
        'POST api/v1/auth/switch-tenant' => 'own memberships, checked by the controller',
        'POST api/v1/auth/two-factor/enable' => 'own second factor',
        'POST api/v1/auth/two-factor/confirm' => 'own second factor',
        'POST api/v1/auth/two-factor/disable' => 'own second factor',
        'POST api/v1/auth/two-factor/recovery-codes' => 'own second factor',
        // Personal dashboard layout, stored per user.
        'PUT api/v1/dashboard/layout' => 'own layout',
        'POST api/v1/dashboard/layout/reset' => 'own layout',
        // Employee self-service: every member clocks in, logs expenses and books meals for themselves; the policies
        // limit each answer to the own records unless the user manages the module.
        'POST api/v1/time-attendance/clock-in' => 'own time entry',
        'POST api/v1/time-attendance/clock-out' => 'own time entry',
        'POST api/v1/expenses' => 'own expense, ExpensePolicy',
        'PUT api/v1/expenses/{id}' => 'ExpensePolicy',
        'PATCH api/v1/expenses/{id}' => 'ExpensePolicy',
        'DELETE api/v1/expenses/{id}' => 'ExpensePolicy',
        'POST api/v1/expenses/{id}/attachments' => 'ExpensePolicy',
        'POST api/v1/hr/time/events' => 'own clock events, TimeTrackingPolicy',
        'PATCH api/v1/hr/time/events/{id}' => 'TimeTrackingPolicy',
        'DELETE api/v1/hr/time/events/{id}' => 'TimeTrackingPolicy',
        'PUT api/v1/hr/time/daily-records' => 'TimeTrackingPolicy',
        'PUT api/v1/hr/time/profiles/{userId}' => 'TimeTrackingPolicy',
        'POST api/v1/hr/time/meal-bookings' => 'own meal booking',
        'POST api/v1/hr/time/punches/import' => 'TimeClockEvent manage ability',
        // Shared endpoints on records of several modules: RecordAccess checks the module of the record.
        'POST api/v1/attachments' => 'module of the parent record',
        'DELETE api/v1/attachments/{id}' => 'module of the parent record',
        'POST api/v1/documents/{type}/{id}/email' => 'write role of the module of the document',
        // Platform administration, outside any tenant.
        'POST api/v1/admin/tenant-access-requests/{id}/approve' => 'platform.admin',
        'POST api/v1/admin/tenant-access-requests/{id}/move' => 'platform.admin',
        'POST api/v1/admin/tenant-access-requests/{id}/reject' => 'platform.admin',
        'PUT api/v1/admin/tenants/{id}/document-quota' => 'platform.admin',
    ];
}

function declaresModuleAccess(Route $route): bool
{
    $excluded = $route->excludedMiddleware();

    if (in_array(EnsureModuleAccess::class, $excluded, true) || in_array('module.access', $excluded, true)) {
        return false;
    }

    return collect($route->gatherMiddleware())->contains(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'module.access:'));
}

test('every authenticated write route declares its module or is a documented exception', function (): void {
    $allowed = writeRoutesWithoutModule();
    $unprotected = [];
    $stale = $allowed;

    foreach (app('router')->getRoutes() as $route) {
        $middleware = collect($route->gatherMiddleware())->filter(fn (mixed $name): bool => is_string($name));
        $isAuthenticated = $middleware->contains(fn (string $name): bool => str_starts_with($name, 'auth:') || $name === 'auth');

        if (! $isAuthenticated || ! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
            $key = "{$method} {$route->uri()}";
            unset($stale[$key]);

            if (! declaresModuleAccess($route) && ! isset($allowed[$key])) {
                $unprotected[] = $key;
            }
        }
    }

    expect($unprotected)->toBe([])
        ->and(array_keys($stale))->toBe([]);
});

test('the legacy route groups declare the module that owns them', function (string $prefix, string $module): void {
    $checked = 0;

    foreach (app('router')->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), "api/v1/{$prefix}")) {
            continue;
        }

        $checked++;

        $declared = collect($route->gatherMiddleware())->contains(fn (mixed $name): bool => $name === "module.access:{$module}" || $name === "module.access:{$module},read");

        expect($declared)->toBeTrue("{$route->uri()} lacks module.access:{$module}");
    }

    expect($checked)->toBeGreaterThan(0);
})->with([
    ['sales/', 'invoicing'],
    ['catalog/', 'invoicing'],
    ['crm/', 'crm'],
    ['purchases/', 'procurement'],
    ['treasury/reconciliation', 'treasury'],
    ['banking/', 'treasury'],
    ['accounting/', 'ledger'],
    ['hr/frontalieri', 'hr'],
    ['retail/', 'fiscal_retail'],
    ['ecommerce/', 'integrations'],
    ['field-service/', 'service'],
    ['service-reports', 'service'],
    ['appointments', 'service'],
    ['inventory/products', 'inventory'],
    ['subscriptions', 'subscriptions'],
    ['workflows/instances', 'workflow'],
    ['settings/sdi-channel', 'system'],
    ['payroll/runs', 'payroll'],
]);

function legacyRouteActor(string $role, array $permissions = []): User
{
    $tenant = test()->tenant;
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}

describe('legacy routes by role', function (): void {
    beforeEach(function (): void {
        $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    });

    test('a technician cannot issue an invoice through the sales routes, an accountant can reach them', function (): void {
        legacyRouteActor('technician');
        $this->postJson('/api/v1/sales/invoices', [])->assertForbidden();

        legacyRouteActor('accountant');
        expect($this->postJson('/api/v1/sales/invoices', [])->status())->not->toBe(403);
    });

    test('ordinary members are kept out of the money and the books', function (string $method, string $uri): void {
        legacyRouteActor('member');

        $this->json($method, $uri, [])->assertForbidden();
    })->with([
        ['GET', '/api/v1/treasury/transactions'],
        ['POST', '/api/v1/treasury/reconciliation/auto-match'],
        ['GET', '/api/v1/banking/statements'],
        ['POST', '/api/v1/accounting/ledger-entries'],
        ['GET', '/api/v1/accounting/general-ledger'],
        ['GET', '/api/v1/hr/frontalieri'],
        ['POST', '/api/v1/retail/pos/sales'],
        ['GET', '/api/v1/ecommerce/channels'],
        ['PUT', '/api/v1/settings/tenant'],
        ['PUT', '/api/v1/settings/preferences/general'],
        ['GET', '/api/v1/settings/users'],
        ['GET', '/api/v1/settings/audit-logs'],
        ['POST', '/api/v1/settings/exchange-rates/sync'],
        ['POST', '/api/v1/crm/contacts'],
        ['POST', '/api/v1/catalog/price-lists'],
        ['POST', '/api/v1/subscriptions'],
        ['GET', '/api/v1/payroll/runs'],
        ['POST', '/api/v1/translations'],
    ]);

    test('a purchaser can order but cannot pay suppliers', function (): void {
        legacyRouteActor('purchasing');

        expect($this->postJson('/api/v1/purchases/orders', [])->status())->not->toBe(403);
        $this->postJson('/api/v1/purchases/export-pain001', [])->assertForbidden();
        $this->postJson('/api/v1/purchases/supplier-invoices/'.Str::uuid().'/mark-paid', [])->assertForbidden();
    });

    test('a technician works the field service but does not bill it', function (): void {
        legacyRouteActor('technician');

        expect($this->postJson('/api/v1/field-service/work-orders', [])->status())->not->toBe(403);
        $this->postJson('/api/v1/field-service/work-orders/'.Str::uuid().'/invoice', [])->assertForbidden();
        $this->postJson('/api/v1/service-reports/'.Str::uuid().'/generate-invoice', [])->assertForbidden();
    });

    test('employees keep their self-service routes', function (string $method, string $uri): void {
        legacyRouteActor('member');

        expect($this->json($method, $uri)->status())->not->toBe(403);
    })->with([
        ['GET', '/api/v1/time-attendance/entries'],
        ['GET', '/api/v1/time-attendance/monthly-report'],
        ['GET', '/api/v1/expenses'],
        ['GET', '/api/v1/payroll/payslips'],
        ['GET', '/api/v1/payroll/salary-certificates'],
        ['GET', '/api/v1/hr/time/events'],
        ['GET', '/api/v1/settings/tenant'],
        ['GET', '/api/v1/settings/preferences'],
        ['GET', '/api/v1/workflows/my-pending'],
    ]);

    test('a payroll grant on the membership opens the payroll routes', function (): void {
        legacyRouteActor('member', ['payroll.read']);

        expect($this->getJson('/api/v1/payroll/runs')->status())->not->toBe(403);
        $this->postJson('/api/v1/payroll/runs/calculate', [])->assertForbidden();
    });
});
