<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class VatCodeSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $codes = [
            [
                'code' => 'IVA22',
                'description' => 'Standard VAT 22% (IVA Ordinaria 22% / Normalsatz / Taux normal)',
                'rate' => 22.00,
                'country' => 'IT',
                'rc' => false,
                'sp' => false,
            ],
            [
                'code' => 'IVA10',
                'description' => 'Reduced VAT 10% (IVA Ridotta 10% / Reduzierter Satz / Taux réduit)',
                'rate' => 10.00,
                'country' => 'IT',
                'rc' => false,
                'sp' => false,
            ],
            [
                'code' => 'IVA04',
                'description' => 'Super Reduced VAT 4% (IVA Minima 4% / Stark reduzierter Satz / Taux super-réduit)',
                'rate' => 4.00,
                'country' => 'IT',
                'rc' => false,
                'sp' => false,
            ],
            [
                'code' => 'REVERSE_CHARGE',
                'description' => 'Reverse Charge Art. 17 c. 6 (Inversione contabile / Steuerschuldumkehr / Autoliquidation)',
                'rate' => 0.00,
                'country' => 'IT',
                'rc' => true,
                'sp' => false,
            ],
            [
                'code' => 'SPLIT_PAYMENT',
                'description' => 'Split Payment PA (Scissione pagamenti / Split-Verfahren / Paiement fractionné)',
                'rate' => 22.00,
                'country' => 'IT',
                'rc' => false,
                'sp' => true,
            ],
            [
                'code' => 'CH_MWST_81',
                'description' => 'Swiss Standard MWST 8.1% (TVA normale suisse / Schweizer Normalsatz)',
                'rate' => 8.10,
                'country' => 'CH',
                'rc' => false,
                'sp' => false,
            ],
            [
                'code' => 'CH_MWST_26',
                'description' => 'Swiss Reduced MWST 2.6% (TVA réduite suisse / Schweizer reduzierter Satz)',
                'rate' => 2.60,
                'country' => 'CH',
                'rc' => false,
                'sp' => false,
            ],
            [
                'code' => 'EXEMPT',
                'description' => 'VAT Exempt (Operazione Esente / Steuerbefreit / Opération exonérée)',
                'rate' => 0.00,
                'country' => 'IT',
                'rc' => false,
                'sp' => false,
            ],
            // Italian operations without VAT, by SDI natura code (issue #286).
            ['code' => 'IT_N1', 'description' => 'Excluded art. 15 (Escluse ex art. 15 DPR 633/72)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N1'],
            ['code' => 'IT_N2_1', 'description' => 'Out of scope arts. 7-7septies (Non soggette artt. 7-7 septies)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N2.1'],
            ['code' => 'IT_N2_2', 'description' => 'Out of scope, other cases / flat-rate regime (Non soggette - altri casi / regime forfettario)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N2.2'],
            ['code' => 'IT_N3_1', 'description' => 'Non-taxable exports (Non imponibili - esportazioni)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N3.1'],
            ['code' => 'IT_N3_2', 'description' => 'Non-taxable intra-EU supplies (Non imponibili - cessioni intracomunitarie)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N3.2'],
            ['code' => 'IT_N3_3', 'description' => 'Non-taxable supplies to San Marino (Non imponibili - cessioni verso San Marino)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N3.3'],
            ['code' => 'IT_N3_4', 'description' => 'Non-taxable, treated as exports (Non imponibili - operazioni assimilate)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N3.4'],
            ['code' => 'IT_N3_5', 'description' => 'Non-taxable, declaration of intent (Non imponibili - dichiarazioni d\'intento)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N3.5'],
            ['code' => 'IT_N3_6', 'description' => 'Non-taxable, other (Non imponibili - altre operazioni)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N3.6'],
            ['code' => 'IT_N4', 'description' => 'Exempt art. 10 (Esenti art. 10 DPR 633/72)', 'rate' => 0.00, 'country' => 'IT', 'rc' => false, 'sp' => false, 'nature' => 'N4'],
        ];

        $hasNature = Schema::hasColumn('vat_codes', 'nature');

        foreach ($codes as $c) {
            $existing = DB::table('vat_codes')->where('code', $c['code'])->first();

            if (! $existing) {
                DB::table('vat_codes')->insert(array_merge([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'code' => $c['code'],
                    'country_iso' => substr($c['country'], 0, 2),
                    'country' => $c['country'],
                    'rate' => $c['rate'],
                    'tax_rate_percent' => $c['rate'],
                    'description' => $c['description'],
                    'is_reverse_charge' => $c['rc'],
                    'is_split_payment' => $c['sp'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $hasNature && isset($c['nature']) ? ['nature' => $c['nature']] : []));
            } else {
                DB::table('vat_codes')->where('id', $existing->id)->update(array_merge([
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'country_iso' => substr($c['country'], 0, 2),
                    'country' => $c['country'],
                    'rate' => $c['rate'],
                    'tax_rate_percent' => $c['rate'],
                    'description' => $c['description'],
                    'is_reverse_charge' => $c['rc'],
                    'is_split_payment' => $c['sp'],
                    'updated_at' => now(),
                ], $hasNature && isset($c['nature']) ? ['nature' => $c['nature']] : []));
            }
        }
    }
}
