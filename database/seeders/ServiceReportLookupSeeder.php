<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

class ServiceReportLookupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'code' => 'service_report_status',
                'name' => 'Service Report Status',
                'description' => 'Lifecycle statuses for intervention service reports',
                'items' => [
                    [
                        'code' => 'draft',
                        'sort_order' => 1,
                        'is_default' => true,
                        'translations' => [
                            'en' => ['label' => 'Draft', 'description' => 'Draft report being prepared'],
                            'it' => ['label' => 'Bozza', 'description' => 'Rapportino in bozza'],
                            'de' => ['label' => 'Entwurf', 'description' => 'Servicebericht im Entwurf'],
                            'fr' => ['label' => 'Brouillon', 'description' => 'Rapport de service en brouillon'],
                        ],
                    ],
                    [
                        'code' => 'in_progress',
                        'sort_order' => 2,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'In Progress', 'description' => 'Intervention currently in progress'],
                            'it' => ['label' => 'In Corso', 'description' => 'Intervento attualmente in corso'],
                            'de' => ['label' => 'In Bearbeitung', 'description' => 'Einsatz in Bearbeitung'],
                            'fr' => ['label' => 'En Cours', 'description' => 'Intervention en cours'],
                        ],
                    ],
                    [
                        'code' => 'completed',
                        'sort_order' => 3,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Completed', 'description' => 'Work finished, pending customer signature'],
                            'it' => ['label' => 'Completato', 'description' => 'Lavoro terminato, in attesa di firma'],
                            'de' => ['label' => 'Abgeschlossen', 'description' => 'Arbeiten beendet, wartet auf Unterschrift'],
                            'fr' => ['label' => 'Terminé', 'description' => 'Travaux achevés, en attente de signature'],
                        ],
                    ],
                    [
                        'code' => 'signed',
                        'sort_order' => 4,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Signed', 'description' => 'Approved and signed by customer'],
                            'it' => ['label' => 'Firmato', 'description' => 'Approvato e firmato dal cliente'],
                            'de' => ['label' => 'Signiert', 'description' => 'Vom Kunden genehmigt und unterschrieben'],
                            'fr' => ['label' => 'Signé', 'description' => 'Approuvé et signé par le client'],
                        ],
                    ],
                    [
                        'code' => 'invoiced',
                        'sort_order' => 5,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Invoiced', 'description' => 'Billed into customer invoice'],
                            'it' => ['label' => 'Fatturato', 'description' => 'Fatturato in fattura cliente'],
                            'de' => ['label' => 'Fakturiert', 'description' => 'In Kundenrechnung übernommen'],
                            'fr' => ['label' => 'Facturé', 'description' => 'Facturé sur une facture client'],
                        ],
                    ],
                    [
                        'code' => 'cancelled',
                        'sort_order' => 6,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Cancelled', 'description' => 'Report voided or cancelled'],
                            'it' => ['label' => 'Annullato', 'description' => 'Rapportino annullato o stornato'],
                            'de' => ['label' => 'Storniert', 'description' => 'Bericht storniert'],
                            'fr' => ['label' => 'Annulé', 'description' => 'Rapport annulé'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'service_report_line_type',
                'name' => 'Service Report Line Type',
                'description' => 'Classification of work lines on an intervention report',
                'items' => [
                    [
                        'code' => 'labor',
                        'sort_order' => 1,
                        'is_default' => true,
                        'translations' => [
                            'en' => ['label' => 'Labor', 'description' => 'Technician labor time'],
                            'it' => ['label' => 'Manodopera', 'description' => 'Ore di manodopera tecnico'],
                            'de' => ['label' => 'Arbeitszeit', 'description' => 'Arbeitsstunden Techniker'],
                            'fr' => ['label' => 'Main d’œuvre', 'description' => 'Heures de travail technicien'],
                        ],
                    ],
                    [
                        'code' => 'material',
                        'sort_order' => 2,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Material', 'description' => 'Parts and materials installed'],
                            'it' => ['label' => 'Materiale', 'description' => 'Ricambi e materiali installati'],
                            'de' => ['label' => 'Material', 'description' => 'Eingebaute Teile und Materialien'],
                            'fr' => ['label' => 'Matériel', 'description' => 'Pièces et matériaux posés'],
                        ],
                    ],
                    [
                        'code' => 'travel',
                        'sort_order' => 3,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Travel', 'description' => 'Travel time and mileage fee'],
                            'it' => ['label' => 'Trasferta / Viaggio', 'description' => 'Diritto fisso di chiamata o chilometraggio'],
                            'de' => ['label' => 'Anfahrt', 'description' => 'Anfahrtskosten und Kilometerpauschale'],
                            'fr' => ['label' => 'Déplacement', 'description' => 'Frais de déplacement et forfait kilométrique'],
                        ],
                    ],
                    [
                        'code' => 'expense',
                        'sort_order' => 4,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Expense', 'description' => 'Out-of-pocket project expenses or permits'],
                            'it' => ['label' => 'Spese / Diritti', 'description' => 'Spese vive, permessi o smaltimento'],
                            'de' => ['label' => 'Auslage', 'description' => 'Auslagen, Gebühren oder Entsorgung'],
                            'fr' => ['label' => 'Dépense', 'description' => 'Débours, permis ou mise au rebut'],
                        ],
                    ],
                    [
                        'code' => 'rental',
                        'sort_order' => 5,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Equipment Rental', 'description' => 'Special tool or machinery rental charge'],
                            'it' => ['label' => 'Noleggio Attrezzatura', 'description' => 'Uso o noleggio attrezzature speciali'],
                            'de' => ['label' => 'Gerätevermietung', 'description' => 'Miete von Spezialwerkzeugen'],
                            'fr' => ['label' => 'Location de matériel', 'description' => 'Location d’outils spéciaux'],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($categories as $catData) {
            /** @var LookupCategory $category */
            $category = LookupCategory::updateOrCreate(
                ['code' => $catData['code']],
                [
                    'name' => $catData['name'],
                    'description' => $catData['description'],
                    'is_system' => true,
                ]
            );

            foreach ($catData['items'] as $itemData) {
                /** @var LookupItem $item */
                $item = LookupItem::updateOrCreate(
                    [
                        'tenant_id' => null,
                        'category_id' => $category->id,
                        'code' => $itemData['code'],
                    ],
                    [
                        'sort_order' => $itemData['sort_order'],
                        'is_active' => true,
                        'is_default' => $itemData['is_default'],
                        'is_system' => true,
                    ]
                );

                foreach ($itemData['translations'] as $locale => $trans) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'lookup_item_id' => $item->id,
                            'locale' => $locale,
                        ],
                        [
                            'label' => $trans['label'],
                            'description' => $trans['description'] ?? null,
                        ]
                    );
                }
            }
        }
    }
}
