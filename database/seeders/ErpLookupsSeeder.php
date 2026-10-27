<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ErpLookupsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categoriesData = $this->getCategoriesData();

        LookupCategory::resolveConnection()->transaction(function () use ($categoriesData): void {
            foreach ($categoriesData as $categoryData) {
                /** @var LookupCategory $category */
                $category = LookupCategory::updateOrCreate(
                    ['code' => $categoryData['code']],
                    [
                        'id' => (string) Str::uuid(),
                        'name' => $categoryData['name'],
                        'description' => $categoryData['description'] ?? null,
                        'is_system' => true,
                    ]
                );

                foreach ($categoryData['items'] as $sortOrder => $itemData) {
                    /** @var LookupItem $item */
                    $item = LookupItem::updateOrCreate(
                        [
                            'tenant_id' => null,
                            'category_id' => $category->id,
                            'code' => $itemData['code'],
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'metadata' => $itemData['metadata'] ?? null,
                            'sort_order' => $sortOrder + 1,
                            'is_active' => true,
                            'is_default' => $itemData['is_default'] ?? false,
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
                                'short_label' => $transData['short_label'] ?? null,
                                'description' => $transData['description'] ?? null,
                            ]
                        );
                    }
                }
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCategoriesData(): array
    {
        return [
            [
                'code' => 'unit_of_measure',
                'name' => 'Unità di Misura / Units of Measure',
                'description' => 'Standard ISO and enterprise measurement units',
                'items' => [
                    [
                        'code' => 'NR',
                        'is_default' => true,
                        'translations' => [
                            'it' => ['label' => 'Numero', 'short_label' => 'nr'],
                            'en' => ['label' => 'Number', 'short_label' => 'nr'],
                            'de' => ['label' => 'Anzahl', 'short_label' => 'Stk'],
                            'fr' => ['label' => 'Numéro', 'short_label' => 'n°'],
                        ],
                    ],
                    [
                        'code' => 'KG',
                        'translations' => [
                            'it' => ['label' => 'Chilogrammo', 'short_label' => 'kg'],
                            'en' => ['label' => 'Kilogram', 'short_label' => 'kg'],
                            'de' => ['label' => 'Kilogramm', 'short_label' => 'kg'],
                            'fr' => ['label' => 'Kilogramme', 'short_label' => 'kg'],
                        ],
                    ],
                    [
                        'code' => 'MT',
                        'translations' => [
                            'it' => ['label' => 'Metro', 'short_label' => 'm'],
                            'en' => ['label' => 'Meter', 'short_label' => 'm'],
                            'de' => ['label' => 'Meter', 'short_label' => 'm'],
                            'fr' => ['label' => 'Mètre', 'short_label' => 'm'],
                        ],
                    ],
                    [
                        'code' => 'LT',
                        'translations' => [
                            'it' => ['label' => 'Litro', 'short_label' => 'l'],
                            'en' => ['label' => 'Liter', 'short_label' => 'l'],
                            'de' => ['label' => 'Liter', 'short_label' => 'l'],
                            'fr' => ['label' => 'Litre', 'short_label' => 'l'],
                        ],
                    ],
                    [
                        'code' => 'H',
                        'translations' => [
                            'it' => ['label' => 'Ora', 'short_label' => 'h'],
                            'en' => ['label' => 'Hour', 'short_label' => 'h'],
                            'de' => ['label' => 'Stunde', 'short_label' => 'h'],
                            'fr' => ['label' => 'Heure', 'short_label' => 'h'],
                        ],
                    ],
                    [
                        'code' => 'DAY',
                        'translations' => [
                            'it' => ['label' => 'Giorno', 'short_label' => 'gg'],
                            'en' => ['label' => 'Day', 'short_label' => 'd'],
                            'de' => ['label' => 'Tag', 'short_label' => 'T'],
                            'fr' => ['label' => 'Jour', 'short_label' => 'j'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'party_type',
                'name' => 'Tipologia Soggetto / Party Type',
                'description' => 'CRM and entity business roles',
                'items' => [
                    [
                        'code' => 'CUSTOMER',
                        'is_default' => true,
                        'translations' => [
                            'it' => ['label' => 'Cliente', 'short_label' => 'CLI'],
                            'en' => ['label' => 'Customer', 'short_label' => 'CUST'],
                            'de' => ['label' => 'Kunde', 'short_label' => 'KND'],
                            'fr' => ['label' => 'Client', 'short_label' => 'CLT'],
                        ],
                    ],
                    [
                        'code' => 'SUPPLIER',
                        'translations' => [
                            'it' => ['label' => 'Fornitore', 'short_label' => 'FOR'],
                            'en' => ['label' => 'Supplier', 'short_label' => 'SUPP'],
                            'de' => ['label' => 'Lieferant', 'short_label' => 'LFR'],
                            'fr' => ['label' => 'Fournisseur', 'short_label' => 'FRN'],
                        ],
                    ],
                    [
                        'code' => 'CARRIER',
                        'translations' => [
                            'it' => ['label' => 'Vettore Trasportatore', 'short_label' => 'VET'],
                            'en' => ['label' => 'Carrier', 'short_label' => 'CARR'],
                            'de' => ['label' => 'Spediteur', 'short_label' => 'SPD'],
                            'fr' => ['label' => 'Transporteur', 'short_label' => 'TRP'],
                        ],
                    ],
                    [
                        'code' => 'LEAD',
                        'translations' => [
                            'it' => ['label' => 'Contatto Commerciale', 'short_label' => 'LEAD'],
                            'en' => ['label' => 'Lead', 'short_label' => 'LEAD'],
                            'de' => ['label' => 'Interessent', 'short_label' => 'INT'],
                            'fr' => ['label' => 'Prospect', 'short_label' => 'PRO'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'payment_type',
                'name' => 'Modalità di Pagamento / Payment Type',
                'description' => 'Payment methods and treasury channels',
                'items' => [
                    [
                        'code' => 'BANK_TRANSFER',
                        'is_default' => true,
                        'translations' => [
                            'it' => ['label' => 'Bonifico Bancario', 'short_label' => 'BON'],
                            'en' => ['label' => 'Bank Transfer', 'short_label' => 'TRF'],
                            'de' => ['label' => 'Banküberweisung', 'short_label' => 'UEB'],
                            'fr' => ['label' => 'Virement bancaire', 'short_label' => 'VIR'],
                        ],
                    ],
                    [
                        'code' => 'DIRECT_DEBIT',
                        'translations' => [
                            'it' => ['label' => 'Addebito Diretto SEPA', 'short_label' => 'SDD'],
                            'en' => ['label' => 'SEPA Direct Debit', 'short_label' => 'SDD'],
                            'de' => ['label' => 'SEPA-Lastschrift', 'short_label' => 'SDD'],
                            'fr' => ['label' => 'Prélèvement SEPA', 'short_label' => 'SDD'],
                        ],
                    ],
                    [
                        'code' => 'QR_BILL',
                        'translations' => [
                            'it' => ['label' => 'Polizza di versamento QR', 'short_label' => 'QR'],
                            'en' => ['label' => 'Swiss QR-Bill', 'short_label' => 'QR'],
                            'de' => ['label' => 'Schweizer QR-Rechnung', 'short_label' => 'QR'],
                            'fr' => ['label' => 'QR-facture suisse', 'short_label' => 'QR'],
                        ],
                    ],
                    [
                        'code' => 'CASH',
                        'translations' => [
                            'it' => ['label' => 'Contanti', 'short_label' => 'CONT'],
                            'en' => ['label' => 'Cash', 'short_label' => 'CASH'],
                            'de' => ['label' => 'Barzahlung', 'short_label' => 'BAR'],
                            'fr' => ['label' => 'Espèces', 'short_label' => 'ESP'],
                        ],
                    ],
                    [
                        'code' => 'POS',
                        'translations' => [
                            'it' => ['label' => 'Carta di Credito o POS', 'short_label' => 'POS'],
                            'en' => ['label' => 'Card POS', 'short_label' => 'POS'],
                            'de' => ['label' => 'Kartenzahlung', 'short_label' => 'POS'],
                            'fr' => ['label' => 'Carte de paiement', 'short_label' => 'POS'],
                        ],
                    ],
                    [
                        'code' => 'RIBA',
                        'translations' => [
                            'it' => ['label' => 'Ricevuta Bancaria Ri.Ba.', 'short_label' => 'RIBA'],
                            'en' => ['label' => 'Ri.Ba. Bank Receipt', 'short_label' => 'RIBA'],
                            'de' => ['label' => 'Ri.Ba. Bankeinzug', 'short_label' => 'RIBA'],
                            'fr' => ['label' => 'Effet Ri.Ba.', 'short_label' => 'RIBA'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'payment_terms',
                'name' => 'Termini di Pagamento / Payment Terms',
                'description' => 'Commercial settlement timeframes',
                'items' => [
                    [
                        'code' => 'IMMEDIATE',
                        'is_default' => true,
                        'translations' => [
                            'it' => ['label' => 'Pagamento Immediato', 'short_label' => 'IMM'],
                            'en' => ['label' => 'Immediate Payment', 'short_label' => 'IMM'],
                            'de' => ['label' => 'Sofortige Zahlung', 'short_label' => 'SOF'],
                            'fr' => ['label' => 'Paiement immédiat', 'short_label' => 'IMM'],
                        ],
                    ],
                    [
                        'code' => 'NET_30',
                        'translations' => [
                            'it' => ['label' => '30 Giorni Data Fattura', 'short_label' => '30DF'],
                            'en' => ['label' => 'Net 30 Days', 'short_label' => '30D'],
                            'de' => ['label' => '30 Tage netto', 'short_label' => '30T'],
                            'fr' => ['label' => '30 jours net', 'short_label' => '30J'],
                        ],
                    ],
                    [
                        'code' => 'NET_60',
                        'translations' => [
                            'it' => ['label' => '60 Giorni Data Fattura', 'short_label' => '60DF'],
                            'en' => ['label' => 'Net 60 Days', 'short_label' => '60D'],
                            'de' => ['label' => '60 Tage netto', 'short_label' => '60T'],
                            'fr' => ['label' => '60 jours net', 'short_label' => '60J'],
                        ],
                    ],
                    [
                        'code' => '30_DF_EOM',
                        'translations' => [
                            'it' => ['label' => '30 Giorni Fine Mese', 'short_label' => '30DFM'],
                            'en' => ['label' => '30 Days End of Month', 'short_label' => '30EOM'],
                            'de' => ['label' => '30 Tage Monatsende', 'short_label' => '30ME'],
                            'fr' => ['label' => '30 jours fin de mois', 'short_label' => '30FDM'],
                        ],
                    ],
                    [
                        'code' => '60_DF_EOM',
                        'translations' => [
                            'it' => ['label' => '60 Giorni Fine Mese', 'short_label' => '60DFM'],
                            'en' => ['label' => '60 Days End of Month', 'short_label' => '60EOM'],
                            'de' => ['label' => '60 Tage Monatsende', 'short_label' => '60ME'],
                            'fr' => ['label' => '60 jours fin de mois', 'short_label' => '60FDM'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'attendance_event_type',
                'name' => 'Eventi Presenze / Attendance Events',
                'description' => 'Clock-in and clock-out types',
                'items' => [
                    [
                        'code' => 'CLOCK_IN',
                        'translations' => [
                            'it' => ['label' => 'Entrata', 'short_label' => 'IN'],
                            'en' => ['label' => 'Clock-In', 'short_label' => 'IN'],
                            'de' => ['label' => 'Kommen', 'short_label' => 'EIN'],
                            'fr' => ['label' => 'Entrée', 'short_label' => 'ENT'],
                        ],
                    ],
                    [
                        'code' => 'CLOCK_OUT',
                        'translations' => [
                            'it' => ['label' => 'Uscita', 'short_label' => 'OUT'],
                            'en' => ['label' => 'Clock-Out', 'short_label' => 'OUT'],
                            'de' => ['label' => 'Gehen', 'short_label' => 'AUS'],
                            'fr' => ['label' => 'Sortie', 'short_label' => 'SOR'],
                        ],
                    ],
                    [
                        'code' => 'BREAK_START',
                        'translations' => [
                            'it' => ['label' => 'Inizio Pausa', 'short_label' => 'BRK_IN'],
                            'en' => ['label' => 'Break Start', 'short_label' => 'BRK_IN'],
                            'de' => ['label' => 'Pausenbeginn', 'short_label' => 'P_ANF'],
                            'fr' => ['label' => 'Début de pause', 'short_label' => 'P_DEB'],
                        ],
                    ],
                    [
                        'code' => 'BREAK_END',
                        'translations' => [
                            'it' => ['label' => 'Fine Pausa', 'short_label' => 'BRK_OUT'],
                            'en' => ['label' => 'Break End', 'short_label' => 'BRK_OUT'],
                            'de' => ['label' => 'Pausenende', 'short_label' => 'P_END'],
                            'fr' => ['label' => 'Fin de pause', 'short_label' => 'P_FIN'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'absence_type',
                'name' => 'Tipologie Assenza / Absence Types',
                'description' => 'Leave and absence categories',
                'items' => [
                    [
                        'code' => 'VACATION',
                        'translations' => [
                            'it' => ['label' => 'Ferie', 'short_label' => 'FER'],
                            'en' => ['label' => 'Vacation', 'short_label' => 'VAC'],
                            'de' => ['label' => 'Urlaub', 'short_label' => 'URL'],
                            'fr' => ['label' => 'Vacances', 'short_label' => 'VAC'],
                        ],
                    ],
                    [
                        'code' => 'PAID_LEAVE',
                        'translations' => [
                            'it' => ['label' => 'Permesso Retribuito - ROL', 'short_label' => 'ROL'],
                            'en' => ['label' => 'Paid Leave', 'short_label' => 'LEAVE'],
                            'de' => ['label' => 'Bezahlter Urlaub', 'short_label' => 'BEZ'],
                            'fr' => ['label' => 'Congé payé', 'short_label' => 'CP'],
                        ],
                    ],
                    [
                        'code' => 'SICKNESS',
                        'translations' => [
                            'it' => ['label' => 'Malattia', 'short_label' => 'MAL'],
                            'en' => ['label' => 'Sickness', 'short_label' => 'SICK'],
                            'de' => ['label' => 'Krankheit', 'short_label' => 'KRNK'],
                            'fr' => ['label' => 'Maladie', 'short_label' => 'MAL'],
                        ],
                    ],
                    [
                        'code' => 'ACCIDENT',
                        'translations' => [
                            'it' => ['label' => 'Infortunio', 'short_label' => 'INF'],
                            'en' => ['label' => 'Accident', 'short_label' => 'ACC'],
                            'de' => ['label' => 'Unfall', 'short_label' => 'UNF'],
                            'fr' => ['label' => 'Accident', 'short_label' => 'ACC'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'vehicle_type',
                'name' => 'Tipologie Veicolo / Vehicle Types',
                'description' => 'Automotive and fleet classes',
                'items' => [
                    [
                        'code' => 'CAR',
                        'translations' => [
                            'it' => ['label' => 'Autovettura', 'short_label' => 'AUTO'],
                            'en' => ['label' => 'Passenger Car', 'short_label' => 'CAR'],
                            'de' => ['label' => 'Personenkraftwagen', 'short_label' => 'PKW'],
                            'fr' => ['label' => 'Voiture particulière', 'short_label' => 'VP'],
                        ],
                    ],
                    [
                        'code' => 'VAN',
                        'translations' => [
                            'it' => ['label' => 'Furgone Commerciale', 'short_label' => 'FURG'],
                            'en' => ['label' => 'Van', 'short_label' => 'VAN'],
                            'de' => ['label' => 'Lieferwagen', 'short_label' => 'LFW'],
                            'fr' => ['label' => 'Utilitaire', 'short_label' => 'VU'],
                        ],
                    ],
                    [
                        'code' => 'TRUCK',
                        'translations' => [
                            'it' => ['label' => 'Autocarro', 'short_label' => 'TRK'],
                            'en' => ['label' => 'Truck', 'short_label' => 'TRK'],
                            'de' => ['label' => 'Lastkraftwagen', 'short_label' => 'LKW'],
                            'fr' => ['label' => 'Camion', 'short_label' => 'PL'],
                        ],
                    ],
                    [
                        'code' => 'MOTORCYCLE',
                        'translations' => [
                            'it' => ['label' => 'Motociclo', 'short_label' => 'MOTO'],
                            'en' => ['label' => 'Motorcycle', 'short_label' => 'MOTO'],
                            'de' => ['label' => 'Motorrad', 'short_label' => 'KRAD'],
                            'fr' => ['label' => 'Moto', 'short_label' => 'MOTO'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'tire_season',
                'name' => 'Stagionalità Pneumatici / Tire Season',
                'description' => 'Tire hotel and automotive seasons',
                'items' => [
                    [
                        'code' => 'SUMMER',
                        'translations' => [
                            'it' => ['label' => 'Estivo', 'short_label' => 'EST'],
                            'en' => ['label' => 'Summer', 'short_label' => 'SUM'],
                            'de' => ['label' => 'Sommer', 'short_label' => 'SOM'],
                            'fr' => ['label' => 'Été', 'short_label' => 'ETE'],
                        ],
                    ],
                    [
                        'code' => 'WINTER',
                        'translations' => [
                            'it' => ['label' => 'Invernale', 'short_label' => 'INV'],
                            'en' => ['label' => 'Winter', 'short_label' => 'WIN'],
                            'de' => ['label' => 'Winter', 'short_label' => 'WIN'],
                            'fr' => ['label' => 'Hiver', 'short_label' => 'HIV'],
                        ],
                    ],
                    [
                        'code' => 'ALL_SEASON',
                        'translations' => [
                            'it' => ['label' => 'Quattro Stagioni', 'short_label' => '4S'],
                            'en' => ['label' => 'All Season', 'short_label' => '4S'],
                            'de' => ['label' => 'Ganzjahresreifen', 'short_label' => 'GJ'],
                            'fr' => ['label' => 'Toutes saisons', 'short_label' => '4S'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'stock_movement_reason',
                'name' => 'Causali Movimento Magazzino / Stock Movement Reasons',
                'description' => 'Warehouse movements and inventory adjustments',
                'items' => [
                    [
                        'code' => 'PURCHASE_RECEIPT',
                        'translations' => [
                            'it' => ['label' => 'Carico da Acquisto Fornitore', 'short_label' => 'CAR_ACQ'],
                            'en' => ['label' => 'Purchase Receipt', 'short_label' => 'PUR_REC'],
                            'de' => ['label' => 'Wareneingang Einkauf', 'short_label' => 'WE_EK'],
                            'fr' => ['label' => 'Réception achat', 'short_label' => 'REC_ACH'],
                        ],
                    ],
                    [
                        'code' => 'SALE_SHIPMENT',
                        'translations' => [
                            'it' => ['label' => 'Scarico Vendita Cliente', 'short_label' => 'SCA_VEN'],
                            'en' => ['label' => 'Sale Shipment', 'short_label' => 'SAL_SHP'],
                            'de' => ['label' => 'Warenausgang Verkauf', 'short_label' => 'WA_VK'],
                            'fr' => ['label' => 'Expédition vente', 'short_label' => 'EXP_VNT'],
                        ],
                    ],
                    [
                        'code' => 'INVENTORY_ADJ',
                        'translations' => [
                            'it' => ['label' => 'Rettifica Inventariale', 'short_label' => 'RET_INV'],
                            'en' => ['label' => 'Inventory Adjustment', 'short_label' => 'INV_ADJ'],
                            'de' => ['label' => 'Inventurkorrektur', 'short_label' => 'INV_KORR'],
                            'fr' => ['label' => "Ajustement d'inventaire", 'short_label' => 'AJU_INV'],
                        ],
                    ],
                    [
                        'code' => 'SCRAP',
                        'translations' => [
                            'it' => ['label' => 'Scarico per Rottamazione', 'short_label' => 'ROT'],
                            'en' => ['label' => 'Scrap', 'short_label' => 'SCRAP'],
                            'de' => ['label' => 'Verschrottung', 'short_label' => 'SCHR'],
                            'fr' => ['label' => 'Mise au rebut', 'short_label' => 'REBUT'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'ddt_reason',
                'name' => 'Causali di Trasporto DDT / Delivery Note Reasons',
                'description' => 'Transport reasons for legal delivery documents',
                'items' => [
                    [
                        'code' => 'SALE',
                        'is_default' => true,
                        'translations' => [
                            'it' => ['label' => 'Vendita', 'short_label' => 'VEN'],
                            'en' => ['label' => 'Sale', 'short_label' => 'SALE'],
                            'de' => ['label' => 'Verkauf', 'short_label' => 'VK'],
                            'fr' => ['label' => 'Vente', 'short_label' => 'VNT'],
                        ],
                    ],
                    [
                        'code' => 'REPAIR',
                        'translations' => [
                            'it' => ['label' => 'Riparazione o Assistenza', 'short_label' => 'RIP'],
                            'en' => ['label' => 'Repair', 'short_label' => 'REP'],
                            'de' => ['label' => 'Reparatur', 'short_label' => 'REP'],
                            'fr' => ['label' => 'Réparation', 'short_label' => 'REP'],
                        ],
                    ],
                    [
                        'code' => 'LOAN_USE',
                        'translations' => [
                            'it' => ['label' => "Conto Visione o Prestito d'uso", 'short_label' => 'VIS'],
                            'en' => ['label' => 'Demonstration or Loan', 'short_label' => 'LOAN'],
                            'de' => ['label' => 'Leihe', 'short_label' => 'LEIH'],
                            'fr' => ['label' => 'Prêt à usage', 'short_label' => 'PRET'],
                        ],
                    ],
                    [
                        'code' => 'PROCESSING',
                        'translations' => [
                            'it' => ['label' => 'Lavorazione presso Terzi', 'short_label' => 'LAV_TER'],
                            'en' => ['label' => 'External Processing', 'short_label' => 'EXT_PRC'],
                            'de' => ['label' => 'Lohnbearbeitung', 'short_label' => 'LOHN'],
                            'fr' => ['label' => 'Sous-traitance', 'short_label' => 'STR'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
