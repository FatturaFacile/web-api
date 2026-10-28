<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcurementReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $criteria = [
            [
                'code' => 'OTIF',
                'names' => [
                    'en' => 'On-Time In-Full Delivery Rate',
                    'it' => 'Tasso di Consegna Puntuale e Completa',
                    'de' => 'Pünktliche und Vollständige Lieferquote',
                    'fr' => 'Taux de livraison à temps et complète',
                ],
                'weight' => 35,
            ],
            [
                'code' => 'QUALITY_PPM',
                'names' => [
                    'en' => 'Quality & Defect Rate (PPM)',
                    'it' => 'Qualità e Tasso di Difettosità (PPM)',
                    'de' => 'Qualität & Fehlerrate (PPM)',
                    'fr' => 'Qualité et taux de non-conformité (PPM)',
                ],
                'weight' => 30,
            ],
            [
                'code' => 'PRICE_COMPLIANCE',
                'names' => [
                    'en' => 'Price & Invoicing Accuracy',
                    'it' => 'Accuratezza Prezzi e Fatturazione',
                    'de' => 'Preis- und Abrechnungsgenauigkeit',
                    'fr' => 'Exactitude des prix et facturation',
                ],
                'weight' => 20,
            ],
            [
                'code' => 'ESG_SUSTAINABILITY',
                'names' => [
                    'en' => 'ESG Compliance & Sustainability',
                    'it' => 'Conformità ESG e Sostenibilità',
                    'de' => 'ESG-Konformität & Nachhaltigkeit',
                    'fr' => 'Conformité ESG et durabilité',
                ],
                'weight' => 15,
            ],
        ];

        foreach ($criteria as $crit) {
            foreach (['en', 'it', 'de', 'fr'] as $lang) {
                DB::table('system_lookups')->updateOrInsert(
                    [
                        'type' => 'supplier_evaluation_criterion',
                        'code' => $crit['code'].'_'.$lang,
                    ],
                    [
                        'id' => (string) Str::uuid7(),
                        'name' => $crit['names'][$lang],
                        'description' => "Weight: {$crit['weight']}% - Locale: {$lang}",
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
