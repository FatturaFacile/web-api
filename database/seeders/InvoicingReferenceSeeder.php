<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class InvoicingReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        if (! $tenantId) {
            return;
        }

        $priceListName = 'Listino Base Italia / Base Italy Price List / Preisliste Italien / Tarif de Base Italie';
        $existing = DB::table('price_lists')
            ->where('tenant_id', $tenantId)
            ->where('name', $priceListName)
            ->first();

        if (! $existing) {
            DB::table('price_lists')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'name' => $priceListName,
                'currency' => 'EUR',
                'is_default' => true,
                'is_active' => true,
                'valid_from' => now()->startOfYear()->toDateString(),
                'valid_to' => now()->endOfYear()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
