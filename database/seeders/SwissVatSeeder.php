<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds global (tenant_id = null) Swiss VAT tax codes for both current (2024+) and historical (2018-2023)
 * epochs, distinguishing exempt supplies with/without input tax deduction, out-of-scope flows,
 * Bezugsteuer (Art. 45 LIVA), and ESTV Saldosteuersatz sector rate codes.
 */
final class SwissVatSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            // Current epoch (2024-01-01 onwards)
            [
                'code' => 'CH_STD_81',
                'name' => 'Standard MWST / IVA / TVA 8.1%',
                'description' => 'Normal rate from 01.01.2024 (Art. 25(1) LIVA) - Box 303',
                'rate_type' => 'standard',
                'exemption_type' => 'none',
                'rate_percent' => 8.10,
                'afc_box' => '303',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'CH_RED_26',
                'name' => 'Reduced MWST / IVA / TVA 2.6%',
                'description' => 'Essential goods, food, books, medicine from 01.01.2024 (Art. 25(2) LIVA) - Box 313',
                'rate_type' => 'reduced',
                'exemption_type' => 'none',
                'rate_percent' => 2.60,
                'afc_box' => '313',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'CH_ACC_38',
                'name' => 'Accommodation MWST / IVA / TVA 3.8%',
                'description' => 'Special accommodation rate from 01.01.2024 (Art. 25(4) LIVA) - Box 343',
                'rate_type' => 'accommodation',
                'exemption_type' => 'none',
                'rate_percent' => 3.80,
                'afc_box' => '343',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'CH_EXP_WITH_DED_0',
                'name' => 'Exempt with Input Tax Deduction 0.0% (Art. 23 LIVA)',
                'description' => 'Exports & tax-exempt supplies with right to input tax deduction - Box 220',
                'rate_type' => 'zero_exempt',
                'exemption_type' => 'exempt_with_deduction',
                'rate_percent' => 0.00,
                'afc_box' => '220',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2018-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'CH_EXP_ABROAD_0',
                'name' => 'Place of Supply Abroad 0.0% (Box 221)',
                'description' => 'Supplies performed outside Switzerland with input tax deduction - Box 221',
                'rate_type' => 'zero_exempt',
                'exemption_type' => 'exempt_with_deduction',
                'rate_percent' => 0.00,
                'afc_box' => '221',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2018-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'CH_EXEMPT_NO_DED_0',
                'name' => 'Exempt without Input Tax Deduction 0.0% (Art. 21 LIVA)',
                'description' => 'Healthcare, education, insurance, real estate exempt without credit - Box 230',
                'rate_type' => 'zero_exempt',
                'exemption_type' => 'exempt_without_deduction',
                'rate_percent' => 0.00,
                'afc_box' => '230',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => false,
                'effective_from' => '2018-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'CH_OUT_OF_SCOPE_900',
                'name' => 'Out of Scope / Subsidies 0.0% (Art. 18(2) LIVA)',
                'description' => 'Subsidies and non-remuneration flows - Box 900',
                'rate_type' => 'zero_exempt',
                'exemption_type' => 'out_of_scope',
                'rate_percent' => 0.00,
                'afc_box' => '900',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => false,
                'effective_from' => '2018-01-01',
                'effective_to' => null,
            ],
            [
                'code' => 'CH_BEZUG_81',
                'name' => 'Bezugsteuer Foreign Services 8.1% (Art. 45 LIVA)',
                'description' => 'Reverse-charge acquisition tax on foreign services from 01.01.2024 - Box 383',
                'rate_type' => 'bezugsteuer',
                'exemption_type' => 'none',
                'rate_percent' => 8.10,
                'afc_box' => '383',
                'is_bezugsteuer' => true,
                'is_input_tax_deductible' => true,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
            ],
            // Historical epoch (2018-01-01 .. 2023-12-31)
            [
                'code' => 'CH_STD_77',
                'name' => 'Standard MWST 7.7% (2018-2023)',
                'description' => 'Historical normal rate 2018-2023 - Box 302',
                'rate_type' => 'standard',
                'exemption_type' => 'none',
                'rate_percent' => 7.70,
                'afc_box' => '302',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2018-01-01',
                'effective_to' => '2023-12-31',
            ],
            [
                'code' => 'CH_RED_25',
                'name' => 'Reduced MWST 2.5% (2018-2023)',
                'description' => 'Historical reduced rate 2018-2023 - Box 312',
                'rate_type' => 'reduced',
                'exemption_type' => 'none',
                'rate_percent' => 2.50,
                'afc_box' => '312',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2018-01-01',
                'effective_to' => '2023-12-31',
            ],
            [
                'code' => 'CH_ACC_37',
                'name' => 'Accommodation MWST 3.7% (2018-2023)',
                'description' => 'Historical accommodation rate 2018-2023 - Box 342',
                'rate_type' => 'accommodation',
                'exemption_type' => 'none',
                'rate_percent' => 3.70,
                'afc_box' => '342',
                'is_bezugsteuer' => false,
                'is_input_tax_deductible' => true,
                'effective_from' => '2018-01-01',
                'effective_to' => '2023-12-31',
            ],
            [
                'code' => 'CH_BEZUG_77',
                'name' => 'Bezugsteuer Foreign Services 7.7% (2018-2023)',
                'description' => 'Historical acquisition tax 2018-2023 - Box 382',
                'rate_type' => 'bezugsteuer',
                'exemption_type' => 'none',
                'rate_percent' => 7.70,
                'afc_box' => '382',
                'is_bezugsteuer' => true,
                'is_input_tax_deductible' => true,
                'effective_from' => '2018-01-01',
                'effective_to' => '2023-12-31',
            ],
        ];

        $now = now();

        foreach ($codes as $row) {
            $exists = DB::table('swiss_vat_tax_codes')
                ->whereNull('tenant_id')
                ->where('code', $row['code'])
                ->where('effective_from', $row['effective_from'])
                ->exists();

            if (! $exists) {
                DB::table('swiss_vat_tax_codes')->insert($row + [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => null,
                    'sss_sector_code' => null,
                    'is_active' => true,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
