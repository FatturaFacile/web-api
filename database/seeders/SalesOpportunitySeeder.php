<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class SalesOpportunitySeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('contacts') || ! Schema::hasTable('sales_opportunities')) {
            return;
        }

        $customers = DB::table('contacts')->take(4)->get();

        $titles = [
            'Rinnovo Licenze ERP Enterprise 2027 / ERP Renewal 2027 / Lizenzverlängerung / Renouvellement de Licence',
            'Fornitura Moduli Cloud & Integrazioni / Cloud & Integration Rollout / Cloud-Rollout / Déploiement Cloud',
            'Progetto Logistica e WMS Avanzato / Advanced WMS Expansion / WMS-Erweiterung / Projet WMS Avancé',
            'Consulenza e Formazione Avanzata / Professional Advisory & Training / Beratung & Schulung / Conseil & Formation',
        ];

        foreach ($customers as $index => $customer) {
            $oppNumber = 'OPP-2027-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $existing = DB::table('sales_opportunities')
                ->where('tenant_id', $customer->tenant_id)
                ->where('opportunity_number', $oppNumber)
                ->first();

            if (! $existing) {
                DB::table('sales_opportunities')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $customer->tenant_id,
                    'customer_id' => $customer->id,
                    'opportunity_number' => $oppNumber,
                    'title' => $titles[$index % count($titles)],
                    'stage' => 'proposal',
                    'estimated_amount' => 45000.00 + ($index * 15000.00),
                    'currency' => 'EUR',
                    'probability_percent' => 70.00,
                    'expected_close_date' => now()->addDays(45)->toDateString(),
                    'notes' => 'Multilingual pipeline seed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
