<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class DepreciationMethodSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('erp_lookups')) {
            return;
        }

        $methods = [
            ['code' => 'straight_line', 'it' => 'Quote Costanti (Lineare)', 'en' => 'Straight Line', 'de' => 'Lineare Abschreibung', 'fr' => 'Amortissement linéaire'],
            ['code' => 'declining_balance', 'it' => 'Quote Decrescenti', 'en' => 'Declining Balance', 'de' => 'Degressive Abschreibung', 'fr' => 'Amortissement dégressif'],
            ['code' => 'double_declining_balance', 'it' => 'Doppia Quota Decrescente (DDB)', 'en' => 'Double Declining Balance', 'de' => 'Doppelt-degressive Abschreibung', 'fr' => 'Double amortissement dégressif'],
            ['code' => 'units_of_production', 'it' => 'Unità di Produzione / Ore Lavoro', 'en' => 'Units of Production', 'de' => 'Leistungsabschreibung', 'fr' => 'Unités de production'],
            ['code' => 'custom', 'it' => 'Metodo Personalizzato / Coefficiente Fiscale', 'en' => 'Custom Enterprise Method', 'de' => 'Benutzerdefinierte Methode', 'fr' => 'Méthode personnalisée'],
        ];

        foreach ($methods as $index => $method) {
            $existingId = DB::table('erp_lookups')
                ->where('category', 'eam_depreciation_method')
                ->where('code', $method['code'])
                ->value('id');

            DB::table('erp_lookups')->updateOrInsert(
                ['category' => 'eam_depreciation_method', 'code' => $method['code']],
                [
                    'id' => $existingId ?: (string) Str::uuid7(),
                    'label' => $method['en'],
                    'metadata' => json_encode([
                        'translations' => [
                            'it' => $method['it'],
                            'en' => $method['en'],
                            'de' => $method['de'],
                            'fr' => $method['fr'],
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
