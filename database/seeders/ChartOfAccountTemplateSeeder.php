<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\GeneralLedger\Models\ChartAccountTemplate;
use Modules\GeneralLedger\Services\ChartTemplates\ChartTemplateCatalog;
use Modules\GeneralLedger\Services\ChartTemplates\FrenchPcgData;
use Modules\GeneralLedger\Services\ChartTemplates\Skr03Data;
use Modules\GeneralLedger\Services\ChartTemplates\Skr04Data;
use Modules\GeneralLedger\Services\ChartTemplates\SpanishPgcData;

/**
 * Mod 011 — Single source of truth for multi-standard Chart of Accounts templates,
 * localized UI copy, Swiss KMU/Käfer (classes 1-9) & Italian Civil Code/OIC (Artt. 2424-2425)
 * hierarchies, and default bidirectional cross-standard account mappings.
 */
final class ChartOfAccountTemplateSeeder extends Seeder
{
    /**
     * Localized UI copy served from the API seeders (no CoA domain copy duplicated in frontend i18n files).
     *
     * @return array<string, array{it: string, en: string, de: string, fr: string}>
     */
    public static function uiCopy(): array
    {
        return [
            'page_title' => [
                'it' => 'Piano dei Conti Multi-Standard',
                'en' => 'Multi-Standard Chart of Accounts',
                'de' => 'Multi-Standard-Kontenplan',
                'fr' => 'Plan comptable multi-normes',
            ],
            'page_subtitle' => [
                'it' => 'Modelli Svizzero KMU/Käfer, Italiano Codice Civile/OIC e Alberi di Mappatura Cross-Standard',
                'en' => 'Swiss KMU/Käfer, Italian Civil Code/OIC Templates and Cross-Standard Mapping Trees',
                'de' => 'Schweizer KMU/Käfer, Italienisches Zivilgesetzbuch/OIC und Cross-Standard-Zuordnungsbäume',
                'fr' => 'Modèles Suisse PME/Käfer, Code civil italien/OIC et arbres de correspondance multi-normes',
            ],
            'tab_tree' => [
                'it' => 'Albero Gerarchico',
                'en' => 'Hierarchy Tree',
                'de' => 'Kontenhierarchie',
                'fr' => 'Arborescence',
            ],
            'tab_table' => [
                'it' => 'Elenco Analitico',
                'en' => 'Analytical List',
                'de' => 'Kontenliste',
                'fr' => 'Liste analytique',
            ],
            'tab_mappings' => [
                'it' => 'Matrice & Albero di Mappatura',
                'en' => 'Mapping Matrix & Tree',
                'de' => 'Zuordnungsmatrix & -baum',
                'fr' => 'Matrice & arbre de correspondance',
            ],
            'provision_title' => [
                'it' => 'Inizializzazione Rapida Modello (1-Click)',
                'en' => '1-Click Template Provisioning',
                'de' => '1-Klick-Vorlageninitialisierung',
                'fr' => 'Initialisation rapide du modèle (1 clic)',
            ],
            'provision_button' => [
                'it' => 'Inizializza Piano dei Conti',
                'en' => 'Provision Chart of Accounts',
                'de' => 'Kontenrahmen initialisieren',
                'fr' => 'Initialiser le plan comptable',
            ],
            'include_mappings_label' => [
                'it' => 'Includi mappature predefinite KMU <-> OIC',
                'en' => 'Include default KMU <-> OIC mappings',
                'de' => 'Standardzuordnungen KMU <-> OIC einbeziehen',
                'fr' => 'Inclure les correspondances par défaut PME <-> OIC',
            ],
            'export_xlsx' => [
                'it' => 'Esporta Excel (.xlsx)',
                'en' => 'Export Excel (.xlsx)',
                'de' => 'Excel exportieren (.xlsx)',
                'fr' => 'Exporter Excel (.xlsx)',
            ],
            'export_csv' => [
                'it' => 'Esporta CSV',
                'en' => 'Export CSV',
                'de' => 'CSV exportieren',
                'fr' => 'Exporter CSV',
            ],
            'export_xml' => [
                'it' => 'Esporta XML Audit',
                'en' => 'Export Audit XML',
                'de' => 'Audit-XML exportieren',
                'fr' => 'Exporter XML d\'audit',
            ],
            'node_grouping' => [
                'it' => 'Nodo Strutturale / Mastro',
                'en' => 'Structural / Grouping Node',
                'de' => 'Struktur- / Sammelkonto',
                'fr' => 'Nœud structurel / regroupement',
            ],
            'node_postable' => [
                'it' => 'Conto Operativo (Movimentabile)',
                'en' => 'Operational Account (Postable)',
                'de' => 'Bebuchbares Konto',
                'fr' => 'Compte opérationnel (imputable)',
            ],
            'open_items_label' => [
                'it' => 'Gestione Partite Aperte',
                'en' => 'Open Item Management',
                'de' => 'Offene-Posten-Verwaltung',
                'fr' => 'Gestion des postes ouverts',
            ],
            'default_vat_label' => [
                'it' => 'Codice IVA Predefinito',
                'en' => 'Default VAT Code',
                'de' => 'Standard-MWST-Code',
                'fr' => 'Code TVA par défaut',
            ],
            'default_cost_center_label' => [
                'it' => 'Centro di Costo Predefinito',
                'en' => 'Default Cost Center',
                'de' => 'Standard-Kostenstelle',
                'fr' => 'Centre de coûts par défaut',
            ],
            'allowed_currencies_label' => [
                'it' => 'Valute Consentite',
                'en' => 'Allowed Currencies',
                'de' => 'Zulässige Währungen',
                'fr' => 'Devises autorisées',
            ],
            'statutory_code_label' => [
                'it' => 'Riferimento Normativo / Codice',
                'en' => 'Statutory Reference',
                'de' => 'Gesetzliche Referenz',
                'fr' => 'Référence légale',
            ],
            'balance_debit' => [
                'it' => 'Dare (Debit)',
                'en' => 'Debit (Dare)',
                'de' => 'Soll (Debit)',
                'fr' => 'Débit',
            ],
            'balance_credit' => [
                'it' => 'Avere (Credit)',
                'en' => 'Credit (Avere)',
                'de' => 'Haben (Credit)',
                'fr' => 'Crédit',
            ],
            'coherence_warning' => [
                'it' => 'Attenzione: il saldo normale selezionato diverge dalla natura contabile standard del conto.',
                'en' => 'Warning: the selected normal balance differs from the standard nature of this account type.',
                'de' => 'Warnung: Der gewählte normale Saldo weicht von der Standardnatur dieses Kontotyps ab.',
                'fr' => 'Attention : le solde normal sélectionné diffère de la nature standard de ce type de compte.',
            ],
            'postings_locked_notice' => [
                'it' => 'Conto con scritture contabili: tipo, saldo normale e codice sono protetti; è possibile rinominarlo o disattivarlo senza alterare lo storico.',
                'en' => 'Account carries journal postings: type, normal balance, and code are locked; renaming or deactivating preserves historical integrity.',
                'de' => 'Konto enthält Buchungen: Typ, Saldo und Code sind gesperrt; Umbenennen oder Deaktivieren erhält die Historie.',
                'fr' => 'Le compte porte des écritures : le type, le solde normal et le code sont verrouillés ; le renommage ou la désactivation préserve l\'historique.',
            ],
            'mapping_bidirectional' => [
                'it' => 'Bidirezionale',
                'en' => 'Bidirectional',
                'de' => 'Bidirektional',
                'fr' => 'Bidirectionnel',
            ],
            'mapping_seed_defaults' => [
                'it' => 'Genera Mappature Predefinite KMU <-> OIC',
                'en' => 'Seed Default KMU <-> OIC Mappings',
                'de' => 'Standardzuordnungen KMU <-> OIC erzeugen',
                'fr' => 'Générer les correspondances PME <-> OIC',
            ],
            'mapping_add' => [
                'it' => 'Nuova Mappatura',
                'en' => 'New Mapping',
                'de' => 'Neue Zuordnung',
                'fr' => 'Nouvelle correspondance',
            ],
        ];
    }

    public static function localizedUiCopy(string $locale): array
    {
        $lang = in_array($locale, ['it', 'en', 'de', 'fr'], true) ? $locale : 'it';
        $result = [];

        foreach (self::uiCopy() as $key => $translations) {
            $result[$key] = $translations[$lang];
        }

        return $result;
    }

    public function run(): void
    {
        foreach (self::definitions() as $key => $template) {
            ChartAccountTemplate::updateOrCreateGlobal(
                ['key' => $key],
                [
                    'standard' => $template['standard'],
                    'country' => $template['country'],
                    'currency' => $template['currency'],
                    'texts' => $template['texts'],
                    'nodes' => $template['nodes'],
                    'default_mappings' => $template['default_mappings'] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return array<string, array{
     *     standard: string,
     *     country: string,
     *     currency: string,
     *     texts: array<string, mixed>,
     *     nodes: list<array<string, mixed>>,
     *     default_mappings?: list<array<string, mixed>>
     * }>
     */
    public static function definitions(): array
    {
        return [
            ChartTemplateCatalog::SWISS_SME => [
                'standard' => ChartTemplateCatalog::SWISS_SME,
                'country' => 'CH',
                'currency' => 'CHF',
                'texts' => [
                    'it' => [
                        'name' => 'Piano dei Conti Svizzero KMU / Käfer (Sterchi)',
                        'subtitle' => 'Struttura ufficiale PMI Svizzera (Classi 1–9, CO Art. 957–963b)',
                        'statutory_reference' => 'CO Art. 957–963b / Kontenrahmen KMU',
                    ],
                    'en' => [
                        'name' => 'Swiss SME Chart of Accounts (KMU / Käfer)',
                        'subtitle' => 'Official Swiss SME structure (Classes 1–9, Swiss CO Art. 957–963b)',
                        'statutory_reference' => 'Swiss CO Art. 957–963b / Kontenrahmen KMU',
                    ],
                    'de' => [
                        'name' => 'Schweizer Kontenrahmen KMU / Käfer (Sterchi)',
                        'subtitle' => 'Offizielle KMU-Struktur (Klassen 1–9, OR Art. 957–963b)',
                        'statutory_reference' => 'OR Art. 957–963b / Kontenrahmen KMU',
                    ],
                    'fr' => [
                        'name' => 'Plan comptable suisse PME / Käfer (Sterchi)',
                        'subtitle' => 'Structure officielle PME suisse (Classes 1–9, CO art. 957–963b)',
                        'statutory_reference' => 'CO art. 957–963b / Plan comptable PME',
                    ],
                ],
                'nodes' => self::swissKmuNodes(),
                'default_mappings' => self::swissToItalianDefaultMappings(),
            ],
            ChartTemplateCatalog::ITALIAN_GENERAL => [
                'standard' => ChartTemplateCatalog::ITALIAN_GENERAL,
                'country' => 'IT',
                'currency' => 'EUR',
                'texts' => [
                    'it' => [
                        'name' => 'Piano dei Conti Italiano — Codice Civile / OIC (Artt. 2424–2425)',
                        'subtitle' => 'Stato Patrimoniale (Attivo/Passivo) e Conto Economico secondo schema CEE / OIC',
                        'statutory_reference' => 'Codice Civile Artt. 2424–2425 / Principi OIC',
                    ],
                    'en' => [
                        'name' => 'Italian Chart of Accounts — Civil Code / OIC (Arts. 2424–2425)',
                        'subtitle' => 'Statutory Balance Sheet and Income Statement per Italian Civil Code & OIC',
                        'statutory_reference' => 'Italian Civil Code Arts. 2424–2425 / OIC',
                    ],
                    'de' => [
                        'name' => 'Italienischer Kontenrahmen — Zivilgesetzbuch / OIC (Art. 2424–2425)',
                        'subtitle' => 'Bilanz und Erfolgsrechnung nach italienischem Codice Civile & OIC',
                        'statutory_reference' => 'Codice Civile Art. 2424–2425 / OIC',
                    ],
                    'fr' => [
                        'name' => 'Plan comptable italien — Code civil / OIC (Art. 2424–2425)',
                        'subtitle' => 'Bilan et compte de résultat selon le Code civil italien & OIC',
                        'statutory_reference' => 'Code civil italien art. 2424–2425 / OIC',
                    ],
                ],
                'nodes' => self::italianOicNodes(),
                'default_mappings' => self::italianToSwissDefaultMappings(),
            ],
            ChartTemplateCatalog::FRENCH_PCG => self::legacyCatalogEntry(
                ChartTemplateCatalog::FRENCH_PCG,
                'FR',
                'EUR',
                'Plan Comptable Général (PCG)',
                FrenchPcgData::accounts(),
            ),
            ChartTemplateCatalog::SPANISH_PGC => self::legacyCatalogEntry(
                ChartTemplateCatalog::SPANISH_PGC,
                'ES',
                'EUR',
                'Plan General de Contabilidad (PGC)',
                SpanishPgcData::accounts(),
            ),
            ChartTemplateCatalog::GERMAN_SKR03 => self::legacyCatalogEntry(
                ChartTemplateCatalog::GERMAN_SKR03,
                'DE',
                'EUR',
                'DATEV Standardkontenrahmen SKR03',
                Skr03Data::accounts(),
            ),
            ChartTemplateCatalog::GERMAN_SKR04 => self::legacyCatalogEntry(
                ChartTemplateCatalog::GERMAN_SKR04,
                'DE',
                'EUR',
                'DATEV Standardkontenrahmen SKR04',
                Skr04Data::accounts(),
            ),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: ?string}>  $rows
     * @return array<string, mixed>
     */
    private static function legacyCatalogEntry(string $standard, string $country, string $currency, string $title, array $rows): array
    {
        $parents = array_unique(array_filter(array_column($rows, 4)));
        $nodes = array_map(static function (array $row) use ($parents, $currency): array {
            [$code, $name, $type, $normalBalance, $parentCode, $reportingClass, $reconciliationType] = $row;

            return [
                'code' => $code,
                'names' => ['it' => $name, 'en' => $name, 'de' => $name, 'fr' => $name],
                'type' => $type,
                'normal_balance' => $normalBalance,
                'parent_code' => $parentCode,
                'reporting_class' => $reportingClass,
                'reconciliation_type' => $reconciliationType,
                'is_postable' => ! in_array($code, $parents, true),
                'open_items_enabled' => in_array($reconciliationType, ['customer', 'supplier'], true),
                'allowed_currencies' => [$currency],
                'statutory_code' => null,
            ];
        }, $rows);

        return [
            'standard' => $standard,
            'country' => $country,
            'currency' => $currency,
            'texts' => [
                'it' => ['name' => $title, 'subtitle' => $title, 'statutory_reference' => $title],
                'en' => ['name' => $title, 'subtitle' => $title, 'statutory_reference' => $title],
                'de' => ['name' => $title, 'subtitle' => $title, 'statutory_reference' => $title],
                'fr' => ['name' => $title, 'subtitle' => $title, 'statutory_reference' => $title],
            ],
            'nodes' => $nodes,
        ];
    }

    /**
     * Swiss KMU / Käfer (Classes 1-9) with multi-level hierarchy, open items, allowed currencies & VAT hints.
     *
     * @return list<array<string, mixed>>
     */
    private static function swissKmuNodes(): array
    {
        $n = static fn (
            string $code,
            string $it,
            string $de,
            string $fr,
            string $en,
            string $type,
            string $balance,
            ?string $parent,
            bool $postable,
            ?string $reportingClass = null,
            ?string $reconciliation = null,
            bool $openItems = false,
            array $currencies = ['CHF', 'EUR'],
            ?string $statutory = null,
            ?string $vatCode = null,
        ): array => [
            'code' => $code,
            'names' => ['it' => $it, 'de' => $de, 'fr' => $fr, 'en' => $en],
            'type' => $type,
            'normal_balance' => $balance,
            'parent_code' => $parent,
            'is_postable' => $postable,
            'reporting_class' => $reportingClass,
            'reconciliation_type' => $reconciliation,
            'open_items_enabled' => $openItems,
            'allowed_currencies' => $currencies,
            'statutory_code' => $statutory,
            'default_vat_code' => $vatCode,
        ];

        return [
            // Class 1: Attivo / Aktiven
            $n('1', 'Attivo', 'Aktiven', 'Actifs', 'Assets', 'asset', 'debit', null, false, null, null, false, ['CHF', 'EUR'], 'OR 959a I'),
            $n('10', 'Attivo circolante', 'Umlaufvermögen', 'Actifs circulants', 'Current Assets', 'asset', 'debit', '1', false, null, null, false, ['CHF', 'EUR'], 'OR 959a I.1'),
            $n('100', 'Liquidità e titoli a breve', 'Flüssige Mittel und Wertschriften', 'Liquidités et titres à court terme', 'Cash and Short-Term Securities', 'asset', 'debit', '10', false, null, null, false, ['CHF', 'EUR'], 'OR 959a I.1.a'),
            $n('1000', 'Cassa', 'Kasse', 'Caisse', 'Cash on Hand', 'asset', 'debit', '100', true, 'cash', null, false, ['CHF', 'EUR'], 'OR 959a I.1.a'),
            $n('1020', 'Banca c/c CHF', 'Bankguthaben CHF', 'Banque CHF', 'Bank Account CHF', 'asset', 'debit', '100', true, 'cash', 'bank', false, ['CHF'], 'OR 959a I.1.a'),
            $n('1021', 'PostFinance / Conto postale', 'Postcheckguthaben', 'Compte postal PostFinance', 'Postal Account', 'asset', 'debit', '100', true, 'cash', 'bank', false, ['CHF', 'EUR'], 'OR 959a I.1.a'),
            $n('1022', 'Banca c/c EUR (Estero)', 'Bankguthaben EUR', 'Banque EUR', 'Bank Account EUR', 'asset', 'debit', '100', true, 'cash', 'bank', false, ['EUR'], 'OR 959a I.1.a'),
            $n('1060', 'Titoli negoziabili a breve termine', 'Wertschriften kurzfristig', 'Titres à court terme', 'Short-Term Marketable Securities', 'asset', 'debit', '100', true, 'other_current_asset'),
            $n('110', 'Crediti', 'Forderungen', 'Créances', 'Receivables', 'asset', 'debit', '10', false, null, null, false, ['CHF', 'EUR'], 'OR 959a I.1.b'),
            $n('1100', 'Crediti verso clienti (Debitori)', 'Forderungen aus Lieferungen und Leistungen (Debitoren)', 'Créances résultant de ventes (Débiteurs)', 'Accounts Receivable (Debtors)', 'asset', 'debit', '110', true, 'receivable', 'customer', true, ['CHF', 'EUR'], 'OR 959a I.1.b'),
            $n('1109', 'Fondo svalutazione crediti (Delkredere)', 'Delkredere', 'Ducroire (provision créances)', 'Allowance for Doubtful Accounts', 'asset', 'credit', '110', true, 'receivable'),
            $n('1140', 'Anticipi e prestiti a breve', 'Vorschüsse und Darlehen', 'Avances et prêts', 'Advances and Short-Term Loans', 'asset', 'debit', '110', true, 'other_current_asset', null, true),
            $n('1170', 'IVA precedente su materiale e servizi (Vorsteuer)', 'Vorsteuer MWST auf Material, Waren, Dienstleistungen', 'Impôt préalable TVA sur matériel et services', 'Input VAT on Materials & Services', 'asset', 'debit', '110', true, 'other_current_asset', 'tax', false, ['CHF'], null, 'CH_MWST_81'),
            $n('1171', 'IVA precedente su investimenti e altri costi', 'Vorsteuer MWST auf Investitionen und übrigen Aufwand', 'Impôt préalable TVA sur investissements', 'Input VAT on Investments & Overhead', 'asset', 'debit', '110', true, 'other_current_asset', 'tax', false, ['CHF'], null, 'CH_MWST_81'),
            $n('1176', 'Imposta preventiva (Verrechnungssteuer)', 'Verrechnungssteuer', 'Impôt anticipé', 'Swiss Withholding Tax Receivable', 'asset', 'debit', '110', true, 'other_current_asset'),
            $n('1180', 'Crediti verso assicurazioni sociali', 'Forderungen gegenüber Sozialversicherungen', 'Créances envers assurances sociales', 'Social Security Receivables', 'asset', 'debit', '110', true, 'other_current_asset'),
            $n('120', 'Scorte e lavori in corso', 'Vorräte und angefangene Arbeiten', 'Stocks et travaux en cours', 'Inventories and WIP', 'asset', 'debit', '10', false, 'inventory', null, false, ['CHF', 'EUR'], 'OR 959a I.1.d'),
            $n('1200', 'Merci commerciali', 'Handelswaren', 'Marchandises', 'Merchandise Inventory', 'asset', 'debit', '120', true, 'inventory', 'inventory'),
            $n('1210', 'Materie prime', 'Rohmaterial', 'Matières premières', 'Raw Materials', 'asset', 'debit', '120', true, 'inventory', 'inventory'),
            $n('1230', 'Lavori in corso / commesse', 'Angefangene Arbeiten', 'Travaux en cours', 'Work in Progress', 'wip', 'debit', '120', true, 'inventory', 'wip'),
            $n('1300', 'Ratei e risconti attivi', 'Aktive Rechnungsabgrenzung', 'Actifs de régularisation', 'Prepaid Expenses & Accrued Income', 'asset', 'debit', '10', true, 'other_current_asset', null, false, ['CHF', 'EUR'], 'OR 959a I.1.e'),
            $n('14', 'Attivo fisso / Immobilizzazioni', 'Anlagevermögen', 'Actifs immobilisés', 'Non-Current Assets', 'asset', 'debit', '1', false, 'non_current_asset', null, false, ['CHF', 'EUR'], 'OR 959a I.2'),
            $n('1400', 'Immobilizzazioni finanziarie', 'Finanzanlagen', 'Immobilisations financières', 'Financial Investments', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1440', 'Prestiti attivi a lungo termine', 'Darlehen langfristig', 'Prêts à long terme', 'Long-Term Loans Receivable', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1500', 'Macchinari e impianti', 'Maschinen und Apparate', 'Machines et appareils', 'Machinery and Equipment', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1510', 'Mobili e arredi', 'Mobiliar und Einrichtungen', 'Mobilier et installations', 'Furniture and Fixtures', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1520', 'Macchine ufficio e informatica', 'Büromaschinen, Informatik', 'Machines de bureau, informatique', 'IT & Office Equipment', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1530', 'Veicoli', 'Fahrzeuge', 'Véhicules', 'Vehicles', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1600', 'Immobili', 'Immobilien', 'Immeubles', 'Real Estate & Buildings', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1700', 'Valori immateriali', 'Immaterielle Werte', 'Valeurs immatérielles', 'Intangible Assets', 'asset', 'debit', '14', true, 'non_current_asset'),
            $n('1850', 'Rettifiche di valore immobilizzazioni', 'Wertberichtigung Anlagevermögen', 'Corrections de valeur immobilisations', 'Accumulated Depreciation', 'asset', 'credit', '14', true, 'non_current_asset'),

            // Class 2: Passivo / Passiven
            $n('2', 'Passivo', 'Passiven', 'Passifs', 'Liabilities & Equity', 'liability', 'credit', null, false, null, null, false, ['CHF', 'EUR'], 'OR 959a II'),
            $n('20', 'Capitale di terzi a breve termine', 'Kurzfristiges Fremdkapital', 'Capitaux étrangers à court terme', 'Current Liabilities', 'liability', 'credit', '2', false, 'current_liability', null, false, ['CHF', 'EUR'], 'OR 959a II.1'),
            $n('2000', 'Debiti verso fornitori (Creditori)', 'Verbindlichkeiten aus Lieferungen und Leistungen (Kreditoren)', 'Dettes résultant d\'achats (Créditeurs)', 'Accounts Payable (Creditors)', 'liability', 'credit', '20', true, 'current_liability', 'supplier', true, ['CHF', 'EUR'], 'OR 959a II.1.a'),
            $n('2030', 'Acconti ricevuti da clienti', 'Erhaltene Anzahlungen', 'Acomptes reçus de clients', 'Customer Advances', 'liability', 'credit', '20', true, 'current_liability', null, true),
            $n('2100', 'Debiti bancari a breve termine', 'Bankverbindlichkeiten kurzfristig', 'Dettes bancaires à court terme', 'Short-Term Bank Debt', 'liability', 'credit', '20', true, 'current_liability'),
            $n('2200', 'IVA dovuta (Umsatzsteuer)', 'Geschuldete MWST (Umsatzsteuer)', 'TVA due', 'Output VAT Payable', 'liability', 'credit', '20', true, 'current_liability', 'tax', false, ['CHF'], null, 'CH_MWST_81'),
            $n('2201', 'Conto liquidazione IVA (AFC)', 'Abrechnungskonto MWST', 'Compte de décompte TVA', 'VAT Settlement Account', 'liability', 'credit', '20', true, 'current_liability', 'tax'),
            $n('2206', 'Imposta preventiva dovuta', 'Verrechnungssteuer geschuldet', 'Impôt anticipé dû', 'Withholding Tax Payable', 'liability', 'credit', '20', true, 'current_liability'),
            $n('2261', 'Dividendi da pagare', 'Dividenden', 'Dividendes', 'Dividends Payable', 'liability', 'credit', '20', true, 'current_liability'),
            $n('2270', 'Debiti assicurazioni sociali (AVS/AI/IPG/AD)', 'Sozialversicherungen (AHV/IV/EO/ALV)', 'Assurances sociales (AVS/AI/APG/AC)', 'Social Insurance Payable', 'liability', 'credit', '20', true, 'current_liability'),
            $n('2271', 'Cassa pensioni (LPP / BVG)', 'Pensionskasse (BVG)', 'Caisse de pension (LPP)', 'Pension Fund Payable (LPP)', 'liability', 'credit', '20', true, 'current_liability'),
            $n('2279', 'Imposta alla fonte (Quellensteuer)', 'Quellensteuer', 'Impôt à la source', 'Payroll Withholding Tax', 'liability', 'credit', '20', true, 'current_liability'),
            $n('2300', 'Ratei e risconti passivi', 'Passive Rechnungsabgrenzung', 'Passifs de régularisation', 'Accrued Liabilities & Deferred Income', 'liability', 'credit', '20', true, 'current_liability'),
            $n('2330', 'Accantonamenti a breve termine', 'Kurzfristige Rückstellungen', 'Provisions à court terme', 'Short-Term Provisions', 'liability', 'credit', '20', true, 'current_liability'),
            $n('24', 'Capitale di terzi a lungo termine', 'Langfristiges Fremdkapital', 'Capitaux étrangers à long terme', 'Non-Current Liabilities', 'liability', 'credit', '2', false, 'non_current_liability', null, false, ['CHF', 'EUR'], 'OR 959a II.2'),
            $n('2400', 'Debiti bancari a lungo termine', 'Bankverbindlichkeiten langfristig', 'Dettes bancaires à long terme', 'Long-Term Bank Loans', 'liability', 'credit', '24', true, 'non_current_liability'),
            $n('2450', 'Mutui e prestiti a lungo termine', 'Darlehen langfristig', 'Emprunts à long terme', 'Long-Term Borrowings', 'liability', 'credit', '24', true, 'non_current_liability'),
            $n('2600', 'Accantonamenti a lungo termine', 'Langfristige Rückstellungen', 'Provisions à long terme', 'Long-Term Provisions', 'liability', 'credit', '24', true, 'non_current_liability'),
            $n('28', 'Capitale proprio', 'Eigenkapital', 'Capitaux propres', 'Equity', 'equity', 'credit', '2', false, 'equity', null, false, ['CHF'], 'OR 959a II.3'),
            $n('2800', 'Capitale azionario / sociale', 'Aktienkapital / Stammkapital', 'Capital-actions / Capital social', 'Share Capital', 'equity', 'credit', '28', true, 'equity'),
            $n('2850', 'Conto privato (Ditta individuale)', 'Privatkonto (Einzelfirma)', 'Compte privé', 'Owner Drawing Account', 'equity', 'credit', '28', true, 'equity'),
            $n('2900', 'Riserva legale da capitale', 'Gesetzliche Kapitalreserve', 'Réserve légale issue du capital', 'Legal Capital Reserve', 'equity', 'credit', '28', true, 'equity'),
            $n('2950', 'Riserva legale da utili', 'Gesetzliche Gewinnreserve', 'Réserve légale issue du bénéfice', 'Legal Retained Earnings', 'equity', 'credit', '28', true, 'equity'),
            $n('2979', 'Utile o perdita dell\'esercizio', 'Jahresgewinn oder Jahresverlust', 'Bénéfice ou perte de l\'exercice', 'Annual Profit or Loss', 'equity', 'credit', '28', true, 'equity'),

            // Class 3: Ricavi d'esercizio
            $n('3', 'Ricavi d\'esercizio da forniture e prestazioni', 'Betriebsertrag aus Lieferungen und Leistungen', 'Chiffre d\'affaires résultant des ventes et prestations', 'Operating Revenue', 'revenue', 'credit', null, false, null, null, false, ['CHF', 'EUR'], 'OR 959b'),
            $n('3000', 'Ricavi di produzione', 'Produktionserlös', 'Ventes de produits fabriqués', 'Production Revenue', 'revenue', 'credit', '3', true, null, null, false, ['CHF', 'EUR'], null, 'CH_MWST_81'),
            $n('3200', 'Ricavi da vendita merci', 'Handelserlös', 'Ventes de marchandises', 'Merchandise Sales Revenue', 'revenue', 'credit', '3', true, null, null, false, ['CHF', 'EUR'], null, 'CH_MWST_81'),
            $n('3400', 'Ricavi da prestazioni di servizi', 'Dienstleistungserlös', 'Prestations de services', 'Service Revenue', 'revenue', 'credit', '3', true, null, null, false, ['CHF', 'EUR'], null, 'CH_MWST_81'),
            $n('3600', 'Altri ricavi d\'esercizio', 'Übrige Erlöse', 'Autres produits d\'exploitation', 'Other Operating Revenue', 'revenue', 'credit', '3', true),
            $n('3800', 'Deduzioni su ricavi (sconti, ribassi)', 'Erlösminderungen (Skonti, Rabatte)', 'Déductions sur ventes (escomptes, rabais)', 'Sales Discounts & Allowances', 'revenue', 'debit', '3', true),
            $n('3805', 'Perdite su crediti', 'Verluste aus Forderungen', 'Pertes sur créances', 'Bad Debt Losses', 'expense', 'debit', '3', true),

            // Class 4: Costi per materiali e merci
            $n('4', 'Costi per materiali, merci e prestazioni terzi', 'Aufwand für Material, Waren und Drittleistungen', 'Charges de matériel, marchandises et services de tiers', 'Material & Merchandise Costs', 'expense', 'debit', null, false, null, null, false, ['CHF', 'EUR'], 'OR 959b'),
            $n('4000', 'Acquisti materiale', 'Materialaufwand', 'Achats de matières', 'Material Purchases', 'cogs', 'debit', '4', true, null, null, false, ['CHF', 'EUR'], null, 'CH_MWST_81'),
            $n('4200', 'Acquisti merci destinate alla rivendita', 'Handelswarenaufwand', 'Achats de marchandises', 'Merchandise Purchases (COGS)', 'cogs', 'debit', '4', true, null, null, false, ['CHF', 'EUR'], null, 'CH_MWST_81'),
            $n('4400', 'Prestazioni di terzi', 'Aufwand für Drittleistungen', 'Prestations de tiers', 'Subcontractor Services', 'cogs', 'debit', '4', true),
            $n('4900', 'Variazione scorte', 'Bestandesänderungen', 'Variations de stocks', 'Inventory Changes', 'expense', 'debit', '4', true),

            // Class 5: Costi del personale
            $n('5', 'Costi del personale', 'Personalaufwand', 'Charges de personnel', 'Personnel Costs', 'expense', 'debit', null, false, null, null, false, ['CHF'], 'OR 959b'),
            $n('5000', 'Salari e stipendi', 'Lohnaufwand', 'Salaires', 'Wages and Salaries', 'expense', 'debit', '5', true),
            $n('5700', 'Oneri sociali (AVS/AI/IPG/AD/LAINF)', 'Sozialversicherungsaufwand', 'Charges sociales', 'Social Security Contributions', 'expense', 'debit', '5', true),
            $n('5720', 'Previdenza professionale (LPP)', 'Berufliche Vorsorge', 'Prévoyance professionnelle', 'Occupational Pension Expense', 'expense', 'debit', '5', true),
            $n('5800', 'Altri costi del personale', 'Übriger Personalaufwand', 'Autres charges de personnel', 'Other Personnel Expenses', 'expense', 'debit', '5', true),

            // Class 6: Altri costi d'esercizio
            $n('6', 'Altri costi d\'esercizio, ammortamenti e risultato finanziario', 'Sonstiger Betriebsaufwand', 'Autres charges d\'exploitation', 'Other Operating Expenses', 'expense', 'debit', null, false, null, null, false, ['CHF', 'EUR'], 'OR 959b'),
            $n('6000', 'Costi locali e affitti', 'Raumaufwand', 'Loyers et charges de locaux', 'Rent & Premises Expense', 'expense', 'debit', '6', true),
            $n('6100', 'Manutenzione e riparazioni', 'Unterhalt und Reparaturen', 'Entretien et réparations', 'Maintenance & Repairs', 'expense', 'debit', '6', true),
            $n('6200', 'Spese veicoli e trasporti', 'Fahrzeugaufwand', 'Charges de véhicules', 'Vehicle Expenses', 'expense', 'debit', '6', true),
            $n('6300', 'Assicurazioni, tasse e tributi', 'Versicherungen, Gebühren, Bewilligungen', 'Assurances, droits et taxes', 'Insurance, Fees & Permits', 'expense', 'debit', '6', true),
            $n('6400', 'Energia e smaltimento', 'Energie- und Entsorgungsaufwand', 'Énergie et élimination', 'Energy & Waste Disposal', 'expense', 'debit', '6', true),
            $n('6500', 'Amministrazione e informatica', 'Verwaltungs- und Informatikaufwand', 'Frais d\'administration et d\'informatique', 'Administration & IT Expenses', 'expense', 'debit', '6', true),
            $n('6600', 'Pubblicità e marketing', 'Werbeaufwand', 'Publicité', 'Advertising & Marketing', 'expense', 'debit', '6', true),
            $n('6700', 'Altri costi d\'esercizio', 'Sonstiger Betriebsaufwand', 'Autres charges d\'exploitation', 'Miscellaneous Operating Expenses', 'expense', 'debit', '6', true),
            $n('6800', 'Ammortamenti', 'Abschreibungen', 'Amortissements', 'Depreciation & Amortization', 'expense', 'debit', '6', true, 'non_cash_expense'),
            $n('6900', 'Oneri finanziari', 'Finanzaufwand', 'Charges financières', 'Financial Expenses', 'expense', 'debit', '6', true),
            $n('6950', 'Proventi finanziari', 'Finanzertrag', 'Produits financiers', 'Financial Income', 'revenue', 'credit', '6', true),

            // Class 7: Attività accessorie
            $n('7', 'Risultato da attività accessorie d\'esercizio', 'Betriebliche Nebenerfolge', 'Résultats annexes d\'exploitation', 'Ancillary Operating Activities', 'revenue', 'credit', null, false, null, null, false, ['CHF', 'EUR'], 'OR 959b'),
            $n('7000', 'Proventi da attività accessorie', 'Ertrag Nebenbetriebe', 'Produits des activités annexes', 'Ancillary Operations Revenue', 'revenue', 'credit', '7', true),
            $n('7500', 'Proventi da immobili d\'esercizio', 'Ertrag betriebliche Liegenschaften', 'Produits des immeubles d\'exploitation', 'Operating Real Estate Income', 'revenue', 'credit', '7', true),

            // Class 8: Risultato straordinario e imposte
            $n('8', 'Risultato straordinario, non operativo e imposte', 'Ausserordentlicher und betriebsfremder Erfolg, Steuern', 'Résultat extraordinaire, hors exploitation et impôts', 'Extraordinary Items & Taxes', 'expense', 'debit', null, false, null, null, false, ['CHF', 'EUR'], 'OR 959b'),
            $n('8000', 'Oneri non operativi', 'Betriebsfremder Aufwand', 'Charges hors exploitation', 'Non-Operating Expenses', 'expense', 'debit', '8', true),
            $n('8100', 'Proventi non operativi', 'Betriebsfremder Ertrag', 'Produits hors exploitation', 'Non-Operating Income', 'revenue', 'credit', '8', true),
            $n('8500', 'Oneri straordinari', 'Ausserordentlicher Aufwand', 'Charges extraordinaires', 'Extraordinary Expenses', 'expense', 'debit', '8', true),
            $n('8510', 'Proventi straordinari', 'Ausserordentlicher Ertrag', 'Produits extraordinaires', 'Extraordinary Income', 'revenue', 'credit', '8', true),
            $n('8900', 'Imposte dirette', 'Direkte Steuern', 'Impôts directs', 'Direct Taxes', 'expense', 'debit', '8', true),

            // Class 9: Chiusure
            $n('9', 'Conti di chiusura', 'Abschluss', 'Clôture', 'Closing Accounts', 'statistical', 'debit', null, false, null, null, false, ['CHF']),
            $n('9000', 'Conto economico (riepilogo chiusura)', 'Erfolgsrechnung (Abschluss)', 'Compte de résultat (clôture)', 'Income Statement Closing Summary', 'statistical', 'debit', '9', true),
            $n('9100', 'Stato patrimoniale (bilancio di chiusura)', 'Bilanz (Abschluss)', 'Bilan (clôture)', 'Balance Sheet Closing Summary', 'statistical', 'debit', '9', true),
        ];
    }

    /**
     * Italian Civil Code Artt. 2424-2425 / OIC hierarchy:
     * Stato Patrimoniale Attivo (A, B, C, D) -> Passivo (A, B, C, D, E) -> Conto Economico (A, B, C, D, E/Imposte)
     * down to synthetic mastri (10..57) and operational sub-accounts (10.01..57.02).
     *
     * @return list<array<string, mixed>>
     */
    private static function italianOicNodes(): array
    {
        $n = static fn (
            string $code,
            string $it,
            string $en,
            string $de,
            string $fr,
            string $type,
            string $balance,
            ?string $parent,
            bool $postable,
            ?string $reportingClass = null,
            ?string $reconciliation = null,
            bool $openItems = false,
            array $currencies = ['EUR'],
            ?string $statutory = null,
            ?string $vatCode = null,
        ): array => [
            'code' => $code,
            'names' => ['it' => $it, 'en' => $en, 'de' => $de, 'fr' => $fr],
            'type' => $type,
            'normal_balance' => $balance,
            'parent_code' => $parent,
            'is_postable' => $postable,
            'reporting_class' => $reportingClass,
            'reconciliation_type' => $reconciliation,
            'open_items_enabled' => $openItems,
            'allowed_currencies' => $currencies,
            'statutory_code' => $statutory,
            'default_vat_code' => $vatCode,
        ];

        return [
            // === STATO PATRIMONIALE — ATTIVO (Art. 2424 c.c.) ===
            $n('SP.A', 'Stato Patrimoniale — Attivo', 'Balance Sheet — Assets', 'Bilanz — Aktiva', 'Bilan — Actif', 'asset', 'debit', null, false, null, null, false, ['EUR'], 'Art. 2424 Attivo'),
            $n('SP.A.A', 'A) Crediti verso soci per versamenti ancora dovuti', 'A) Subscribed Capital Unpaid', 'A) Ausstehende Einlagen', 'A) Capital souscrit non appelé', 'asset', 'debit', 'SP.A', false, 'other_current_asset', null, false, ['EUR'], 'Art. 2424 Attivo A'),
            $n('SP.A.B', 'B) Immobilizzazioni', 'B) Fixed Assets', 'B) Anlagevermögen', 'B) Actifs immobilisés', 'asset', 'debit', 'SP.A', false, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 Attivo B'),
            $n('SP.A.B.I', 'B.I) Immobilizzazioni immateriali', 'B.I) Intangible Fixed Assets', 'B.I) Immaterielle Vermögenswerte', 'B.I) Immobilisations incorporelles', 'asset', 'debit', 'SP.A.B', false, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 Attivo B.I'),
            $n('10', 'Immobilizzazioni immateriali', 'Intangible Assets', 'Immaterielle Anlagen', 'Immobilisations incorporelles', 'asset', 'debit', 'SP.A.B.I', false, 'non_current_asset'),
            $n('10.01', 'Costi di impianto e di ampliamento', 'Start-up and Expansion Costs', 'Gründungs- und Erweiterungskosten', 'Frais d\'établissement', 'asset', 'debit', '10', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.I.1'),
            $n('10.02', 'Diritti di brevetto, software e licenze', 'Patents, Software & Licenses', 'Software und Lizenzen', 'Brevets, logiciels et licences', 'asset', 'debit', '10', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.I.3'),
            $n('10.09', 'Fondo ammortamento immobilizzazioni immateriali', 'Accumulated Amortization (Intangibles)', 'Kumulierte Abschreibungen immateriell', 'Amortissements cumulés incorporels', 'asset', 'credit', '10', true, 'non_current_asset'),
            $n('SP.A.B.II', 'B.II) Immobilizzazioni materiali', 'B.II) Tangible Fixed Assets', 'B.II) Sachanlagen', 'B.II) Immobilisations corporelles', 'asset', 'debit', 'SP.A.B', false, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 Attivo B.II'),
            $n('11', 'Immobilizzazioni materiali', 'Tangible Assets', 'Sachanlagen', 'Immobilisations corporelles', 'asset', 'debit', 'SP.A.B.II', false, 'non_current_asset'),
            $n('11.01', 'Terreni e fabbricati', 'Land and Buildings', 'Grundstücke und Gebäude', 'Terrains et constructions', 'asset', 'debit', '11', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.II.1'),
            $n('11.02', 'Impianti e macchinari', 'Plant and Machinery', 'Anlagen und Maschinen', 'Installations et machines', 'asset', 'debit', '11', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.II.2'),
            $n('11.03', 'Attrezzature industriali e commerciali', 'Industrial & Commercial Equipment', 'Betriebs- und Geschäftsausstattung', 'Outillage industriel et commercial', 'asset', 'debit', '11', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.II.3'),
            $n('11.04', 'Mobili e arredi d\'ufficio', 'Office Furniture & Fixtures', 'Büromöbel und Einrichtung', 'Mobilier de bureau', 'asset', 'debit', '11', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.II.4'),
            $n('11.05', 'Macchine elettroniche e sistemi IT', 'IT & Electronic Equipment', 'EDV-Anlagen', 'Matériel informatique', 'asset', 'debit', '11', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.II.4'),
            $n('11.06', 'Automezzi e veicoli aziendali', 'Vehicles', 'Fahrzeuge', 'Véhicules', 'asset', 'debit', '11', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.II.4'),
            $n('11.09', 'Fondo ammortamento immobilizzazioni materiali', 'Accumulated Depreciation (Tangibles)', 'Kumulierte Abschreibungen Sachanlagen', 'Amortissements cumulés corporels', 'asset', 'credit', '11', true, 'non_current_asset'),
            $n('SP.A.B.III', 'B.III) Immobilizzazioni finanziarie', 'B.III) Financial Fixed Assets', 'B.III) Finanzanlagen', 'B.III) Immobilisations financières', 'asset', 'debit', 'SP.A.B', false, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 Attivo B.III'),
            $n('12', 'Immobilizzazioni finanziarie', 'Financial Assets', 'Finanzanlagen', 'Immobilisations financières', 'asset', 'debit', 'SP.A.B.III', false, 'non_current_asset'),
            $n('12.01', 'Partecipazioni in imprese controllate e collegate', 'Equity Investments', 'Beteiligungen', 'Participations', 'asset', 'debit', '12', true, 'non_current_asset', null, false, ['EUR'], 'Art. 2424 B.III.1'),
            $n('12.02', 'Depositi cauzionali attivi', 'Security Deposits', 'Kautionen', 'Dépôts de garantie', 'asset', 'debit', '12', true, 'non_current_asset'),
            $n('SP.A.C', 'C) Attivo circolante', 'C) Current Assets', 'C) Umlaufvermögen', 'C) Actif circulant', 'asset', 'debit', 'SP.A', false, null, null, false, ['EUR'], 'Art. 2424 Attivo C'),
            $n('SP.A.C.I', 'C.I) Rimanenze', 'C.I) Inventories', 'C.I) Vorräte', 'C.I) Stocks', 'asset', 'debit', 'SP.A.C', false, 'inventory', null, false, ['EUR'], 'Art. 2424 Attivo C.I'),
            $n('20', 'Rimanenze di magazzino', 'Inventories', 'Vorräte', 'Stocks', 'asset', 'debit', 'SP.A.C.I', false, 'inventory'),
            $n('20.01', 'Merci', 'Merchandise', 'Handelswaren', 'Marchandises', 'asset', 'debit', '20', true, 'inventory', 'inventory'),
            $n('20.02', 'Materie prime, sussidiarie e di consumo', 'Raw & Consumable Materials', 'Roh-, Hilfs- und Betriebsstoffe', 'Matières premières et consommables', 'asset', 'debit', '20', true, 'inventory', 'inventory'),
            $n('20.03', 'Prodotti finiti', 'Finished Goods', 'Fertigerzeugnisse', 'Produits finis', 'asset', 'debit', '20', true, 'inventory', 'inventory'),
            $n('20.04', 'Lavori in corso su ordinazione', 'Contract Work in Progress', 'Unfertige Aufträge', 'Travaux en cours', 'wip', 'debit', '20', true, 'inventory', 'wip'),
            $n('SP.A.C.II', 'C.II) Crediti', 'C.II) Receivables', 'C.II) Forderungen', 'C.II) Créances', 'asset', 'debit', 'SP.A.C', false, 'receivable', null, false, ['EUR'], 'Art. 2424 Attivo C.II'),
            $n('21', 'Crediti commerciali e diversi', 'Trade & Other Receivables', 'Forderungen', 'Créances', 'asset', 'debit', 'SP.A.C.II', false, 'receivable'),
            $n('21.01', 'Crediti verso clienti', 'Accounts Receivable', 'Forderungen aus Lieferungen und Leistungen', 'Clients', 'asset', 'debit', '21', true, 'receivable', 'customer', true, ['EUR', 'CHF'], 'Art. 2424 C.II.1'),
            $n('21.02', 'Fatture da emettere', 'Unbilled Receivables', 'Noch nicht fakturierte Lieferungen', 'Factures à établir', 'asset', 'debit', '21', true, 'receivable'),
            $n('21.03', 'Fondo svalutazione crediti', 'Allowance for Bad Debts', 'Wertberichtigung auf Forderungen', 'Dépréciation des créances', 'asset', 'credit', '21', true, 'receivable'),
            $n('21.04', 'Erario c/IVA a credito', 'VAT Credit Balance', 'Vorsteuer-Guthaben', 'Crédit de TVA', 'asset', 'debit', '21', true, 'other_current_asset', 'tax'),
            $n('21.05', 'IVA c/acquisti', 'Input VAT', 'Vorsteuer auf Einkäufe', 'TVA déductible', 'asset', 'debit', '21', true, 'other_current_asset', 'tax', false, ['EUR'], null, 'IVA22'),
            $n('21.06', 'Erario c/ritenute subite', 'Withholding Tax Credits', 'Einbehaltene Quellensteuern', 'Retenues subies', 'asset', 'debit', '21', true, 'other_current_asset'),
            $n('21.07', 'Erario c/acconti imposte', 'Tax Prepayments', 'Steuervorauszahlungen', 'Acomptes d\'impôts', 'asset', 'debit', '21', true, 'other_current_asset'),
            $n('21.08', 'Crediti verso INPS e INAIL', 'Social Security Receivables', 'Forderungen Sozialversicherung', 'Créances sociales', 'asset', 'debit', '21', true, 'other_current_asset'),
            $n('SP.A.C.IV', 'C.IV) Disponibilità liquide', 'C.IV) Cash and Cash Equivalents', 'C.IV) Flüssige Mittel', 'C.IV) Disponibilités', 'asset', 'debit', 'SP.A.C', false, 'cash', null, false, ['EUR', 'CHF'], 'Art. 2424 Attivo C.IV'),
            $n('22', 'Disponibilità liquide', 'Cash & Bank', 'Flüssige Mittel', 'Disponibilités', 'asset', 'debit', 'SP.A.C.IV', false, 'cash'),
            $n('22.01', 'Banca c/c ordinario', 'Operating Bank Account', 'Bankkontokorrent', 'Banque compte courant', 'asset', 'debit', '22', true, 'cash', 'bank', false, ['EUR', 'CHF'], 'Art. 2424 C.IV.1'),
            $n('22.02', 'Cassa contanti', 'Cash on Hand', 'Barkasse', 'Caisse', 'asset', 'debit', '22', true, 'cash', null, false, ['EUR'], 'Art. 2424 C.IV.3'),
            $n('22.03', 'Conto corrente postale', 'Postal Account', 'Postkonto', 'Compte postal', 'asset', 'debit', '22', true, 'cash', 'bank'),
            $n('SP.A.D', 'D) Ratei e risconti attivi', 'D) Prepayments & Accrued Income', 'D) Aktive Rechnungsabgrenzung', 'D) Comptes de régularisation actif', 'asset', 'debit', 'SP.A', false, 'other_current_asset', null, false, ['EUR'], 'Art. 2424 Attivo D'),
            $n('23', 'Ratei e risconti attivi', 'Prepaid Expenses & Accrued Income', 'Aktive Rechnungsabgrenzung', 'Charges constatées d\'avance', 'asset', 'debit', 'SP.A.D', true, 'other_current_asset'),

            // === STATO PATRIMONIALE — PASSIVO (Art. 2424 c.c.) ===
            $n('SP.P', 'Stato Patrimoniale — Passivo', 'Balance Sheet — Liabilities & Equity', 'Bilanz — Passiva', 'Bilan — Passif', 'liability', 'credit', null, false, null, null, false, ['EUR'], 'Art. 2424 Passivo'),
            $n('SP.P.A', 'A) Patrimonio netto', 'A) Shareholders\' Equity', 'A) Eigenkapital', 'A) Capitaux propres', 'equity', 'credit', 'SP.P', false, 'equity', null, false, ['EUR'], 'Art. 2424 Passivo A'),
            $n('30', 'Patrimonio netto', 'Equity', 'Eigenkapital', 'Capitaux propres', 'equity', 'credit', 'SP.P.A', false, 'equity'),
            $n('30.01', 'Capitale sociale', 'Share Capital', 'Gesellschaftskapital', 'Capital social', 'equity', 'credit', '30', true, 'equity', null, false, ['EUR'], 'Art. 2424 A.I'),
            $n('30.02', 'Riserva legale', 'Legal Reserve', 'Gesetzliche Rücklage', 'Réserve légale', 'equity', 'credit', '30', true, 'equity', null, false, ['EUR'], 'Art. 2424 A.IV'),
            $n('30.03', 'Riserve statutarie e straordinarie', 'Statutory & Extraordinary Reserves', 'Statutarische Rücklagen', 'Réserves statutaires', 'equity', 'credit', '30', true, 'equity', null, false, ['EUR'], 'Art. 2424 A.VI'),
            $n('30.04', 'Utili (perdite) portati a nuovo', 'Retained Earnings Carried Forward', 'Gewinn-/Verlustvortrag', 'Report à nouveau', 'equity', 'credit', '30', true, 'equity', null, false, ['EUR'], 'Art. 2424 A.VIII'),
            $n('30.05', 'Utile (perdita) dell\'esercizio', 'Net Profit (Loss) for the Year', 'Jahresergebnis', 'Résultat de l\'exercice', 'equity', 'credit', '30', true, 'equity', null, false, ['EUR'], 'Art. 2424 A.IX'),
            $n('SP.P.B', 'B) Fondi per rischi e oneri', 'B) Provisions for Risks & Charges', 'B) Rückstellungen', 'B) Provisions pour risques et charges', 'liability', 'credit', 'SP.P', false, 'non_current_liability', null, false, ['EUR'], 'Art. 2424 Passivo B'),
            $n('SP.P.C', 'C) Trattamento di fine rapporto (TFR)', 'C) Employee Severance Indemnity (TFR)', 'C) Abfertigungsrückstellung (TFR)', 'C) Indemnités de fin de contrat (TFR)', 'liability', 'credit', 'SP.P', false, 'non_current_liability', null, false, ['EUR'], 'Art. 2424 Passivo C'),
            $n('31', 'Fondi rischi, oneri e TFR', 'Provisions & TFR', 'Rückstellungen und TFR', 'Provisions et TFR', 'liability', 'credit', 'SP.P.B', false, 'non_current_liability'),
            $n('31.01', 'Fondo trattamento di fine rapporto (TFR)', 'TFR Liability Fund', 'TFR-Rückstellung', 'Fonds TFR', 'liability', 'credit', 'SP.P.C', true, 'non_current_liability'),
            $n('31.02', 'Fondi per imposte e rischi', 'Tax & Risk Provisions', 'Steuerrückstellungen', 'Provisions pour impôts', 'liability', 'credit', '31', true, 'non_current_liability'),
            $n('SP.P.D', 'D) Debiti', 'D) Payables', 'D) Verbindlichkeiten', 'D) Dettes', 'liability', 'credit', 'SP.P', false, 'current_liability', null, false, ['EUR', 'CHF'], 'Art. 2424 Passivo D'),
            $n('32', 'Debiti commerciali, finanziari e tributari', 'Trade, Financial & Tax Payables', 'Verbindlichkeiten', 'Dettes', 'liability', 'credit', 'SP.P.D', false, 'current_liability'),
            $n('32.01', 'Debiti verso fornitori', 'Accounts Payable', 'Verbindlichkeiten aus Lieferungen und Leistungen', 'Fournisseurs', 'liability', 'credit', '32', true, 'current_liability', 'supplier', true, ['EUR', 'CHF'], 'Art. 2424 D.7'),
            $n('32.02', 'Fatture da ricevere', 'Unbilled Payables (GR/IR)', 'Noch nicht erhaltene Rechnungen', 'Factures à recevoir', 'liability', 'credit', '32', true, 'current_liability', 'gr_ir'),
            $n('32.03', 'Debiti verso banche a breve', 'Short-Term Bank Debt', 'Kurzfristige Bankverbindlichkeiten', 'Dettes bancaires à court terme', 'liability', 'credit', '32', true, 'current_liability'),
            $n('32.04', 'Mutui passivi a lungo termine', 'Long-Term Mortgages & Loans', 'Langfristige Darlehen', 'Emprunts à long terme', 'liability', 'credit', '32', true, 'non_current_liability'),
            $n('32.05', 'Erario c/IVA a debito', 'VAT Payable Settlement', 'Geschuldete MWST-Abrechnung', 'TVA à décaisser', 'liability', 'credit', '32', true, 'current_liability', 'tax'),
            $n('32.06', 'IVA c/vendite', 'Output VAT', 'Umsatzsteuer', 'TVA collectée', 'liability', 'credit', '32', true, 'current_liability', 'tax', false, ['EUR'], null, 'IVA22'),
            $n('32.07', 'Erario c/ritenute da versare', 'Withholding Tax Payable', 'Abzuführende Quellensteuer', 'Retenues à reverser', 'liability', 'credit', '32', true, 'current_liability'),
            $n('32.08', 'Debiti verso INPS e INAIL', 'Social Security Payables', 'Sozialversicherungsverbindlichkeiten', 'Dettes sociales', 'liability', 'credit', '32', true, 'current_liability'),
            $n('32.09', 'Debiti verso dipendenti per retribuzioni', 'Wages & Salaries Payable', 'Lohnverbindlichkeiten', 'Personnel rémunérations dues', 'liability', 'credit', '32', true, 'current_liability'),
            $n('32.10', 'Erario c/imposte da versare (IRES, IRAP)', 'Corporate Taxes Payable (IRES/IRAP)', 'Körperschaftsteuerverbindlichkeiten', 'Impôts sur les sociétés dus', 'liability', 'credit', '32', true, 'current_liability'),
            $n('32.11', 'Acconti da clienti', 'Customer Advances', 'Erhaltene Anzahlungen', 'Avances clients', 'liability', 'credit', '32', true, 'current_liability', null, true),
            $n('SP.P.E', 'E) Ratei e risconti passivi', 'E) Accrued Liabilities & Deferred Income', 'E) Passive Rechnungsabgrenzung', 'E) Comptes de régularisation passif', 'liability', 'credit', 'SP.P', false, 'current_liability', null, false, ['EUR'], 'Art. 2424 Passivo E'),
            $n('33', 'Ratei e risconti passivi', 'Accrued Liabilities & Deferred Income', 'Passive Rechnungsabgrenzung', 'Produits constatés d\'avance', 'liability', 'credit', 'SP.P.E', true, 'current_liability'),

            // === CONTO ECONOMICO (Art. 2425 c.c.) ===
            $n('CE', 'Conto Economico', 'Income Statement', 'Erfolgsrechnung (GuV)', 'Compte de résultat', 'revenue', 'credit', null, false, null, null, false, ['EUR'], 'Art. 2425'),
            $n('CE.A', 'A) Valore della produzione', 'A) Value of Production', 'A) Gesamtleistung', 'A) Valeur de la production', 'revenue', 'credit', 'CE', false, null, null, false, ['EUR'], 'Art. 2425 A'),
            $n('40', 'Ricavi delle vendite e prestazioni', 'Sales & Service Revenue', 'Umsatzerlöse', 'Chiffre d\'affaires', 'revenue', 'credit', 'CE.A', false),
            $n('40.01', 'Ricavi delle vendite di merci e prodotti', 'Product & Merchandise Sales', 'Waren- und Produkterlöse', 'Ventes de marchandises', 'revenue', 'credit', '40', true, null, null, false, ['EUR'], 'Art. 2425 A.1', 'IVA22'),
            $n('40.02', 'Ricavi da prestazioni di servizi', 'Service Revenue', 'Dienstleistungserlöse', 'Prestations de services', 'revenue', 'credit', '40', true, null, null, false, ['EUR'], 'Art. 2425 A.1', 'IVA22'),
            $n('40.03', 'Resi, sconti e abbuoni su vendite', 'Sales Returns & Discounts', 'Erlösschmälerungen', 'Rabais et retours sur ventes', 'revenue', 'debit', '40', true),
            $n('40.04', 'Altri ricavi e proventi', 'Other Operating Income', 'Sonstige betriebliche Erträge', 'Autres produits d\'exploitation', 'revenue', 'credit', '40', true, null, null, false, ['EUR'], 'Art. 2425 A.5'),
            $n('CE.B', 'B) Costi della produzione', 'B) Production Costs', 'B) Herstellungs- und Betriebskosten', 'B) Coûts de production', 'expense', 'debit', 'CE', false, null, null, false, ['EUR'], 'Art. 2425 B'),
            $n('50', 'Costi per materie prime, sussidiarie e merci', 'Raw Materials & Merchandise Costs', 'Material- und Warenaufwand', 'Achats de matières et marchandises', 'cogs', 'debit', 'CE.B', false),
            $n('50.01', 'Acquisti di merci', 'Merchandise Purchases', 'Handelswareneinkauf', 'Achats de marchandises', 'cogs', 'debit', '50', true, null, null, false, ['EUR'], 'Art. 2425 B.6', 'IVA22'),
            $n('50.02', 'Acquisti di materie prime e di consumo', 'Raw & Consumable Purchases', 'Roh- und Hilfsstoffeinkauf', 'Achats de matières premières', 'cogs', 'debit', '50', true, null, null, false, ['EUR'], 'Art. 2425 B.6', 'IVA22'),
            $n('50.03', 'Resi, sconti e abbuoni su acquisti', 'Purchase Discounts & Returns', 'Erhaltene Skonti und Nachlässe', 'Rabais obtenus sur achats', 'cogs', 'credit', '50', true),
            $n('50.04', 'Variazione delle rimanenze', 'Change in Inventories', 'Bestandsveränderungen', 'Variation des stocks', 'expense', 'debit', '50', true, null, null, false, ['EUR'], 'Art. 2425 B.11'),
            $n('51', 'Costi per servizi', 'Service Expenses', 'Dienstleistungsaufwand', 'Services extérieurs', 'expense', 'debit', 'CE.B', false),
            $n('51.01', 'Energia elettrica, gas e acqua', 'Utilities (Power, Gas, Water)', 'Energie und Wasser', 'Énergie, gaz et eau', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.02', 'Spese telefoniche e connettività', 'Telecom & Internet', 'Telefon und Internet', 'Télécommunications', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.03', 'Consulenze professionali e legali', 'Professional & Legal Consulting', 'Rechts- und Beratungskosten', 'Honoraires professionnels', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.04', 'Spese pubblicitarie e marketing', 'Advertising & Marketing', 'Werbekosten', 'Publicité', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.05', 'Assicurazioni', 'Insurance', 'Versicherungen', 'Assurances', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.06', 'Manutenzioni e riparazioni', 'Maintenance & Repairs', 'Wartung und Reparaturen', 'Entretien et réparations', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.07', 'Spese bancarie e commissioni', 'Bank Fees & Charges', 'Bankspesen', 'Frais bancaires', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.08', 'Trasporti su acquisti e vendite', 'Freight & Transportation', 'Transportkosten', 'Transports', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('51.09', 'Carburanti e spese auto', 'Fuel & Vehicle Costs', 'Kraftstoffe und Kfz-Kosten', 'Carburants et véhicules', 'expense', 'debit', '51', true, null, null, false, ['EUR'], 'Art. 2425 B.7'),
            $n('52', 'Costi per godimento di beni di terzi', 'Use of Third-Party Assets', 'Miet- und Leasingaufwand', 'Locations et redevances', 'expense', 'debit', 'CE.B', false),
            $n('52.01', 'Affitti passivi', 'Rent Expense', 'Mietaufwand', 'Loyers', 'expense', 'debit', '52', true, null, null, false, ['EUR'], 'Art. 2425 B.8'),
            $n('52.02', 'Canoni di leasing e noleggio', 'Leasing & Rental Fees', 'Leasingaufwand', 'Crédit-bail et locations', 'expense', 'debit', '52', true, null, null, false, ['EUR'], 'Art. 2425 B.8'),
            $n('53', 'Costi per il personale', 'Personnel Costs', 'Personalaufwand', 'Charges de personnel', 'expense', 'debit', 'CE.B', false),
            $n('53.01', 'Salari e stipendi', 'Wages and Salaries', 'Löhne und Gehälter', 'Salaires et traitements', 'expense', 'debit', '53', true, null, null, false, ['EUR'], 'Art. 2425 B.9.a'),
            $n('53.02', 'Oneri sociali', 'Social Security Costs', 'Sozialabgaben', 'Charges sociales', 'expense', 'debit', '53', true, null, null, false, ['EUR'], 'Art. 2425 B.9.b'),
            $n('53.03', 'Accantonamento TFR', 'TFR Accrual', 'Zuführung TFR', 'Dotation TFR', 'expense', 'debit', '53', true, null, null, false, ['EUR'], 'Art. 2425 B.9.c'),
            $n('54', 'Ammortamenti e svalutazioni', 'Depreciation & Write-downs', 'Abschreibungen', 'Amortissements et dépréciations', 'expense', 'debit', 'CE.B', false, 'non_cash_expense'),
            $n('54.01', 'Ammortamento immobilizzazioni immateriali', 'Amortization of Intangibles', 'Abschreibung immaterieller Anlagen', 'Amortissement incorporels', 'expense', 'debit', '54', true, 'non_cash_expense', null, false, ['EUR'], 'Art. 2425 B.10.a'),
            $n('54.02', 'Ammortamento immobilizzazioni materiali', 'Depreciation of Tangibles', 'Abschreibung Sachanlagen', 'Amortissement corporels', 'expense', 'debit', '54', true, 'non_cash_expense', null, false, ['EUR'], 'Art. 2425 B.10.b'),
            $n('54.03', 'Svalutazione crediti', 'Receivable Write-downs', 'Wertberichtigung Forderungen', 'Dépréciation créances', 'expense', 'debit', '54', true, 'non_cash_expense', null, false, ['EUR'], 'Art. 2425 B.10.d'),
            $n('55', 'Oneri diversi di gestione', 'Other Operating Charges', 'Sonstige betriebliche Aufwendungen', 'Autres charges de gestion', 'expense', 'debit', 'CE.B', false),
            $n('55.01', 'Imposte e tasse indirette', 'Indirect Taxes & Duties', 'Indirekte Steuern', 'Impôts et taxes indirects', 'expense', 'debit', '55', true, null, null, false, ['EUR'], 'Art. 2425 B.14'),
            $n('55.02', 'Sopravvenienze passive', 'Contingent Losses', 'Ausserordentliche Aufwendungen', 'Charges sur exercices antérieurs', 'expense', 'debit', '55', true),
            $n('55.03', 'Minusvalenze da alienazione cespiti', 'Losses on Asset Disposals', 'Verluste aus Anlagenabgängen', 'Moins-values de cession', 'expense', 'debit', '55', true),
            $n('CE.C', 'C) Proventi e oneri finanziari', 'C) Financial Income & Charges', 'C) Finanzergebnis', 'C) Produits et charges financiers', 'expense', 'debit', 'CE', false, null, null, false, ['EUR'], 'Art. 2425 C'),
            $n('40.05', 'Proventi finanziari e interessi attivi', 'Financial & Interest Income', 'Zinserträge', 'Produits financiers', 'revenue', 'credit', 'CE.C', true, null, null, false, ['EUR'], 'Art. 2425 C.16'),
            $n('40.06', 'Plusvalenze da alienazione cespiti', 'Gains on Asset Disposals', 'Gewinne aus Anlagenabgängen', 'Plus-values de cession', 'revenue', 'credit', 'CE.A', true, null, null, false, ['EUR'], 'Art. 2425 A.5'),
            $n('56', 'Oneri finanziari', 'Financial Expenses', 'Finanzaufwendungen', 'Charges financières', 'expense', 'debit', 'CE.C', false),
            $n('56.01', 'Interessi passivi bancari', 'Bank Interest Expense', 'Bankzinsen', 'Intérêts bancaires', 'expense', 'debit', '56', true, null, null, false, ['EUR'], 'Art. 2425 C.17'),
            $n('56.02', 'Interessi passivi su mutui', 'Loan Interest Expense', 'Darlehenszinsen', 'Intérêts sur emprunts', 'expense', 'debit', '56', true, null, null, false, ['EUR'], 'Art. 2425 C.17'),
            $n('CE.20', '20) Imposte sul reddito dell\'esercizio', '20) Income Taxes', '20) Ertragsteuern', '20) Impôts sur le résultat', 'expense', 'debit', 'CE', false, null, null, false, ['EUR'], 'Art. 2425 20'),
            $n('57', 'Imposte sul reddito', 'Income Taxes', 'Ertragsteuern', 'Impôts sur le résultat', 'expense', 'debit', 'CE.20', false),
            $n('57.01', 'IRES', 'IRES Corporate Income Tax', 'IRES Körperschaftsteuer', 'IRES Impôt sur les sociétés', 'expense', 'debit', '57', true),
            $n('57.02', 'IRAP', 'IRAP Regional Production Tax', 'IRAP Gewerbesteuer', 'IRAP Taxe régionale', 'expense', 'debit', '57', true),
        ];
    }

    /**
     * Preconfigured Swiss KMU <-> Italian Civil Code/OIC mapping pairs.
     *
     * @return list<array{source_code: string, target_code: string, target_name: string, target_standard: string, mapping_type: string, is_bidirectional: bool, allocation_percentage: string}>
     */
    private static function swissToItalianDefaultMappings(): array
    {
        $pairs = [
            ['1000', '22.02', 'Cassa contanti'],
            ['1020', '22.01', 'Banca c/c ordinario'],
            ['1021', '22.03', 'Conto corrente postale'],
            ['1100', '21.01', 'Crediti verso clienti'],
            ['1109', '21.03', 'Fondo svalutazione crediti'],
            ['1170', '21.05', 'IVA c/acquisti'],
            ['1200', '20.01', 'Merci'],
            ['1210', '20.02', 'Materie prime, sussidiarie e di consumo'],
            ['1230', '20.04', 'Lavori in corso su ordinazione'],
            ['1300', '23', 'Ratei e risconti attivi'],
            ['1400', '12.01', 'Partecipazioni in imprese controllate e collegate'],
            ['1500', '11.02', 'Impianti e macchinari'],
            ['1510', '11.04', 'Mobili e arredi d\'ufficio'],
            ['1520', '11.05', 'Macchine elettroniche e sistemi IT'],
            ['1530', '11.06', 'Automezzi e veicoli aziendali'],
            ['1600', '11.01', 'Terreni e fabbricati'],
            ['1700', '10.02', 'Diritti di brevetto, software e licenze'],
            ['2000', '32.01', 'Debiti verso fornitori'],
            ['2030', '32.11', 'Acconti da clienti'],
            ['2100', '32.03', 'Debiti verso banche a breve'],
            ['2200', '32.06', 'IVA c/vendite'],
            ['2201', '32.05', 'Erario c/IVA a debito'],
            ['2270', '32.08', 'Debiti verso INPS e INAIL'],
            ['2279', '32.07', 'Erario c/ritenute da versare'],
            ['2300', '33', 'Ratei e risconti passivi'],
            ['2450', '32.04', 'Mutui passivi a lungo termine'],
            ['2800', '30.01', 'Capitale sociale'],
            ['2950', '30.02', 'Riserva legale'],
            ['2979', '30.05', 'Utile (perdita) dell\'esercizio'],
            ['3200', '40.01', 'Ricavi delle vendite di merci e prodotti'],
            ['3400', '40.02', 'Ricavi da prestazioni di servizi'],
            ['3600', '40.04', 'Altri ricavi e proventi'],
            ['3800', '40.03', 'Resi, sconti e abbuoni su vendite'],
            ['4000', '50.02', 'Acquisti di materie prime e di consumo'],
            ['4200', '50.01', 'Acquisti di merci'],
            ['5000', '53.01', 'Salari e stipendi'],
            ['5700', '53.02', 'Oneri sociali'],
            ['6000', '52.01', 'Affitti passivi'],
            ['6100', '51.06', 'Manutenzioni e riparazioni'],
            ['6200', '51.09', 'Carburanti e spese auto'],
            ['6300', '51.05', 'Assicurazioni'],
            ['6400', '51.01', 'Energia elettrica, gas e acqua'],
            ['6500', '51.03', 'Consulenze professionali e legali'],
            ['6600', '51.04', 'Spese pubblicitarie e marketing'],
            ['6800', '54.02', 'Ammortamento immobilizzazioni materiali'],
            ['6900', '56.01', 'Interessi passivi bancari'],
            ['6950', '40.05', 'Proventi finanziari e interessi attivi'],
            ['8900', '57.01', 'IRES'],
        ];

        return array_map(static fn (array $pair): array => [
            'source_code' => $pair[0],
            'target_code' => $pair[1],
            'target_name' => $pair[2],
            'target_standard' => ChartTemplateCatalog::ITALIAN_GENERAL,
            'mapping_type' => 'direct',
            'is_bidirectional' => true,
            'allocation_percentage' => '100.0000',
        ], $pairs);
    }

    /**
     * @return list<array{source_code: string, target_code: string, target_name: string, target_standard: string, mapping_type: string, is_bidirectional: bool, allocation_percentage: string}>
     */
    private static function italianToSwissDefaultMappings(): array
    {
        return array_map(static fn (array $m): array => [
            'source_code' => $m['target_code'],
            'target_code' => $m['source_code'],
            'target_name' => $m['source_code'],
            'target_standard' => ChartTemplateCatalog::SWISS_SME,
            'mapping_type' => $m['mapping_type'],
            'is_bidirectional' => true,
            'allocation_percentage' => $m['allocation_percentage'],
        ], self::swissToItalianDefaultMappings());
    }
}
