<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProjectLookupSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'project_type',
                'name' => 'Project Industry & Archetype Types',
                'description' => 'Classification of projects across business verticals',
                'items' => [
                    ['code' => 'internal', 'sort' => 10, 'translations' => ['en' => 'Internal / R&D', 'it' => 'Interno / R&S', 'de' => 'Intern / F&E', 'fr' => 'Interne / R&D']],
                    ['code' => 'customer', 'sort' => 20, 'translations' => ['en' => 'Customer Direct', 'it' => 'Cliente Diretto', 'de' => 'Kundenprojekt', 'fr' => 'Projet Client']],
                    ['code' => 'construction', 'sort' => 30, 'translations' => ['en' => 'Construction & Engineering', 'it' => 'Costruzioni & Ingegneria', 'de' => 'Bau & Ingenieurwesen', 'fr' => 'BTP & Ingénierie']],
                    ['code' => 'consulting', 'sort' => 40, 'translations' => ['en' => 'Professional Services / Consulting', 'it' => 'Servizi Professionali / Consulenza', 'de' => 'Beratung & Dienstleistungen', 'fr' => 'Conseil & Services']],
                    ['code' => 'software_dev', 'sort' => 50, 'translations' => ['en' => 'Software & IT Implementation', 'it' => 'Sviluppo Software & IT', 'de' => 'Software & IT-Implementierung', 'fr' => 'Logiciel & IT']],
                    ['code' => 'maintenance', 'sort' => 60, 'translations' => ['en' => 'Service & Maintenance', 'it' => 'Assistenza & Manutenzione', 'de' => 'Wartung & Instandhaltung', 'fr' => 'Maintenance & SAV']],
                    ['code' => 'capex', 'sort' => 70, 'translations' => ['en' => 'Capital Expenditure (CapEx)', 'it' => 'Investimento di Capitale (CapEx)', 'de' => 'Investitionsprojekt (CapEx)', 'fr' => 'Investissement (CapEx)']],
                ],
            ],
            [
                'code' => 'project_billing_method',
                'name' => 'Project Commercial Billing Models',
                'description' => 'Contractual billing terms and revenue recognition methodology',
                'items' => [
                    ['code' => 'fixed_price', 'sort' => 10, 'translations' => ['en' => 'Fixed Price / Lump Sum', 'it' => 'Prezzo Fisso / A Corpo', 'de' => 'Festpreis', 'fr' => 'Prix Forfaitaire']],
                    ['code' => 'time_and_materials', 'sort' => 20, 'translations' => ['en' => 'Time & Materials (T&M)', 'it' => 'Tempo e Materiali (A Consuntivo)', 'de' => 'Nach Aufwand (T&M)', 'fr' => 'Régie (Temps & Matériaux)']],
                    ['code' => 'cost_plus', 'sort' => 30, 'translations' => ['en' => 'Cost Plus Margin', 'it' => 'Costo Maggiorato (Cost Plus)', 'de' => 'Kostenaufschlagsmethode', 'fr' => 'Coût Majoré']],
                    ['code' => 'milestone_based', 'sort' => 40, 'translations' => ['en' => 'Milestone Progress Billing', 'it' => 'Fatturazione a Milestone', 'de' => 'Meilensteinabrechnung', 'fr' => 'Facturation par Jalon']],
                    ['code' => 'internal', 'sort' => 50, 'translations' => ['en' => 'Non-Billable / Cost Center Absorption', 'it' => 'Non Fatturabile / Ribaltamento Centri', 'de' => 'Nicht abrechenbar', 'fr' => 'Non Facturable']],
                ],
            ],
            [
                'code' => 'project_task_priority',
                'name' => 'Task Priority Levels',
                'description' => 'Urgency and critical-path weighting for project tasks',
                'items' => [
                    ['code' => 'low', 'sort' => 10, 'translations' => ['en' => 'Low', 'it' => 'Bassa', 'de' => 'Niedrig', 'fr' => 'Basse']],
                    ['code' => 'medium', 'sort' => 20, 'translations' => ['en' => 'Medium', 'it' => 'Media', 'de' => 'Mittel', 'fr' => 'Moyenne']],
                    ['code' => 'high', 'sort' => 30, 'translations' => ['en' => 'High', 'it' => 'Alta', 'de' => 'Hoch', 'fr' => 'Haute']],
                    ['code' => 'critical', 'sort' => 40, 'translations' => ['en' => 'Critical Path', 'it' => 'Percorso Critico', 'de' => 'Kritischer Pfad', 'fr' => 'Chemin Critique']],
                ],
            ],
            [
                'code' => 'project_cost_category',
                'name' => 'Job Costing Cost Categories',
                'description' => 'Primary cost breakdown dimensions for project accounting',
                'items' => [
                    ['code' => 'labour', 'sort' => 10, 'translations' => ['en' => 'Direct Labour & Burdens', 'it' => 'Manodopera Diretta & Oneri', 'de' => 'Direkte Arbeitskosten', 'fr' => 'Main-d\'œuvre Directe']],
                    ['code' => 'material', 'sort' => 20, 'translations' => ['en' => 'Direct Materials & Consumables', 'it' => 'Materiali Diretti & Consumabili', 'de' => 'Material & Rohstoffe', 'fr' => 'Matériaux & Consommables']],
                    ['code' => 'supplier', 'sort' => 30, 'translations' => ['en' => 'Subcontractors & Suppliers', 'it' => 'Subappalti & Terzisti', 'de' => 'Fremdleistungen & Subunternehmer', 'fr' => 'Sous-traitance & Fournisseurs']],
                    ['code' => 'fleet', 'sort' => 40, 'translations' => ['en' => 'Fleet & Heavy Machinery', 'it' => 'Automezzi & Macchinari', 'de' => 'Fuhrpark & Maschinen', 'fr' => 'Flotte & Engins']],
                    ['code' => 'expense', 'sort' => 50, 'translations' => ['en' => 'Direct Expenses & Travel', 'it' => 'Spese Dirette & Trasferte', 'de' => 'Reisekosten & Spesen', 'fr' => 'Frais & Déplacements']],
                    ['code' => 'contingency', 'sort' => 60, 'translations' => ['en' => 'Contingency & Reserve', 'it' => 'Fondo Imprevisti / Riserva', 'de' => 'Rückstellung / Unvorhergesehenes', 'fr' => 'Provision pour Imprévus']],
                ],
            ],
        ];

        foreach ($definitions as $def) {
            $cat = LookupCategory::updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => true,
                ]
            );

            foreach ($def['items'] as $item) {
                $lookupItem = LookupItem::updateOrCreate(
                    [
                        'category_id' => $cat->id,
                        'code' => $item['code'],
                    ],
                    [
                        'sort_order' => $item['sort'],
                        'is_active' => true,
                        'is_default' => $item['code'] === 'medium' || $item['code'] === 'customer',
                    ]
                );

                foreach (['en', 'it', 'de', 'fr'] as $lang) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'lookup_item_id' => $lookupItem->id,
                            'locale' => $lang,
                        ],
                        [
                            'label' => $item['translations'][$lang],
                            'description' => $item['translations'][$lang],
                        ]
                    );
                }
            }
        }
    }
}
