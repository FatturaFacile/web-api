<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ExpenseManagementReferenceSeeder;
use Illuminate\Testing\TestResponse;
use Modules\ExpenseManagement\Models\ExpenseCategory;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Builders of the expense management tests (Mod 075): an Italian or Swiss company with the seeded categories, policies and
 * vehicle rates, and members signed in with a role.
 */
function exmSetUp(object $test, string $country = 'IT'): void
{
    $currency = $country === 'CH' ? 'CHF' : 'EUR';
    $test->tenant = Tenant::create(['name' => 'Spese Srl', 'legal_name' => 'Spese Srl', 'country' => $country, 'default_currency' => $currency]);
    (new ExpenseManagementReferenceSeeder)->run();
    $test->currency = $currency;
    $test->employee = treasuryActor($test, 'member');
}

function exmCategory(string $code): string
{
    return (string) ExpenseCategory::query()->where('code', $code)->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')->value('id');
}

function exmActAs(object $test, string $role): User
{
    return treasuryActor($test, $role);
}

/**
 * @return array<string, mixed>
 */
function exmReportPayload(object $test): array
{
    return ['title' => 'Trip', 'period_start' => now()->subDays(6)->toDateString(), 'period_end' => now()->toDateString(), 'currency' => $test->currency];
}

function exmCreateReport(object $test): string
{
    return (string) $test->postJson('/api/v1/expense-management/reports', exmReportPayload($test))->assertCreated()->json('data.id');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function exmLinePayload(object $test, string $category, array $overrides = []): array
{
    return array_merge([
        'expense_date' => now()->subDays(2)->toDateString(),
        'category_id' => exmCategory($category),
        'merchant_name' => 'Ristorante Alfa',
        'original_amount' => '40.00',
        'original_currency' => $test->currency,
        'payment_method' => 'employee_out_of_pocket',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function exmAddLine(object $test, string $reportId, string $category, array $overrides = []): TestResponse
{
    return $test->postJson("/api/v1/expense-management/reports/{$reportId}/lines", exmLinePayload($test, $category, $overrides));
}
