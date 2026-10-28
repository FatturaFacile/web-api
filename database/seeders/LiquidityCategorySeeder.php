<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class LiquidityCategorySeeder extends Seeder
{
    public function run(): void
    {
        $category = LookupCategory::updateOrCreate(
            ['code' => 'liquidity_category'],
            [
                'name' => 'Liquidity & Cash Forecast Categories',
                'description' => 'Classification of cash inflows and outflows for treasury forecasting',
                'is_system' => true,
            ]
        );

        $items = [
            [
                'code' => 'OPERATING_INFLOW_SALES',
                'is_default' => true,
                'translations' => [
                    'it' => ['label' => 'Incassi Operativi da Clienti', 'description' => 'Entrate per vendite commerciali e fatture emesse'],
                    'en' => ['label' => 'Operating Inflow - Customer Collections', 'description' => 'Inflows from trade debtors and sales invoices'],
                    'de' => ['label' => 'Operativer Zufluss - Kundenzahlungen', 'description' => 'Mittelzuflüsse aus Forderungen aus Lieferungen und Leistungen'],
                    'fr' => ['label' => 'Flux opérationnel entrant - Créances clients', 'description' => 'Encaissements sur factures clients'],
                ],
            ],
            [
                'code' => 'OPERATING_OUTFLOW_VENDORS',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Pagamenti Operativi a Fornitori', 'description' => 'Uscite per acquisto materie prime e servizi'],
                    'en' => ['label' => 'Operating Outflow - Vendor Payments', 'description' => 'Disbursements for trade creditors and procurement'],
                    'de' => ['label' => 'Operativer Abfluss - Lieferantenzahlungen', 'description' => 'Auszahlungen für Verbindlichkeiten aus Lieferungen'],
                    'fr' => ['label' => 'Flux opérationnel sortant - Fournisseurs', 'description' => 'Décaissements pour fournisseurs et achats'],
                ],
            ],
            [
                'code' => 'PAYROLL_TAXES',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Stipendi, Contributi e F24', 'description' => 'Uscite per retribuzioni personale e oneri fiscali'],
                    'en' => ['label' => 'Payroll, Social Charges & Taxes', 'description' => 'Disbursements for salaries, social security, and statutory taxes'],
                    'de' => ['label' => 'Gehälter, Sozialabgaben und Steuern', 'description' => 'Auszahlungen für Personal und Abgaben'],
                    'fr' => ['label' => 'Salaires, charges sociales et taxes', 'description' => 'Règlements de salaires et prélèvements fiscaux'],
                ],
            ],
            [
                'code' => 'CAPEX_INVESTMENTS',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Investimenti Capex e Cespiti', 'description' => 'Acquisto macchinari, impianti e beni strumentali'],
                    'en' => ['label' => 'Capex & Fixed Asset Investments', 'description' => 'Capital expenditure on machinery and assets'],
                    'de' => ['label' => 'Investitionsausgaben (Capex)', 'description' => 'Investitionen in Sachanlagen'],
                    'fr' => ['label' => 'Dépenses d\'investissement (Capex)', 'description' => 'Investissements corporels et immobilisations'],
                ],
            ],
            [
                'code' => 'FINANCING_DEBT',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Oneri Finanziari e Rimborsi Mutui', 'description' => 'Rimborso quota capitale e interessi passivi'],
                    'en' => ['label' => 'Financing, Debt Service & Interest', 'description' => 'Principal repayments and borrowing interest charges'],
                    'de' => ['label' => 'Finanzierung, Schuldendienst und Zinsen', 'description' => 'Tilgung und Zinsaufwendungen'],
                    'fr' => ['label' => 'Financement et service de la dette', 'description' => 'Remboursement du capital et intérêts emprunts'],
                ],
            ],
            [
                'code' => 'INTERCOMPANY_SWEEP',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Giroconti e Sweeping Infragruppo', 'description' => 'Movimentazioni di accentramento tesoreria e ZBA'],
                    'en' => ['label' => 'Intercompany Sweeping & Pooling', 'description' => 'Zero-balance account cash pooling sweeps'],
                    'de' => ['label' => 'Konzerninternes Cash Pooling (ZBA)', 'description' => 'Konzernweiter Liquiditätsausgleich'],
                    'fr' => ['label' => 'Nivellement et centralisation intragroupe', 'description' => 'Mouvements de nivellement de trésorerie'],
                ],
            ],
        ];

        foreach ($items as $sortOrder => $itemData) {
            $item = LookupItem::updateOrCreate(
                [
                    'tenant_id' => null,
                    'category_id' => $category->id,
                    'code' => $itemData['code'],
                ],
                [
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                    'is_default' => $itemData['is_default'],
                    'is_system' => true,
                ]
            );

            foreach ($itemData['translations'] as $locale => $transData) {
                LookupItemTranslation::updateOrCreate(
                    [
                        'tenant_id' => null,
                        'lookup_item_id' => $item->id,
                        'locale' => $locale,
                    ],
                    [
                        'label' => $transData['label'],
                        'description' => $transData['description'] ?? null,
                    ]
                );
            }
        }
    }
}
