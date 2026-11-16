<?php

declare(strict_types=1);

use App\Models\LookupItem;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SwissPayrollReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;
use Modules\HumanResources\Models\Payroll\WageType;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Tests\Feature\HumanResources\Payroll\PayrollFixtures;

uses(DatabaseTransactions::class);

test('the reference seeder creates idempotent system defaults that every tenant can read', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();

    $this->seed(SwissPayrollReferenceSeeder::class);
    $counts = [
        WageType::withoutGlobalScopes()->whereNull('tenant_id')->count(),
        PayrollRateSet::withoutGlobalScopes()->whereNull('tenant_id')->count(),
        WithholdingTariff::withoutGlobalScopes()->whereNull('tenant_id')->count(),
    ];
    $this->seed(SwissPayrollReferenceSeeder::class);

    expect([
        WageType::withoutGlobalScopes()->whereNull('tenant_id')->count(),
        PayrollRateSet::withoutGlobalScopes()->whereNull('tenant_id')->count(),
        WithholdingTariff::withoutGlobalScopes()->whereNull('tenant_id')->count(),
    ])->toBe($counts)
        ->and($counts[0])->toBeGreaterThanOrEqual(9)
        ->and($counts[1])->toBe(2)
        ->and(app(TenantContext::class)->getTenantId())->toBe((string) $tenant->id);

    // System rows are visible through the tenant scope and parse into valid rate sets.
    $currentYear = (int) now()->format('Y');
    $rateSet = PayrollRateSet::query()->where('code', "CH-{$currentYear}")->firstOrFail();

    expect(RateSet::fromArray($rateSet->rates)->ahvEmployeePpm)->toBe(53_000)
        ->and(WageType::query()->where('code', '1000')->exists())->toBeTrue()
        ->and(WithholdingTariff::query()->where('canton', 'TI')->where('tariff_code', 'A0Y')->exists())->toBeTrue();
});

test('the payroll permissions are part of the permission catalog', function (): void {
    (new PermissionSeeder)->run();

    expect(LookupItem::query()->whereIn('code', ['hr.payroll.view', 'hr.payroll.manage'])->count())->toBe(2);
});

test('tenants manage their own wage types but cannot edit system defaults', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    $this->seed(SwissPayrollReferenceSeeder::class);
    Sanctum::actingAs($user, ['*']);

    $systemWageType = WageType::query()->whereNull('tenant_id')->where('code', '1000')->firstOrFail();

    $this->putJson("/api/v1/hr/payroll/wage-types/{$systemWageType->id}", ['name' => 'Hijacked'])->assertForbidden();

    $created = $this->postJson('/api/v1/hr/payroll/wage-types', [
        'code' => '1250',
        'name' => 'Commission',
        'category' => 'allowance',
        'is_bvg_subject' => false,
    ])->assertCreated()
        ->assertJsonPath('data.is_system_default', false);

    $this->postJson('/api/v1/hr/payroll/wage-types', ['code' => '1250', 'name' => 'Duplicate', 'category' => 'allowance'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    $this->putJson('/api/v1/hr/payroll/wage-types/'.$created->json('data.id'), ['name' => 'Sales commission'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Sales commission');

    $codes = collect($this->getJson('/api/v1/hr/payroll/wage-types')->assertOk()->json('data'))->pluck('code');

    expect($codes)->toContain('1000')->toContain('1250')
        ->and(WageType::query()->whereKey($systemWageType->id)->value('name'))->toBe('Monthly salary');
});

test('tenant rate sets are validated as a whole before being stored', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    Sanctum::actingAs($user, ['*']);

    $rates = SwissPayrollReferenceSeeder::indicativeRates(2027);

    $broken = $rates;
    unset($broken['bvg']['coordination_deduction']);

    $this->postJson('/api/v1/hr/payroll/rate-sets', [
        'code' => 'TENANT-2027',
        'name' => 'Own pension plan 2027',
        'year' => 2027,
        'valid_from' => '2027-01-01',
        'rates' => $broken,
    ])->assertStatus(422)->assertJsonValidationErrors('rates');

    $this->postJson('/api/v1/hr/payroll/rate-sets', [
        'code' => 'TENANT-2027',
        'name' => 'Own pension plan 2027',
        'year' => 2027,
        'valid_from' => '2027-01-01',
        'valid_to' => '2027-12-31',
        'rates' => $rates,
    ])->assertCreated()
        ->assertJsonPath('data.tenant_id', (string) $tenant->id)
        ->assertJsonPath('data.year', 2027);

    $this->getJson('/api/v1/hr/payroll/rate-sets?year=2027')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
