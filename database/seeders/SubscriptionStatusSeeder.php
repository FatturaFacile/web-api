<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionStatusSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $statuses = [
                ['code' => 'draft', 'name_it' => 'Bozza', 'name_en' => 'Draft', 'name_de' => 'Entwurf', 'name_fr' => 'Brouillon'],
                ['code' => 'active', 'name_it' => 'Attivo', 'name_en' => 'Active', 'name_de' => 'Aktiv', 'name_fr' => 'Actif'],
                ['code' => 'paused', 'name_it' => 'Sospeso', 'name_en' => 'Paused', 'name_de' => 'Pausiert', 'name_fr' => 'Suspendu'],
                ['code' => 'past_due', 'name_it' => 'Scaduto non pagato', 'name_en' => 'Past Due', 'name_de' => 'Überfällig', 'name_fr' => 'En souffrance'],
                ['code' => 'cancelled', 'name_it' => 'Cancellato', 'name_en' => 'Cancelled', 'name_de' => 'Gekündigt', 'name_fr' => 'Résilié'],
                ['code' => 'expired', 'name_it' => 'Terminato', 'name_en' => 'Expired', 'name_de' => 'Abgelaufen', 'name_fr' => 'Expiré'],
            ];

            foreach ($statuses as $s) {
                DB::table('sub_products')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'product_code' => 'STATUS-'.strtoupper($s['code'])],
                    [
                        'id' => (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $s['name_it'],
                        'description' => $s['name_en'],
                        'product_type' => 'saas_license',
                        'is_active' => true,
                        'metadata' => json_encode([
                            'status_code' => $s['code'],
                            'translations' => [
                                'it' => $s['name_it'],
                                'en' => $s['name_en'],
                                'de' => $s['name_de'],
                                'fr' => $s['name_fr'],
                            ],
                        ]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
