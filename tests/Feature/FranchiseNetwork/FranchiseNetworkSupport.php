<?php

declare(strict_types=1);

use App\Models\Tenant;
use Database\Seeders\FranchiseNetworkReferenceSeeder;
use Illuminate\Support\Str;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * A franchisor company (owner signed in), a franchisee company with its own books and an unrelated company, all with an open
 * fiscal calendar, and the global catalogues (formula presets, audit templates, benchmark dimensions).
 */
function fnetSetUp(object $test, string $role = 'owner'): void
{
    $suffix = Str::upper(Str::random(5));
    $test->tenant = Tenant::create(['name' => 'Franchisor '.$suffix, 'legal_name' => 'Franchisor '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->franchiseeTenant = Tenant::create(['name' => 'Affiliato '.$suffix, 'legal_name' => 'Affiliato '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => 'IT01234567890']);
    $test->otherTenant = Tenant::create(['name' => 'Estranea '.$suffix, 'legal_name' => 'Estranea '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);

    foreach ([$test->tenant, $test->franchiseeTenant, $test->otherTenant] as $tenant) {
        fiscalCalendar($tenant, (int) now()->year);
    }

    (new FranchiseNetworkReferenceSeeder)->run();
    $test->period = now()->subMonthNoOverflow()->format('Y-m');
    $test->director = treasuryActor($test, $role);
}

/**
 * Signs in a member of a company with a role for the next requests.
 */
function fnetActAs(object $test, string $role, ?Tenant $tenant = null): void
{
    treasuryActor($test, $role, $tenant ?? $test->tenant);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnetFormula(object $test, array $overrides = []): array
{
    return $test->postJson('/api/v1/franchise-network/formulas', array_merge([
        'code' => 'F-'.Str::upper(Str::random(6)),
        'labels' => ['it' => 'Formula', 'en' => 'Formula', 'de' => 'Formel', 'fr' => 'Formule'],
        'royalty_tiers' => [['from' => 0, 'to' => null, 'rate' => 6]],
        'marketing_tiers' => [['from' => 0, 'to' => null, 'rate' => 2]],
        'minimum_guarantee' => 0,
        'startup_months' => 0,
        'startup_mode' => 'none',
        'currency' => 'EUR',
    ], $overrides))->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnetStore(object $test, string $code, array $overrides = []): array
{
    return $test->postJson('/api/v1/franchise-network/stores', array_merge([
        'code' => $code,
        'name' => 'Negozio '.$code,
        'store_type' => 'franchisee',
        'country' => 'IT',
        'city' => 'Milano',
        'postal_code' => '20100',
        'size_cluster' => 'medium',
        'opened_on' => now()->subYears(3)->toDateString(),
    ], $overrides))->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnetAgreementPayload(string $storeId, ?string $formulaId, array $overrides = []): array
{
    $start = now()->subMonths(6)->startOfMonth();

    return array_merge([
        'store_id' => $storeId,
        'formula_id' => $formulaId,
        'jurisdiction' => 'IT',
        'disclosure_delivered_on' => $start->copy()->subDays(60)->toDateString(),
        'signed_on' => $start->toDateString(),
        'start_date' => $start->toDateString(),
        'end_date' => $start->copy()->addYears(3)->subDay()->toDateString(),
        'payment_method' => 'bank_transfer',
        'currency' => 'EUR',
    ], $overrides);
}

/**
 * Creates and activates an agreement.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnetAgreement(object $test, string $storeId, ?string $formulaId, array $overrides = [], bool $activate = true): array
{
    $agreement = $test->postJson('/api/v1/franchise-network/agreements', fnetAgreementPayload($storeId, $formulaId, $overrides))->assertCreated()->json('data');

    return $activate
        ? $test->postJson('/api/v1/franchise-network/agreements/'.$agreement['id'].'/activate')->assertOk()->json('data')
        : $agreement;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fnetSellOut(object $test, string $storeId, string $period, array $overrides = []): array
{
    return $test->putJson('/api/v1/franchise-network/stores/'.$storeId.'/sell-out/'.$period, array_merge([
        'gross_sales' => 10000, 'returns' => 200, 'discounts' => 300, 'tickets_count' => 250, 'visitors_count' => 800,
        'cost_of_goods_sold' => 5000, 'average_inventory_value' => 12000, 'source' => 'pos',
    ], $overrides))->assertOk()->json('data');
}
