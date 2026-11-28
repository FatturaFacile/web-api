<?php

declare(strict_types=1);

use Database\Seeders\CrisisMonitoringReferenceSeeder;

require_once __DIR__.'/../LiquidityPlanning/LiquiditySupport.php';

/**
 * Builders of the crisis monitoring tests (Mod 357). They reuse the liquidity support (two companies, an accountant on the first one)
 * and seed the global reference rows BEFORE any company context is set, so that they stay global.
 */
function crisisSetUp(object $test): void
{
    $test->seed(CrisisMonitoringReferenceSeeder::class);
    lpSetUp($test);
}

/**
 * A company with 50,000 in the bank, 20,000 of receivables, 3,000 of payables and 10,500 of debt service in the first month.
 *
 * Treasury DSCR over six months: (20,000 - 3,000 + 50,000) / 10,500 = 6.38. Operating DSCR: (20,000 - 3,000) / 10,500 = 1.62.
 */
function crisisScenario(object $test): void
{
    lpBank($test, '50000.00');
    lpManualItem($test, 'inflow', 'receivable', '20000.00', 10);
    lpManualItem($test, 'outflow', 'payable', '3000.00', 5);
    lpManualItem($test, 'outflow', 'debt_principal', '10000.00', 20);
    lpManualItem($test, 'outflow', 'debt_interest', '500.00', 20);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function crisisLoanPayload(array $overrides = []): array
{
    return array_merge([
        'creditor_name' => 'Mario Rossi', 'creditor_type' => 'shareholder', 'principal' => '100000', 'interest_rate' => '5',
        'interest_treatment' => 'deferred', 'agreement_date' => now()->toDateString(), 'effective_from' => now()->subDay()->toDateString(),
        'deferral_end_date' => null, 'scope' => 'principal_and_interest', 'status' => 'active', 'notes' => null,
    ], $overrides);
}
