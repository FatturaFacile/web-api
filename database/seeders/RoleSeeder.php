<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;

class RoleSeeder extends GlobalDataSeeder
{
    /**
     * Seed standardized enterprise roles into the global lookup catalog.
     */
    protected function seedGlobalData(): void
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
                'code' => 'warehouse_operator',
                'translations' => [
                    'it' => ['label' => 'Operatore di Magazzino', 'short_label' => 'WhOp', 'description' => 'Prepara, emette e spedisce DDT e trasferimenti'],
                    'en' => ['label' => 'Warehouse Operator', 'short_label' => 'WhOp', 'description' => 'Prepares, issues and ships delivery notes and transfers'],
                    'de' => ['label' => 'Lagermitarbeiter', 'short_label' => 'WhOp', 'description' => 'Bereitet Lieferscheine und Umlagerungen vor und versendet sie'],
                    'fr' => ['label' => 'Opérateur d\'Entrepôt', 'short_label' => 'WhOp', 'description' => 'Prépare, émet et expédie bons de livraison et transferts'],
                ],
            ],
            [
                'code' => 'accountant',
                'translations' => [
                    'it' => ['label' => 'Contabile / Commercialista', 'short_label' => 'Acct', 'description' => 'Fatturazione differita e riconciliazione conto deposito'],
                    'en' => ['label' => 'Accountant', 'short_label' => 'Acct', 'description' => 'Deferred invoicing and consignment reconciliation'],
                    'de' => ['label' => 'Buchhalter', 'short_label' => 'Acct', 'description' => 'Sammelrechnungen und Konsignationsabstimmung'],
                    'fr' => ['label' => 'Comptable', 'short_label' => 'Acct', 'description' => 'Facturation différée et rapprochement des dépôts'],
                ],
            ],
            [
                'code' => 'viewer',
                'translations' => [
                    'it' => ['label' => 'Sola lettura', 'short_label' => 'Viewer', 'description' => 'Consultazione di DDT e logistica'],
                    'en' => ['label' => 'Viewer', 'short_label' => 'Viewer', 'description' => 'Read-only access to delivery notes and logistics'],
                    'de' => ['label' => 'Nur Lesezugriff', 'short_label' => 'Viewer', 'description' => 'Lesezugriff auf Lieferscheine und Logistik'],
                    'fr' => ['label' => 'Lecture seule', 'short_label' => 'Viewer', 'description' => 'Consultation des bons de livraison et de la logistique'],
                ],
            ],
            [
                'code' => 'logistics_manager',
                'translations' => [
                    'it' => ['label' => 'Responsabile Logistica', 'short_label' => 'LogMgr', 'description' => 'DDT, trasferimenti di magazzino, conto deposito e conto lavoro'],
                    'en' => ['label' => 'Logistics Manager', 'short_label' => 'LogMgr', 'description' => 'Delivery notes, stock transfers, consignment and subcontracting'],
                    'de' => ['label' => 'Logistikleiter', 'short_label' => 'LogL', 'description' => 'Lieferscheine, Umlagerungen, Konsignation und Lohnfertigung'],
                    'fr' => ['label' => 'Responsable Logistique', 'short_label' => 'RespLog', 'description' => 'Bons de livraison, transferts, dépôt et sous-traitance'],
                ],
            ],
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
            [
                'code' => 'payroll_manager',
                'translations' => [
                    'it' => ['label' => 'Responsabile Paghe', 'short_label' => 'PayMgr', 'description' => 'Elaborazione paghe svizzere, imposta alla fonte e dichiarazioni salariali (permessi hr.payroll.view, hr.payroll.manage)'],
                    'en' => ['label' => 'Payroll Manager', 'short_label' => 'PayMgr', 'description' => 'Swiss payroll runs, withholding tax and salary declarations (permissions hr.payroll.view, hr.payroll.manage)'],
                    'de' => ['label' => 'Lohnbuchhalter', 'short_label' => 'LohnB', 'description' => 'Schweizer Lohnläufe, Quellensteuer und Lohnmeldungen (Berechtigungen hr.payroll.view, hr.payroll.manage)'],
                    'fr' => ['label' => 'Responsable Salaires', 'short_label' => 'RespSal', 'description' => 'Salaires suisses, impôt à la source et déclarations de salaires (permissions hr.payroll.view, hr.payroll.manage)'],
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
