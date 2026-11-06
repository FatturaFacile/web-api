<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Payroll\Http\Controllers\PayrollController;
use Modules\Payroll\Models\Payslip;
use OpenApi\Annotations\Operation;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'admin');
    $this->worker = payrollMember($this->tenant, 'member');
    $this->colleague = payrollMember($this->tenant, 'member');
    $this->employee = payrollEmployee($this->tenant, [], $this->worker);
    $this->colleagueEmployee = payrollEmployee($this->tenant, ['first_name' => 'Anna', 'last_name' => 'Bianchi', 'full_name' => 'Anna Bianchi'], $this->colleague);
    payrollProfile($this->tenant, $this->employee);
    payrollProfile($this->tenant, $this->colleagueEmployee);
    payrollSwissSetup($this->tenant, $this->manager);
    $this->runId = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    $this->ownSlip = Payslip::query()->withoutGlobalScopes()->where('employee_id', $this->employee->id)->sole();
    $this->otherSlip = Payslip::query()->withoutGlobalScopes()->where('employee_id', $this->colleagueEmployee->id)->sole();
});

/**
 * @return list<IlluminateRoute>
 */
function payrollRoutes(): array
{
    return array_values(array_filter(iterator_to_array(Route::getRoutes()), static fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/payroll')));
}

function payrollRouteUri(IlluminateRoute $route, string $id): string
{
    return '/'.preg_replace('/\{(id|levelId|employeeId)\}/', $id, str_replace(['{country}', '{year}'], ['CH', '2026'], $route->uri()));
}

test('every route runs under the required middleware', function (): void {
    $routes = payrollRoutes();

    expect(count($routes))->toBeGreaterThan(50);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('api', 'auth:sanctum', 'tenant', 'locale');
    }
});

test('every action authorizes and is documented in English on OpenAPI', function (): void {
    foreach (payrollRoutes() as $route) {
        [$controller, $action] = explode('@', $route->getActionName());
        $method = new ReflectionMethod($controller, $action);
        $source = implode('', array_slice(file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $attributes = array_filter($method->getAttributes(), static fn (ReflectionAttribute $attribute): bool => is_subclass_of($attribute->getName(), Operation::class));

        expect(str_contains($source, '$this->authorize('))->toBeTrue("{$controller}::{$action} does not authorize");
        expect($attributes)->toHaveCount(1, "{$controller}::{$action} has no OpenAPI attribute");
        expect(is_subclass_of($controller, PayrollController::class))->toBeTrue();
    }
});

test('no route accepts user_id or tenant_id as a filter', function (): void {
    foreach (glob(base_path('modules/18_Payroll/src/Http/Requests/*.php')) as $file) {
        expect(file_get_contents($file))
            ->not->toContain("'user_id'")
            ->not->toContain("'tenant_id'");
    }
});

test('without a token every route answers 401', function (): void {
    auth()->forgetGuards();

    foreach (payrollRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $this->json($method, payrollRouteUri($route, (string) Str::uuid()))->assertUnauthorized();
        }
    }
});

test('a member without permissions gets 403 from everything but the own documents', function (): void {
    $id = (string) Str::uuid();
    $open = ['payslips.index', 'payslips.show', 'payslips.pdf', 'salary-certificates.index', 'salary-certificates.show', 'salary-certificates.pdf'];
    $checked = 0;

    foreach (payrollRoutes() as $route) {
        if (in_array(Str::after((string) $route->getName(), 'api.v1.payroll.'), $open, true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $status = payrollApi($this->worker, $this->tenant)->json($method, payrollRouteUri($route, $this->runId), [
                'year' => 2026, 'month' => 1, 'employee_id' => $id, 'reason' => 'Motivo valido', 'code' => 'H011', 'name' => 'Nome', 'kind' => 'Earning',
                'level' => '4', 'monthly_minimum' => 1, 'wage_type_code' => '1100', 'amount' => 1, 'absence_type' => 'Unpaid', 'start_date' => '2026-01-01',
                'end_date' => '2026-01-02', 'first_name' => 'Nome', 'date_of_birth' => '2020-01-01', 'canton' => 'TI', 'valid_from' => '2026-01-01',
                'child_allowance' => 215, 'education_allowance' => 268, 'parameters' => ['bvg_entry_threshold' => 1], 'content' => 'x',
            ])->status();

            expect(in_array($status, [403, 404], true))->toBeTrue("{$method} {$route->uri()} answered {$status}");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(45);
});

test('the employee sees only the own payslips', function (): void {
    payrollApi($this->worker, $this->tenant)->getJson('/api/v1/payroll/payslips')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_id', $this->employee->id);

    payrollApi($this->worker, $this->tenant)->getJson("/api/v1/payroll/payslips?employee_id={$this->employee->id}")->assertOk()->assertJsonCount(1, 'data');
    payrollApi($this->worker, $this->tenant)->getJson("/api/v1/payroll/payslips?employee_id={$this->colleagueEmployee->id}")->assertForbidden();
    payrollApi($this->worker, $this->tenant)->getJson("/api/v1/payroll/payslips/{$this->ownSlip->id}")->assertOk()->assertJsonCount(count($this->ownSlip->lines()->withoutGlobalScopes()->get()), 'data.lines');
    payrollApi($this->worker, $this->tenant)->getJson("/api/v1/payroll/payslips/{$this->otherSlip->id}")->assertForbidden();
    payrollApi($this->worker, $this->tenant)->getJson("/api/v1/payroll/payslips/{$this->otherSlip->id}/pdf")->assertForbidden();
    payrollApi($this->worker, $this->tenant)->get("/api/v1/payroll/payslips/{$this->ownSlip->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('a member without an employee record sees no payslip', function (): void {
    $stranger = payrollMember($this->tenant, 'member');

    payrollApi($stranger, $this->tenant)->getJson('/api/v1/payroll/payslips')->assertOk()->assertJsonCount(0, 'data');
});

test('the managers see every payslip', function (string $role): void {
    $manager = payrollMember($this->tenant, $role);

    payrollApi($manager, $this->tenant)->getJson('/api/v1/payroll/payslips')->assertOk()->assertJsonCount(2, 'data');
    payrollApi($manager, $this->tenant)->getJson("/api/v1/payroll/payslips?employee_id={$this->colleagueEmployee->id}")->assertOk()->assertJsonCount(1, 'data');
})->with(['owner', 'admin']);

test('payroll.read reads and does not write', function (): void {
    $reader = payrollMember($this->tenant, 'accountant', ['payroll.read']);

    payrollApi($reader, $this->tenant)->getJson('/api/v1/payroll/runs')->assertOk();
    payrollApi($reader, $this->tenant)->getJson('/api/v1/payroll/payslips')->assertOk()->assertJsonCount(2, 'data');
    payrollApi($reader, $this->tenant)->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 2])->assertOk();
    payrollApi($reader, $this->tenant)->postJson('/api/v1/payroll/runs/calculate', ['year' => 2026, 'month' => 2])->assertForbidden();
    payrollApi($reader, $this->tenant)->postJson("/api/v1/payroll/runs/{$this->runId}/finalize")->assertForbidden();
});

test('payroll.write and the wildcards can also write', function (array $permissions): void {
    $writer = payrollMember($this->tenant, 'member', $permissions);

    payrollApi($writer, $this->tenant)->postJson('/api/v1/payroll/runs/calculate', ['year' => 2026, 'month' => 2])->assertCreated();
    payrollApi($writer, $this->tenant)->getJson('/api/v1/payroll/runs')->assertOk();
})->with([
    'write' => [['payroll.write']],
    'module wildcard' => [['payroll.*']],
    'global wildcard' => [['*']],
]);

test('permissions of other modules give no access', function (): void {
    $sales = payrollMember($this->tenant, 'member', ['sales.*', 'crm.*']);

    payrollApi($sales, $this->tenant)->getJson('/api/v1/payroll/runs')->assertForbidden();
});

test('a role in another company does not count', function (): void {
    $otherTenant = payrollTenant();
    $user = payrollMember($this->tenant, 'member');
    $user->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    payrollApi($user, $this->tenant)->getJson('/api/v1/payroll/runs')->assertForbidden();
    payrollApi($user, $otherTenant)->getJson('/api/v1/payroll/runs')->assertOk();
});
