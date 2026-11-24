<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CustomerAgreementSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('contacts') || ! Schema::hasTable('customer_agreements')) {
            return;
        }

        $customers = DB::table('contacts')->take(5)->get();

        foreach ($customers as $index => $customer) {
            $agNum = 'AGR-2027-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $existing = DB::table('customer_agreements')
                ->where('tenant_id', $customer->tenant_id)
                ->where('agreement_number', $agNum)
                ->first();

            if (! $existing) {
                DB::table('customer_agreements')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $customer->tenant_id,
                    'customer_id' => $customer->id,
                    'agreement_number' => $agNum,
                    'title' => 'Contratto Quadro di Fornitura Annuale / Master Supply Agreement / Rahmenliefervertrag / Contrat-Cadre de Fourniture',
                    'start_date' => now()->startOfYear()->toDateString(),
                    'end_date' => now()->endOfYear()->toDateString(),
                    'total_committed_amount' => 150000.00,
                    'consumed_amount' => 0.00,
                    'currency' => 'EUR',
                    'status' => 'active',
                    'signed_at' => now()->startOfYear(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
