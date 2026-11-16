<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResources\Payroll;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\Payroll\PayrollProfile;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;
use Modules\HumanResources\Models\Payroll\WageType;

/**
 * Database fixtures shared by the Swiss payroll feature tests.
 */
final class PayrollFixtures
{
    /**
     * Creates a tenant, makes it the active tenant and attaches a user with the given membership.
     *
     * @param  list<string>  $permissions
     * @return array{0: Tenant, 1: User}
     */
    public static function tenantWithUser(string $role = 'admin', array $permissions = []): array
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($tenant);

        return [$tenant, self::member($tenant, $role, $permissions)];
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function member(Tenant $tenant, string $role = 'member', array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, [
            'id' => (string) Str::uuid(),
            'role' => $role,
            'permissions' => json_encode($permissions),
        ]);

        return $user;
    }

    /**
     * Base salary wage type and a tenant rate set for the year (indicative seeder values plus overrides).
     *
     * @param  array<string, mixed>  $rateOverrides
     */
    public static function referenceData(Tenant $tenant, int $year = 2026, array $rateOverrides = []): PayrollRateSet
    {
        if (! WageType::query()->where('tenant_id', $tenant->id)->where('code', '1000')->exists()) {
            WageType::factory()->baseSalary()->create(['tenant_id' => $tenant->id]);
        }

        return PayrollRateSet::factory()
            ->forYear($year)
            ->withRates($rateOverrides)
            ->create(['tenant_id' => $tenant->id]);
    }

    /**
     * Employee earning CHF 96'000 per year at 100% (CHF 8'000 per month), born 1986, working in ZH, with an
     * open-ended contract and a payroll profile (UVG class A1, KTG insured).
     *
     * @param  array<string, mixed>  $employee
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $profile
     */
    public static function employee(Tenant $tenant, array $employee = [], array $contract = [], array $profile = []): Employee
    {
        $model = Employee::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'birth_date' => '1986-03-10',
            'hire_date' => '2020-01-01',
            'termination_date' => null,
            'currency' => 'CHF',
        ], $employee));

        EmploymentContract::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'employee_id' => $model->id,
            'start_date' => '2020-01-01',
            'end_date' => null,
            'base_annual_salary' => 96000.00,
            'fte_percentage' => 100.00,
            'currency' => 'CHF',
        ], $contract));

        PayrollProfile::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'employee_id' => $model->id,
        ], $profile));

        return $model;
    }
}
