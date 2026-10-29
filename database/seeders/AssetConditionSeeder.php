<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AssetConditionSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('erp_lookups')) {
            return;
        }

        $conditions = [
            ['code' => 'new', 'it' => 'Nuovo', 'en' => 'New', 'de' => 'Neu', 'fr' => 'Neuf'],
            ['code' => 'excellent', 'it' => 'Eccellente', 'en' => 'Excellent', 'de' => 'Ausgezeichnet', 'fr' => 'Excellent'],
            ['code' => 'good', 'it' => 'Buono', 'en' => 'Good', 'de' => 'Gut', 'fr' => 'Bon'],
            ['code' => 'fair', 'it' => 'Discreto', 'en' => 'Fair', 'de' => 'Ausreichend', 'fr' => 'Passable'],
            ['code' => 'poor', 'it' => 'Scarso', 'en' => 'Poor', 'de' => 'Schlecht', 'fr' => 'Mauvais'],
            ['code' => 'damaged', 'it' => 'Danneggiato', 'en' => 'Damaged', 'de' => 'Beschädigt', 'fr' => 'Endommagé'],
            ['code' => 'decommissioned', 'it' => 'Fuori Uso', 'en' => 'Decommissioned', 'de' => 'Stillgelegt', 'fr' => 'Mis hors service'],
        ];

        foreach ($conditions as $index => $cond) {
            $existingId = DB::table('erp_lookups')
                ->where('category', 'eam_asset_condition')
                ->where('code', $cond['code'])
                ->value('id');

            DB::table('erp_lookups')->updateOrInsert(
                ['category' => 'eam_asset_condition', 'code' => $cond['code']],
                [
                    'id' => $existingId ?: (string) Str::uuid7(),
                    'label' => $cond['en'],
                    'metadata' => json_encode([
                        'translations' => [
                            'it' => $cond['it'],
                            'en' => $cond['en'],
                            'de' => $cond['de'],
                            'fr' => $cond['fr'],
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
