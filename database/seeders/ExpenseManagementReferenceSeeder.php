<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ExpenseManagement\Models\AciVehicleRate;
use Modules\ExpenseManagement\Models\ExpenseCategory;
use Modules\ExpenseManagement\Models\ExpensePolicy;
use Modules\ExpenseManagement\Models\ExpensePolicyRule;

/**
 * Global reference data of the expense module (Mod 075): categories with fiscal flags and default ledger / VAT mapping,
 * the Swiss and Italian expense policies, and sample vehicle cost-per-km rates. Idempotent, rows without a tenant.
 *
 * The caps, the Swiss regulation values and the vehicle rates are SAMPLE figures for demonstration and tests: the official
 * ACI tables (published every half year) and the TCS rates must be loaded by the company, and the caps reviewed against its own
 * expense regulation. Labels of the categories live in the frontend dictionary (expenseManagement.category.<code>).
 */
final class ExpenseManagementReferenceSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    private const array Categories = [
        ['code' => 'meals', 'is_meal' => true, 'vat_recoverable' => true, 'gl' => '6420', 'vat' => 'IVA22', 'rate' => '10.0000', 'sort' => 10],
        ['code' => 'lodging', 'is_lodging' => true, 'vat_recoverable' => true, 'gl' => '6430', 'vat' => 'IVA10', 'rate' => '10.0000', 'sort' => 20],
        ['code' => 'local_transport', 'is_transport' => true, 'vat_recoverable' => true, 'gl' => '6440', 'vat' => 'IVA10', 'rate' => '10.0000', 'sort' => 30],
        ['code' => 'fuel', 'vat_recoverable' => true, 'gl' => '6450', 'vat' => 'IVA22', 'rate' => '22.0000', 'sort' => 40],
        ['code' => 'mileage', 'is_mileage' => true, 'vat_recoverable' => false, 'requires_receipt' => false, 'gl' => '6460', 'vat' => null, 'rate' => '0.0000', 'sort' => 50],
        ['code' => 'tolls_parking', 'is_transport' => true, 'vat_recoverable' => true, 'gl' => '6470', 'vat' => 'IVA22', 'rate' => '22.0000', 'sort' => 60],
        ['code' => 'supplies', 'vat_recoverable' => true, 'gl' => '6480', 'vat' => 'IVA22', 'rate' => '22.0000', 'sort' => 70],
        ['code' => 'representation', 'vat_recoverable' => false, 'gl' => '6490', 'vat' => 'IVA22', 'rate' => '22.0000', 'sort' => 80],
        ['code' => 'other', 'vat_recoverable' => false, 'gl' => '6900', 'vat' => null, 'rate' => '0.0000', 'sort' => 90],
    ];

    public function run(): void
    {
        foreach (self::Categories as $definition) {
            ExpenseCategory::updateOrCreateGlobal(['code' => $definition['code']], [
                'is_meal' => $definition['is_meal'] ?? false,
                'is_lodging' => $definition['is_lodging'] ?? false,
                'is_transport' => $definition['is_transport'] ?? false,
                'is_mileage' => $definition['is_mileage'] ?? false,
                'vat_recoverable' => $definition['vat_recoverable'],
                'requires_receipt' => $definition['requires_receipt'] ?? true,
                'default_gl_account_code' => $definition['gl'],
                'default_vat_code' => $definition['vat'],
                'default_vat_rate' => $definition['rate'],
                'sort_order' => $definition['sort'],
                'is_active' => true,
            ]);
        }

        $this->policy('IT_STANDARD', 'Italy — standard expense policy', 'IT', ['receipt_threshold' => '0.00', 'block_non_traceable' => false, 'max_age_days' => 90], [
            ['meals', ExpensePolicyRule::MaxPerItem, '60.00', 'warning'],
            ['lodging', ExpensePolicyRule::MaxPerItem, '180.00', 'warning'],
            ['meals', ExpensePolicyRule::MaxPerDay, '90.00', 'warning'],
            [null, ExpensePolicyRule::MaxPerItem, '2000.00', 'blocking'],
        ]);

        $this->policy('CH_STANDARD', 'Switzerland — expense regulation (Spesenreglement)', 'CH', ['receipt_threshold' => '25.00', 'mileage_rate_per_km' => '0.7000', 'per_diem_daily_limit' => '120.00', 'max_age_days' => 90], [
            ['meals', ExpensePolicyRule::MaxPerItem, '70.00', 'warning'],
            ['lodging', ExpensePolicyRule::MaxPerItem, '220.00', 'warning'],
            ['meals', ExpensePolicyRule::MaxPerDay, '100.00', 'warning'],
            [null, ExpensePolicyRule::MaxPerItem, '2500.00', 'blocking'],
        ]);

        $this->vehicleRates();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string|null, 1: string, 2: string, 3: string}>  $rules
     */
    private function policy(string $code, string $name, string $jurisdiction, array $attributes, array $rules): void
    {
        $policy = ExpensePolicy::updateOrCreateGlobal(['code' => $code], $attributes + [
            'name' => $name,
            'jurisdiction' => $jurisdiction,
            'is_active' => true,
            'duplicate_detection' => true,
            'block_non_traceable' => false,
        ]);

        foreach ($rules as [$category, $type, $limit, $severity]) {
            ExpensePolicyRule::updateOrCreateGlobal(
                ['policy_id' => $policy->id, 'category_code' => $category, 'rule_type' => $type],
                ['limit_amount' => $limit, 'severity' => $severity],
            );
        }
    }

    private function vehicleRates(): void
    {
        // [code, make/model, fuel, class, bracket 15,000 km, bracket 30,000 km]
        $aci = [
            ['FIAT-PANDA-HYBRID', 'Fiat Panda 1.0 Hybrid', 'hybrid', 'city', '0.3962', '0.3105'],
            ['VW-GOLF-TDI', 'Volkswagen Golf 2.0 TDI', 'diesel', 'compact', '0.5481', '0.4297'],
            ['TOYOTA-COROLLA-HYBRID', 'Toyota Corolla 1.8 Hybrid', 'hybrid', 'compact', '0.5127', '0.4034'],
            ['TESLA-MODEL-3', 'Tesla Model 3', 'electric', 'executive', '0.4758', '0.3811'],
        ];

        foreach ($aci as [$code, $model, $fuel, $class, $low, $high]) {
            foreach ([[15000, $low], [30000, $high]] as [$bracket, $rate]) {
                AciVehicleRate::updateOrCreateGlobal(
                    ['source' => 'IT_ACI_TABLE', 'vehicle_code' => $code, 'annual_km_bracket' => $bracket, 'valid_from' => '2028-01-01'],
                    ['make_model' => $model, 'fuel' => $fuel, 'vehicle_class' => $class, 'rate_per_km' => $rate, 'currency' => 'EUR', 'rate_period' => '2028-H1', 'valid_to' => null],
                );
                AciVehicleRate::updateOrCreateGlobal(
                    ['source' => 'IT_ACI_TABLE', 'vehicle_code' => $code, 'annual_km_bracket' => $bracket, 'valid_from' => '2024-01-01'],
                    ['make_model' => $model, 'fuel' => $fuel, 'vehicle_class' => $class, 'rate_per_km' => bcsub($rate, '0.0100', 4), 'currency' => 'EUR', 'rate_period' => '2024-2027H1', 'valid_to' => '2027-06-30'],
                );
                AciVehicleRate::updateOrCreateGlobal(
                    ['source' => 'IT_ACI_TABLE', 'vehicle_code' => $code, 'annual_km_bracket' => $bracket, 'valid_from' => '2027-07-01'],
                    ['make_model' => $model, 'fuel' => $fuel, 'vehicle_class' => $class, 'rate_per_km' => bcadd($rate, '0.0100', 4), 'currency' => 'EUR', 'rate_period' => '2027-H2', 'valid_to' => '2027-12-31'],
                );
            }
        }

        foreach ([['TCS-COMPACT', 'TCS compact car (up to 1.6 l)', 'petrol', 'compact', '0.6800'], ['TCS-MIDSIZE', 'TCS mid-size car', 'petrol', 'midsize', '0.7600']] as [$code, $model, $fuel, $class, $rate]) {
            AciVehicleRate::updateOrCreateGlobal(
                ['source' => 'CH_TCS', 'vehicle_code' => $code, 'annual_km_bracket' => 15000, 'valid_from' => '2024-01-01'],
                ['make_model' => $model, 'fuel' => $fuel, 'vehicle_class' => $class, 'rate_per_km' => $rate, 'currency' => 'CHF', 'rate_period' => '2024', 'valid_to' => null],
            );
        }
    }
}
