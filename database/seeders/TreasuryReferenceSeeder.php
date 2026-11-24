<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class TreasuryReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        // 1. Forecast Scenarios
        $scenarios = [
            [
                'code' => 'BASELINE',
                'name' => 'Scenario Base / Standard Operating Model',
                'description' => 'Flusso di cassa previsionale basato sui termini contrattuali standard',
                'is_baseline' => true,
                'collection_delay_days' => 0,
                'disbursement_acceleration_days' => 0,
                'sales_growth_factor' => 1.0000,
            ],
            [
                'code' => 'OPTIMISTIC',
                'name' => 'Scenario Espansivo / Early Collections',
                'description' => 'Accelerazione incassi del 10% e rispetto rigoroso scadenze fornitori',
                'is_baseline' => false,
                'collection_delay_days' => -5,
                'disbursement_acceleration_days' => 0,
                'sales_growth_factor' => 1.1500,
            ],
            [
                'code' => 'STRESSED',
                'name' => 'Scenario Stress Test / Liquidity Pressure',
                'description' => 'Ritardo incassi medi di 30 giorni e anticipazione uscite fornitori',
                'is_baseline' => false,
                'collection_delay_days' => 30,
                'disbursement_acceleration_days' => 5,
                'sales_growth_factor' => 0.8500,
            ],
        ];

        foreach ($scenarios as $scenario) {
            $existing = DB::table('forecast_scenarios')
                ->where('tenant_id', $tenantId)
                ->where('code', $scenario['code'])
                ->first();

            if ($existing) {
                DB::table('forecast_scenarios')->where('id', $existing->id)->update([
                    'name' => $scenario['name'],
                    'description' => $scenario['description'],
                    'is_baseline' => $scenario['is_baseline'],
                    'collection_delay_days' => $scenario['collection_delay_days'],
                    'disbursement_acceleration_days' => $scenario['disbursement_acceleration_days'],
                    'sales_growth_factor' => $scenario['sales_growth_factor'],
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('forecast_scenarios')->insert(array_merge($scenario, [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        // 2. Cash Concentration Pool (Zero Balance Account ZBA)
        $headerAccount = DB::table('bank_accounts')
            ->where('tenant_id', $tenantId)
            ->where('is_pool_header', true)
            ->first();

        if ($headerAccount) {
            $existingPool = DB::table('cash_pools')
                ->where('tenant_id', $tenantId)
                ->where('pool_name', 'European Central Cash Pool (EUR ZBA)')
                ->first();

            if ($existingPool) {
                DB::table('cash_pools')->where('id', $existingPool->id)->update([
                    'pool_structure' => 'ZERO_BALANCE',
                    'header_bank_account_id' => $headerAccount->id,
                    'currency' => 'EUR',
                    'sweeping_frequency' => 'DAILY',
                    'target_balance_floor' => 10000.00,
                    'target_balance_ceiling' => 500000.00,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('cash_pools')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'pool_name' => 'European Central Cash Pool (EUR ZBA)',
                    'pool_structure' => 'ZERO_BALANCE',
                    'header_bank_account_id' => $headerAccount->id,
                    'currency' => 'EUR',
                    'sweeping_frequency' => 'DAILY',
                    'target_balance_floor' => 10000.00,
                    'target_balance_ceiling' => 500000.00,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
