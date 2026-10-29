<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed standardized enterprise roles into the global lookup catalog.
     */
    public function run(): void
    {
        $category = LookupCategory::updateOrCreate(
            ['code' => 'enterprise_role'],
            [
                'name' => 'Ruoli Aziendali / Enterprise RBAC Roles',
                'description' => 'System-wide standardized enterprise role definitions',
                'is_system' => true,
            ]
        );

        $roles = [
            [
                'code' => 'admin',
                'translations' => [
                    'it' => ['label' => 'Amministratore di Sistema', 'short_label' => 'Admin', 'description' => 'Accesso totale alle configurazioni e a tutti i moduli'],
                    'en' => ['label' => 'System Administrator', 'short_label' => 'Admin', 'description' => 'Full administrative access across all modules'],
                    'de' => ['label' => 'Systemadministrator', 'short_label' => 'Admin', 'description' => 'Vollzugriff auf alle Unternehmensmodule'],
                    'fr' => ['label' => 'Administrateur Système', 'short_label' => 'Admin', 'description' => 'Accès complet à tous les modules'],
                ],
            ],
            [
                'code' => 'finance_director',
                'translations' => [
                    'it' => ['label' => 'Direttore Finanziario (CFO)', 'short_label' => 'CFO', 'description' => 'Controllo contabilità generale, tesoreria e bilancio'],
                    'en' => ['label' => 'Chief Financial Officer (CFO)', 'short_label' => 'CFO', 'description' => 'General ledger, treasury, banking and financial statements'],
                    'de' => ['label' => 'Finanzvorstand (CFO)', 'short_label' => 'CFO', 'description' => 'Hauptbuch, Treasury, Zahlungsverkehr und Jahresabschluss'],
                    'fr' => ['label' => 'Directeur Financier (CFO)', 'short_label' => 'CFO', 'description' => 'Grand livre, trésorerie et états financiers'],
                ],
            ],
            [
                'code' => 'sales_manager',
                'translations' => [
                    'it' => ['label' => 'Responsabile Vendite', 'short_label' => 'SalesMgr', 'description' => 'Gestione pipeline CRM, preventivi, ordini e contratti'],
                    'en' => ['label' => 'Sales Director / Manager', 'short_label' => 'SalesMgr', 'description' => 'CRM pipeline, quotations, sales orders and contracts'],
                    'de' => ['label' => 'Vertriebsleiter', 'short_label' => 'VertrL', 'description' => 'CRM-Pipeline, Angebote, Kundenaufträge und Verträge'],
                    'fr' => ['label' => 'Directeur Commercial', 'short_label' => 'DirCom', 'description' => 'Pipeline CRM, devis, commandes et contrats'],
                ],
            ],
            [
                'code' => 'manufacturing_director',
                'translations' => [
                    'it' => ['label' => 'Direttore di Produzione', 'short_label' => 'ProdDir', 'description' => 'Pianificazione MRP, distinte base, cicli e ordini di produzione'],
                    'en' => ['label' => 'Manufacturing Operations Director', 'short_label' => 'ProdDir', 'description' => 'MRP planning, BOMs, routings and work order executions'],
                    'de' => ['label' => 'Produktionsleiter', 'short_label' => 'ProdL', 'description' => 'MRP-Planung, Stücklisten, Arbeitspläne und Fertigungsaufträge'],
                    'fr' => ['label' => 'Directeur de Production', 'short_label' => 'DirProd', 'description' => 'Planification MRP, nomenclatures, gammes et fabrication'],
                ],
            ],
            [
                'code' => 'service_manager',
                'translations' => [
                    'it' => ['label' => 'Responsabile Assistenza Clienti', 'short_label' => 'ServMgr', 'description' => 'Gestione ticket, SLA, interventi sul campo e CSAT'],
                    'en' => ['label' => 'Customer Service Manager', 'short_label' => 'ServMgr', 'description' => 'Service desk, SLAs, field service work orders and CSAT'],
                    'de' => ['label' => 'Kundendienstleiter', 'short_label' => 'KundL', 'description' => 'Service-Desk, SLAs, Außendiensteinsätze und Kundenzufriedenheit'],
                    'fr' => ['label' => 'Responsable Service Client', 'short_label' => 'RespServ', 'description' => 'Support, SLA, interventions sur site et satisfaction client'],
                ],
            ],
        ];

        foreach ($roles as $idx => $role) {
            $item = LookupItem::updateOrCreate(
                [
                    'tenant_id' => null,
                    'category_id' => $category->id,
                    'code' => $role['code'],
                ],
                [
                    'sort_order' => $idx + 1,
                    'is_active' => true,
                    'is_system' => true,
                ]
            );

            foreach ($role['translations'] as $locale => $trans) {
                LookupItemTranslation::updateOrCreate(
                    [
                        'tenant_id' => null,
                        'lookup_item_id' => $item->id,
                        'locale' => $locale,
                    ],
                    [
                        'label' => $trans['label'],
                        'short_label' => $trans['short_label'],
                        'description' => $trans['description'],
                    ]
                );
            }
        }
    }
}
