<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MaintenanceTypeSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('erp_lookups')) {
            return;
        }

        $types = [
            ['code' => 'preventive', 'it' => 'Manutenzione Preventiva Programmata', 'en' => 'Preventive Maintenance', 'de' => 'Vorbeugende Wartung', 'fr' => 'Maintenance préventive'],
            ['code' => 'corrective', 'it' => 'Manutenzione Correttiva / Guasto', 'en' => 'Corrective Maintenance', 'de' => 'Korrektive Wartung', 'fr' => 'Maintenance corrective'],
            ['code' => 'condition_based', 'it' => 'Manutenzione Su Condizione (CBM)', 'en' => 'Condition-Based Maintenance', 'de' => 'Zustandsorientierte Wartung', 'fr' => 'Maintenance conditionnelle'],
            ['code' => 'predictive', 'it' => 'Manutenzione Predittiva IoT', 'en' => 'Predictive Maintenance', 'de' => 'Vorausschauende Wartung', 'fr' => 'Maintenance prédictive'],
            ['code' => 'upgrade', 'it' => 'Miglioria Capitalizzabile (Upgrade)', 'en' => 'Capitalizable Upgrade', 'de' => 'Aktivierbare Erweiterung', 'fr' => 'Amélioration capitalisable'],
        ];

        foreach ($types as $index => $type) {
            $existingId = DB::table('erp_lookups')
                ->where('category', 'eam_maintenance_type')
                ->where('code', $type['code'])
                ->value('id');

            DB::table('erp_lookups')->updateOrInsert(
                ['category' => 'eam_maintenance_type', 'code' => $type['code']],
                [
                    'id' => $existingId ?: (string) Str::uuid7(),
                    'label' => $type['en'],
                    'metadata' => json_encode([
                        'translations' => [
                            'it' => $type['it'],
                            'en' => $type['en'],
                            'de' => $type['de'],
                            'fr' => $type['fr'],
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
