<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AssetStatusSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('erp_lookups')) {
            return;
        }

        $statuses = [
            ['code' => 'draft', 'it' => 'Bozza', 'en' => 'Draft', 'de' => 'Entwurf', 'fr' => 'Brouillon'],
            ['code' => 'registered', 'it' => 'Registrato', 'en' => 'Registered', 'de' => 'Registriert', 'fr' => 'Enregistré'],
            ['code' => 'capitalized', 'it' => 'Capitalizzato', 'en' => 'Capitalized', 'de' => 'Aktiviert', 'fr' => 'Capitalisé'],
            ['code' => 'commissioned', 'it' => 'Collaudato', 'en' => 'Commissioned', 'de' => 'In Betrieb genommen', 'fr' => 'Mis en service'],
            ['code' => 'in_service', 'it' => 'In Esercizio', 'en' => 'In Service', 'de' => 'Im Einsatz', 'fr' => 'En exploitation'],
            ['code' => 'active', 'it' => 'Attivo', 'en' => 'Active', 'de' => 'Aktiv', 'fr' => 'Actif'],
            ['code' => 'under_maintenance', 'it' => 'In Manutenzione', 'en' => 'Under Maintenance', 'de' => 'In Wartung', 'fr' => 'En maintenance'],
            ['code' => 'revalued', 'it' => 'Rivalutato', 'en' => 'Revalued', 'de' => 'Neubewertet', 'fr' => 'Réévalué'],
            ['code' => 'impaired', 'it' => 'Svalutato', 'en' => 'Impaired', 'de' => 'Wertgemindert', 'fr' => 'Déprécié'],
            ['code' => 'retired', 'it' => 'Ritirato', 'en' => 'Retired', 'de' => 'Ausgemustert', 'fr' => 'Retiré'],
            ['code' => 'disposed', 'it' => 'Dismesso', 'en' => 'Disposed', 'de' => 'Entsorgt', 'fr' => 'Cédé'],
            ['code' => 'sold', 'it' => 'Venduto', 'en' => 'Sold', 'de' => 'Verkauft', 'fr' => 'Vendu'],
        ];

        foreach ($statuses as $index => $status) {
            $existingId = DB::table('erp_lookups')
                ->where('category', 'eam_asset_status')
                ->where('code', $status['code'])
                ->value('id');

            DB::table('erp_lookups')->updateOrInsert(
                ['category' => 'eam_asset_status', 'code' => $status['code']],
                [
                    'id' => $existingId ?: (string) Str::uuid7(),
                    'label' => $status['en'],
                    'metadata' => json_encode([
                        'translations' => [
                            'it' => $status['it'],
                            'en' => $status['en'],
                            'de' => $status['de'],
                            'fr' => $status['fr'],
                        ],
                    ]),
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
