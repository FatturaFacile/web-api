<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcurementLookupSeeder extends Seeder
{
    /**
     * Run the procurement lookups and reference data seeder.
     */
    public function run(): void
    {
        $this->seedLookupCategoriesAndItems();
        $this->seedTenantSupplierCategories();
    }

    private function seedLookupCategoriesAndItems(): void
    {
        $definitions = [
            [
                'code' => 'procurement_incoterms',
                'name' => 'Incoterms (International Commercial Terms)',
                'description' => 'ICC standard commercial terms for goods transportation and risk allocation',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'EXW',
                        'translations' => [
                            'en' => 'Ex Works',
                            'it' => 'Franco Fabbrica',
                            'de' => 'Ab Werk',
                            'fr' => 'À l\'usine',
                        ],
                        'sort' => 10,
                    ],
                    [
                        'code' => 'FCA',
                        'translations' => [
                            'en' => 'Free Carrier',
                            'it' => 'Franco Vettore',
                            'de' => 'Frei Frachtführer',
                            'fr' => 'Franco transporteur',
                        ],
                        'sort' => 20,
                    ],
                    [
                        'code' => 'CPT',
                        'translations' => [
                            'en' => 'Carriage Paid To',
                            'it' => 'Porto Pagato Fino A',
                            'de' => 'Frachtfrei',
                            'fr' => 'Port payé jusqu\'à',
                        ],
                        'sort' => 30,
                    ],
                    [
                        'code' => 'CIP',
                        'translations' => [
                            'en' => 'Carriage and Insurance Paid To',
                            'it' => 'Porto e Assicurazione Pagati Fino A',
                            'de' => 'Frachtfrei versichert',
                            'fr' => 'Port payé, assurance comprise',
                        ],
                        'sort' => 40,
                    ],
                    [
                        'code' => 'DAP',
                        'translations' => [
                            'en' => 'Delivered at Place',
                            'it' => 'Reso al Luogo di Destinazione',
                            'de' => 'Geliefert benannter Ort',
                            'fr' => 'Rendu au lieu de destination',
                        ],
                        'sort' => 50,
                    ],
                    [
                        'code' => 'DPU',
                        'translations' => [
                            'en' => 'Delivered at Place Unloaded',
                            'it' => 'Reso al Luogo Scaricato',
                            'de' => 'Geliefert benannter Ort entladen',
                            'fr' => 'Rendu au lieu de destination déchargé',
                        ],
                        'sort' => 60,
                    ],
                    [
                        'code' => 'DDP',
                        'translations' => [
                            'en' => 'Delivered Duty Paid',
                            'it' => 'Reso Sdoganato',
                            'de' => 'Geliefert verzollt',
                            'fr' => 'Rendu droits acquittés',
                        ],
                        'sort' => 70,
                    ],
                    [
                        'code' => 'FOB',
                        'translations' => [
                            'en' => 'Free on Board',
                            'it' => 'Franco a Bordo',
                            'de' => 'Frei an Bord',
                            'fr' => 'Franco bord',
                        ],
                        'sort' => 80,
                    ],
                    [
                        'code' => 'CIF',
                        'translations' => [
                            'en' => 'Cost, Insurance and Freight',
                            'it' => 'Costo, Assicurazione e Nolo',
                            'de' => 'Kosten, Versicherung und Fracht',
                            'fr' => 'Coût, assurance et fret',
                        ],
                        'sort' => 90,
                    ],
                ],
            ],
            [
                'code' => 'procurement_order_types',
                'name' => 'Procurement Order Types',
                'description' => 'Classification of purchase orders and commitments',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'standard',
                        'translations' => [
                            'en' => 'Standard Purchase Order',
                            'it' => 'Ordine di Acquisto Standard',
                            'de' => 'Standardbestellung',
                            'fr' => 'Bon de commande standard',
                        ],
                        'sort' => 10,
                    ],
                    [
                        'code' => 'blanket_release',
                        'translations' => [
                            'en' => 'Blanket Order Release',
                            'it' => 'Rilascio Ordine Quadro',
                            'de' => 'Rahmenvertragsabruf',
                            'fr' => 'Appel sur commande ouverte',
                        ],
                        'sort' => 20,
                    ],
                    [
                        'code' => 'subcontract',
                        'translations' => [
                            'en' => 'Subcontract / Service Order',
                            'it' => 'Ordine di Subappalto / Servizio',
                            'de' => 'Unterauftrag / Dienstleistung',
                            'fr' => 'Sous-traitance / Prestation de service',
                        ],
                        'sort' => 30,
                    ],
                ],
            ],
        ];

        foreach ($definitions as $def) {
            $cat = LookupCategory::updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => $def['is_system'],
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
                        'is_default' => $item['code'] === 'DAP' || $item['code'] === 'standard',
                    ]
                );

                foreach (['en', 'it', 'de', 'fr'] as $lang) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'lookup_item_id' => $lookupItem->id,
                            'locale' => $lang,
                        ],
                        [
                            'label' => $item['translations'][$lang] ?? $item['translations']['en'],
                            'description' => $item['translations'][$lang] ?? $item['translations']['en'],
                        ]
                    );
                }
            }
        }
    }

    private function seedTenantSupplierCategories(): void
    {
        $tenants = Tenant::all();

        $categories = [
            ['code' => 'raw_materials', 'name' => 'Raw Materials', 'desc' => 'Metals, plastics, chemicals, and foundational bulk ingredients'],
            ['code' => 'electrical', 'name' => 'Electrical & Electronics', 'desc' => 'Semiconductors, PCBs, wiring, and automation components'],
            ['code' => 'mechanical', 'name' => 'Mechanical Components', 'desc' => 'Bearings, valves, pumps, gears, and structural tooling'],
            ['code' => 'logistics', 'name' => 'Logistics & Freight', 'desc' => 'Couriers, air freight, ocean freight, and warehousing providers'],
            ['code' => 'it_software', 'name' => 'IT Equipment & SaaS', 'desc' => 'Servers, workstations, peripherals, and enterprise software licenses'],
            ['code' => 'professional_services', 'name' => 'Professional Services', 'desc' => 'Legal, auditing, consulting, and engineering services'],
            ['code' => 'facility_maintenance', 'name' => 'Facility & Maintenance', 'desc' => 'HVAC, electrical maintenance, janitorial, and safety supplies'],
            ['code' => 'office_supplies', 'name' => 'Office Supplies', 'desc' => 'Paper, consumables, stationery, and breakroom supplies'],
        ];

        foreach ($tenants as $tenant) {
            foreach ($categories as $cat) {
                $existingId = DB::table('supplier_categories')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $cat['code'])
                    ->value('id') ?? (string) Str::uuid();

                DB::table('supplier_categories')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $cat['code'],
                    ],
                    [
                        'id' => $existingId,
                        'name' => $cat['name'],
                        'description' => $cat['desc'],
                        'is_active' => true,
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
