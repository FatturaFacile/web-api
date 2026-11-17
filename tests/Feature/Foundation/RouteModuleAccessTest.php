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
        'PUT api/v1/auth/password' => 'own password',
        'POST api/v1/auth/switch-tenant' => 'own memberships, checked by the controller',
        'POST api/v1/auth/two-factor/enable' => 'own second factor',
        'POST api/v1/auth/two-factor/confirm' => 'own second factor',
        'POST api/v1/auth/two-factor/disable' => 'own second factor',
        'POST api/v1/auth/two-factor/recovery-codes' => 'own second factor',
        'POST api/v1/auth/sessions/revoke-others' => 'own sessions',
        // In-app notification centre and its preferences: the notifications of the user themselves.
        'PUT api/v1/platform/notifications/preferences' => 'own notification preferences',
        'POST api/v1/platform/notifications/read-all' => 'own notifications',
        'POST api/v1/platform/notifications/{id}/read' => 'own notifications',
        'DELETE api/v1/platform/notifications/{id}' => 'own notifications',
        'DELETE api/v1/auth/sessions/{kind}/{id}' => 'own sessions',
        // Interface preferences of the user themselves.
        'PATCH api/v1/user/preferences' => 'own interface preferences',
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
        'POST api/v1/hr/me/leave-requests' => 'own leave request, employee linked to the user',
        'POST api/v1/hr/me/leave-requests/{id}/cancel' => 'own leave request, employee linked to the user',
        // Shared endpoints on records of several modules: RecordAccess checks the module of the record.
        'POST api/v1/attachments' => 'module of the parent record',
        'DELETE api/v1/attachments/{id}' => 'module of the parent record',
        'POST api/v1/documents/{type}/{id}/email' => 'write role of the module of the document',
        // Routes that check access themselves.
        'POST api/v1/broadcasting/auth' => 'channel authorisation callbacks',
        'POST api/v1/catalog/customs-taric-codes' => 'customs.manage gate of the controller',
        'PUT api/v1/catalog/customs-taric-codes/{id}' => 'customs.manage gate of the controller',
        'PATCH api/v1/catalog/customs-taric-codes/{id}' => 'customs.manage gate of the controller',
        'DELETE api/v1/catalog/customs-taric-codes/{id}' => 'customs.manage gate of the controller',
        'POST api/v1/catalog/free-trade-agreements' => 'customs.manage gate of the controller',
        'PUT api/v1/catalog/free-trade-agreements/{id}' => 'customs.manage gate of the controller',
        'PATCH api/v1/catalog/free-trade-agreements/{id}' => 'customs.manage gate of the controller',
        'DELETE api/v1/catalog/free-trade-agreements/{id}' => 'customs.manage gate of the controller',
        // Mobile companion app and employee self-service: the own records, or the checks of the controller.
        'POST api/v1/mobile/devices' => 'own device',
        'PUT api/v1/mobile/devices/current/push-token' => 'own device',
        'PUT api/v1/mobile/devices/current/biometric' => 'own device',
        'POST api/v1/mobile/devices/current/wipe-ack' => 'own device',
        'PUT api/v1/mobile/devices/current/manifest' => 'own device',
        'DELETE api/v1/mobile/devices/{id}' => 'own device or admin check of MobileDeviceController',
        'POST api/v1/mobile/approvals/{stepId}/decision' => 'approver of the step',
        'POST api/v1/mobile/work-sites' => 'checked by MobileWorkforceController',
        'PUT api/v1/mobile/work-sites/{id}' => 'checked by MobileWorkforceController',
        'DELETE api/v1/mobile/work-sites/{id}' => 'checked by MobileWorkforceController',
        'POST api/v1/mobile/shifts' => 'checked by MobileWorkforceController',
        'DELETE api/v1/mobile/shifts/{id}' => 'checked by MobileWorkforceController',
        'POST api/v1/mobile/payslips/{id}/read-receipt' => 'own payslip',
        'POST api/v1/mobile/admin/payslips' => 'checked by MobilePayslipController',
        'POST api/v1/mobile/receipts/parse' => 'own receipt',
        'POST api/v1/hr/me/clock' => 'own clock event',
        // Recruiting: the authorisation of each controller.
        'POST api/v1/hr/recruiting/job-openings' => 'JobOpeningController authorisation',
        'PUT api/v1/hr/recruiting/job-openings/{id}' => 'JobOpeningController authorisation',
        'POST api/v1/hr/recruiting/job-openings/{id}/publish' => 'JobOpeningController authorisation',
        'POST api/v1/hr/recruiting/job-openings/{id}/close' => 'JobOpeningController authorisation',
        'POST api/v1/hr/recruiting/applications' => 'CandidateApplicationController authorisation',
        'PATCH api/v1/hr/recruiting/applications/{id}' => 'CandidateApplicationController authorisation',
        'POST api/v1/hr/recruiting/applications/{id}/reparse-cv' => 'CandidateApplicationController authorisation',
        'POST api/v1/hr/recruiting/applications/{id}/move' => 'CandidateApplicationController authorisation',
        'POST api/v1/hr/recruiting/applications/{id}/extend-consent' => 'CandidateApplicationController authorisation',
        'POST api/v1/hr/recruiting/applications/{id}/anonymize' => 'CandidateApplicationController authorisation',
        'POST api/v1/hr/recruiting/applications/{id}/hire' => 'CandidateApplicationController authorisation',
        'POST api/v1/hr/recruiting/applications/{id}/scorecards' => 'InterviewScorecardController authorisation',
        'POST api/v1/hr/recruiting/applications/{id}/offer' => 'JobOfferController authorisation',
        'POST api/v1/hr/recruiting/offers/{id}/mark-signed' => 'JobOfferController authorisation',
        'POST api/v1/hr/recruiting/offers/{id}/decline' => 'JobOfferController authorisation',
        // Notification devices and channels of the user themselves.
        'POST api/v1/platform/notifications/devices' => 'own notification device',
        'DELETE api/v1/platform/notifications/devices/{id}' => 'own notification device',
        'POST api/v1/platform/notifications/channels/telegram/link' => 'own channel',
        'DELETE api/v1/platform/notifications/channels/telegram' => 'own channel',
        'PUT api/v1/platform/notifications/settings' => 'own notification settings',
        // Logistics: every route carries its own logistics.can permission (EnsureLogisticsPermission).
        'POST api/v1/logistics/delivery-notes' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/from-orders' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/deferred-invoice' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/deferred-invoicing/run' => 'logistics.can permission',
        'PUT api/v1/logistics/delivery-notes/{id}' => 'logistics.can permission',
        'DELETE api/v1/logistics/delivery-notes/{id}' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/{id}/issue' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/{id}/dispatch' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/{id}/deliver' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/{id}/awb' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/{id}/accept-approval' => 'logistics.can permission',
        'POST api/v1/logistics/delivery-notes/{id}/cancel' => 'logistics.can permission',
        'POST api/v1/wms/transfers' => 'logistics.can permission',
        'POST api/v1/wms/transfers/{id}/ship' => 'logistics.can permission',
        'POST api/v1/wms/transfers/{id}/receive' => 'logistics.can permission',
        'POST api/v1/wms/transfers/{id}/scan' => 'logistics.can permission',
        'POST api/v1/wms/transfers/{id}/cancel' => 'logistics.can permission',
        'POST api/v1/wms/transfers/{id}/approve' => 'logistics.can permission',
        'POST api/v1/wms/transfers/{id}/close' => 'logistics.can permission',
        'POST api/v1/wms/transfers/{id}/discrepancies/{discrepancyId}/resolve' => 'logistics.can permission',
        'POST api/v1/wms/transfer-discrepancies/{id}/resolve' => 'logistics.can permission',
        'POST api/v1/wms/consignment/declarations' => 'logistics.can permission',
        'POST api/v1/wms/consignment/consumption-reports' => 'logistics.can permission',
        'POST api/v1/wms/consignment/vendor-receipts' => 'logistics.can permission',
        'POST api/v1/wms/consignment/agreements' => 'logistics.can permission',
        'POST api/v1/wms/consignment/agreements/{id}/terminate' => 'logistics.can permission',
        'POST api/v1/logistics/transfers' => 'logistics.can permission',
        'POST api/v1/logistics/transfers/{id}/ship' => 'logistics.can permission',
        'POST api/v1/logistics/transfers/{id}/receive' => 'logistics.can permission',
        'POST api/v1/logistics/transfers/{id}/scan' => 'logistics.can permission',
        'POST api/v1/logistics/transfers/{id}/cancel' => 'logistics.can permission',
        'POST api/v1/logistics/transfers/{id}/approve' => 'logistics.can permission',
        'POST api/v1/logistics/transfers/{id}/close' => 'logistics.can permission',
        'POST api/v1/logistics/transfers/{id}/discrepancies/{discrepancyId}/resolve' => 'logistics.can permission',
        'POST api/v1/logistics/transfer-discrepancies/{id}/resolve' => 'logistics.can permission',
        'POST api/v1/logistics/consignment/declarations' => 'logistics.can permission',
        'POST api/v1/logistics/consignment/consumption-reports' => 'logistics.can permission',
        'POST api/v1/logistics/consignment/vendor-receipts' => 'logistics.can permission',
        'POST api/v1/logistics/consignment/agreements' => 'logistics.can permission',
        'POST api/v1/logistics/consignment/agreements/{id}/terminate' => 'logistics.can permission',
        // Platform administration, outside any tenant.
        'POST api/v1/admin/tenant-access-requests/{id}/approve' => 'platform.admin',
        'POST api/v1/admin/tenant-access-requests/{id}/move' => 'platform.admin',
        'POST api/v1/admin/tenant-access-requests/{id}/reject' => 'platform.admin',
        'PUT api/v1/admin/tenants/{id}/document-quota' => 'platform.admin',
        'PUT api/v1/admin/tenants/{id}/plan' => 'platform.admin',
        'PUT api/v1/admin/users/{userId}/tenants/{tenantId}' => 'platform.admin',
        'DELETE api/v1/admin/users/{userId}/tenants/{tenantId}' => 'platform.admin',
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
        if (! str_starts_with($route->uri(), "api/v1/{$prefix}") || str_starts_with($route->uri(), 'api/v1/catalog/customs-taric-codes') || str_starts_with($route->uri(), 'api/v1/catalog/free-trade-agreements')) {
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
