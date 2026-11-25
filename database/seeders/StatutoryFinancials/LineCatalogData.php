<?php

declare(strict_types=1);

namespace Database\Seeders\StatutoryFinancials;

/**
 * Line catalogue of the statutory statements (Mod 167): Italian balance sheet and income statement (Art. 2424-2425 c.c., with the
 * abbreviated Art. 2435-bis and micro Art. 2435-ter schemes as coarser views of the ordinary one), the Swiss balance sheet and
 * income statements (CO Art. 959a / 959b, both methods) and the cash flow statement (OIC 10 / Swiss GAAP FER 4).
 *
 * Row: [code, parent, sign, side, schemes, formula, cf_group, xbrl element, is_rounding, [it, en, de, fr]].
 * Schemes: T = all schemes, M = ordinary and abbreviated, D = ordinary only. The XBRL element names follow the XBRL Italia
 * PCI taxonomy naming (prefix itcc-ci) and the Swiss package (prefix ch-co); the entry point and the exact elements of the
 * taxonomy release in force are configurable (config/statutory_financials.php) and must be checked before the deposit.
 */
final class LineCatalogData
{
    private const array SCHEMES = ['T' => ['ord', 'abb', 'micro'], 'M' => ['ord', 'abb'], 'D' => ['ord']];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = [];
        $order = 0;

        foreach ([['IT', 'balance_sheet', 'any', self::italianBalanceSheet()], ['IT', 'income_statement', 'any', self::italianIncomeStatement()],
            ['CH', 'balance_sheet', 'any', self::swissBalanceSheet()], ['CH', 'income_statement', 'total_cost', self::swissTotalCost()],
            ['CH', 'income_statement', 'cost_of_sales', self::swissCostOfSales()], ['ALL', 'cash_flow', 'any', self::cashFlow()]] as [$family, $statement, $variant, $lines]) {
            foreach ($lines as $line) {
                [$code, $parent, $sign, $side, $schemes, $formula, $group, $xbrl, $rounding, $labels] = $line;
                $rows[] = [
                    'code' => $code,
                    'family' => $family,
                    'statement' => $statement,
                    'variant' => str_starts_with($code, 'cf.a.') || str_starts_with($code, 'cf.d.') ? self::cashFlowVariant($code) : $variant,
                    'parent_code' => $parent,
                    'sign' => $sign,
                    'side' => $side,
                    'sort_order' => ++$order * 10,
                    'schemes' => $family === 'CH' ? ['ord'] : (self::SCHEMES[$schemes] ?? self::SCHEMES['T']),
                    'formula' => $formula,
                    'cf_group' => $group,
                    'xbrl_element' => $xbrl,
                    'is_rounding' => $rounding,
                    'labels' => ['it' => $labels[0], 'en' => $labels[1], 'de' => $labels[2], 'fr' => $labels[3]],
                ];
            }
        }

        return $rows;
    }

    private static function cashFlowVariant(string $code): string
    {
        return in_array($code, ['cf.a.receipts', 'cf.a.payments'], true) ? 'direct' : 'indirect';
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: string, 4: string, 5: ?array<int, mixed>, 6: ?string, 7: ?string, 8: bool, 9: list<string>}>
     */
    private static function italianBalanceSheet(): array
    {
        $x = 'itcc-ci:';

        return [
            ['it.bs.assets', null, 1, 'asset', 'T', null, null, $x.'TotaleAttivo', false, ['TOTALE ATTIVO', 'TOTAL ASSETS', 'TOTAL AKTIVEN', 'TOTAL DE L\'ACTIF']],
            ['it.bs.a', 'it.bs.assets', 1, 'asset', 'T', null, 'fin_equity', $x.'CreditiVersoSociPerVersamentiAncoraDovuti', false, ['A) Crediti verso soci per versamenti ancora dovuti', 'A) Receivables from shareholders for unpaid contributions', 'A) Ausstehende Einlagen der Gesellschafter', 'A) Créances sur les associés pour apports non versés']],
            ['it.bs.b', 'it.bs.assets', 1, 'asset', 'T', null, 'invest_tangible', $x.'TotaleImmobilizzazioni', false, ['B) Immobilizzazioni', 'B) Fixed assets', 'B) Anlagevermögen', 'B) Immobilisations']],
            ['it.bs.b.i', 'it.bs.b', 1, 'asset', 'M', null, 'invest_intangible', $x.'TotaleImmobilizzazioniImmateriali', false, ['I - Immobilizzazioni immateriali', 'I - Intangible assets', 'I - Immaterielle Anlagen', 'I - Immobilisations incorporelles']],
            ['it.bs.b.i.1', 'it.bs.b.i', 1, 'asset', 'D', null, 'invest_intangible', $x.'CostiImpiantoAmpliamento', false, ['1) Costi di impianto e di ampliamento', '1) Start-up and expansion costs', '1) Gründungs- und Erweiterungskosten', '1) Frais d\'établissement et d\'extension']],
            ['it.bs.b.i.3', 'it.bs.b.i', 1, 'asset', 'D', null, 'invest_intangible', $x.'DirittiBrevettoIndustriale', false, ['3-4) Diritti di brevetto, concessioni, licenze e marchi', '3-4) Patents, concessions, licences and trademarks', '3-4) Patente, Konzessionen, Lizenzen und Marken', '3-4) Brevets, concessions, licences et marques']],
            ['it.bs.b.i.6', 'it.bs.b.i', 1, 'asset', 'D', null, 'invest_intangible', $x.'ImmobilizzazioniImmaterialiInCorso', false, ['6) Immobilizzazioni in corso e acconti', '6) Assets under construction and advances', '6) Anlagen im Bau und Anzahlungen', '6) Immobilisations en cours et acomptes']],
            ['it.bs.b.i.7', 'it.bs.b.i', 1, 'asset', 'D', null, 'invest_intangible', $x.'AltreImmobilizzazioniImmateriali', false, ['7) Altre immobilizzazioni immateriali', '7) Other intangible assets', '7) Übrige immaterielle Anlagen', '7) Autres immobilisations incorporelles']],
            ['it.bs.b.ii', 'it.bs.b', 1, 'asset', 'M', null, 'invest_tangible', $x.'TotaleImmobilizzazioniMateriali', false, ['II - Immobilizzazioni materiali', 'II - Tangible assets', 'II - Sachanlagen', 'II - Immobilisations corporelles']],
            ['it.bs.b.ii.1', 'it.bs.b.ii', 1, 'asset', 'D', null, 'invest_tangible', $x.'TerreniFabbricati', false, ['1) Terreni e fabbricati', '1) Land and buildings', '1) Grundstücke und Gebäude', '1) Terrains et bâtiments']],
            ['it.bs.b.ii.2', 'it.bs.b.ii', 1, 'asset', 'D', null, 'invest_tangible', $x.'ImpiantiMacchinario', false, ['2) Impianti e macchinario', '2) Plant and machinery', '2) Anlagen und Maschinen', '2) Installations et machines']],
            ['it.bs.b.ii.3', 'it.bs.b.ii', 1, 'asset', 'D', null, 'invest_tangible', $x.'AttrezzatureIndustrialiCommerciali', false, ['3) Attrezzature industriali e commerciali', '3) Industrial and commercial equipment', '3) Betriebs- und Geschäftsausstattung', '3) Équipements industriels et commerciaux']],
            ['it.bs.b.ii.4', 'it.bs.b.ii', 1, 'asset', 'D', null, 'invest_tangible', $x.'AltriBeniMateriali', false, ['4-5) Altri beni e immobilizzazioni in corso', '4-5) Other assets and assets under construction', '4-5) Übrige Sachanlagen und Anlagen im Bau', '4-5) Autres biens et immobilisations en cours']],
            ['it.bs.b.iii', 'it.bs.b', 1, 'asset', 'M', null, 'invest_financial', $x.'TotaleImmobilizzazioniFinanziarie', false, ['III - Immobilizzazioni finanziarie', 'III - Financial fixed assets', 'III - Finanzanlagen', 'III - Immobilisations financières']],
            ['it.bs.b.iii.1', 'it.bs.b.iii', 1, 'asset', 'D', null, 'invest_financial', $x.'Partecipazioni', false, ['1) Partecipazioni', '1) Equity investments', '1) Beteiligungen', '1) Participations']],
            ['it.bs.b.iii.2', 'it.bs.b.iii', 1, 'asset', 'D', null, 'invest_financial', $x.'CreditiImmobilizzazioniFinanziarie', false, ['2-3) Crediti e altri titoli', '2-3) Loans and other securities', '2-3) Darlehen und übrige Wertschriften', '2-3) Créances et autres titres']],
            ['it.bs.c', 'it.bs.assets', 1, 'asset', 'T', null, 'wc_other', $x.'TotaleAttivoCircolante', false, ['C) Attivo circolante', 'C) Current assets', 'C) Umlaufvermögen', 'C) Actif circulant']],
            ['it.bs.c.i', 'it.bs.c', 1, 'asset', 'M', null, 'wc_inventory', $x.'TotaleRimanenze', false, ['I - Rimanenze', 'I - Inventories', 'I - Vorräte', 'I - Stocks']],
            ['it.bs.c.ii', 'it.bs.c', 1, 'asset', 'M', null, 'wc_receivables', $x.'TotaleCrediti', false, ['II - Crediti', 'II - Receivables', 'II - Forderungen', 'II - Créances']],
            ['it.bs.c.ii.1', 'it.bs.c.ii', 1, 'asset', 'D', null, 'wc_receivables', $x.'CreditiVersoClienti', false, ['1) Verso clienti', '1) Trade receivables', '1) Forderungen aus Lieferungen und Leistungen', '1) Créances clients']],
            ['it.bs.c.ii.5bis', 'it.bs.c.ii', 1, 'asset', 'D', null, 'wc_other', $x.'CreditiTributari', false, ['5-bis) Crediti tributari', '5-bis) Tax receivables', '5-bis) Steuerforderungen', '5-bis) Créances fiscales']],
            ['it.bs.c.ii.5ter', 'it.bs.c.ii', 1, 'asset', 'D', null, 'wc_other', $x.'ImposteAnticipate', false, ['5-ter) Imposte anticipate', '5-ter) Deferred tax assets', '5-ter) Aktive latente Steuern', '5-ter) Impôts différés actifs']],
            ['it.bs.c.ii.5quater', 'it.bs.c.ii', 1, 'asset', 'D', null, 'wc_other', $x.'CreditiVersoAltri', false, ['5-quater) Verso altri', '5-quater) Other receivables', '5-quater) Übrige Forderungen', '5-quater) Autres créances']],
            ['it.bs.c.iii', 'it.bs.c', 1, 'asset', 'M', null, 'wc_other', $x.'TotaleAttivitaFinanziarieNonImmobilizzate', false, ['III - Attività finanziarie che non costituiscono immobilizzazioni', 'III - Current financial assets', 'III - Kurzfristige Finanzanlagen', 'III - Actifs financiers circulants']],
            ['it.bs.c.iv', 'it.bs.c', 1, 'asset', 'M', null, 'cash', $x.'TotaleDisponibilitaLiquide', false, ['IV - Disponibilità liquide', 'IV - Cash and cash equivalents', 'IV - Flüssige Mittel', 'IV - Disponibilités']],
            ['it.bs.c.iv.1', 'it.bs.c.iv', 1, 'asset', 'D', null, 'cash', $x.'DepositiBancariPostali', false, ['1) Depositi bancari e postali', '1) Bank and postal deposits', '1) Bank- und Postguthaben', '1) Dépôts bancaires et postaux']],
            ['it.bs.c.iv.3', 'it.bs.c.iv', 1, 'asset', 'D', null, 'cash', $x.'DenaroValoriInCassa', false, ['3) Danaro e valori in cassa', '3) Cash on hand', '3) Kassenbestand', '3) Caisse']],
            ['it.bs.d', 'it.bs.assets', 1, 'asset', 'T', null, 'wc_other', $x.'RateiRiscontiAttivi', false, ['D) Ratei e risconti attivi', 'D) Accrued income and prepaid expenses', 'D) Aktive Rechnungsabgrenzung', 'D) Comptes de régularisation actifs']],

            ['it.bs.liabilities', null, 1, 'liability_equity', 'T', null, null, $x.'TotalePassivo', false, ['TOTALE PASSIVO E PATRIMONIO NETTO', 'TOTAL LIABILITIES AND EQUITY', 'TOTAL PASSIVEN', 'TOTAL DU PASSIF']],
            ['it.bs.pn', 'it.bs.liabilities', 1, 'liability_equity', 'T', null, 'fin_equity', $x.'TotalePatrimonioNetto', false, ['A) Patrimonio netto', 'A) Equity', 'A) Eigenkapital', 'A) Capitaux propres']],
            ['it.bs.pn.i', 'it.bs.pn', 1, 'liability_equity', 'T', null, 'fin_equity', $x.'Capitale', false, ['I - Capitale', 'I - Share capital', 'I - Aktienkapital', 'I - Capital social']],
            ['it.bs.pn.ii', 'it.bs.pn', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'RiservaSoprapprezzoAzioni', false, ['II - Riserva da sopraprezzo delle azioni', 'II - Share premium reserve', 'II - Kapitaleinlagereserve', 'II - Prime d\'émission']],
            ['it.bs.pn.iii', 'it.bs.pn', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'RiserveRivalutazione', false, ['III - Riserve di rivalutazione', 'III - Revaluation reserves', 'III - Aufwertungsreserven', 'III - Réserves de réévaluation']],
            ['it.bs.pn.iv', 'it.bs.pn', 1, 'liability_equity', 'T', null, 'fin_equity', $x.'RiservaLegale', false, ['IV - Riserva legale', 'IV - Legal reserve', 'IV - Gesetzliche Reserve', 'IV - Réserve légale']],
            ['it.bs.pn.v', 'it.bs.pn', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'RiserveStatutarie', false, ['V - Riserve statutarie', 'V - Statutory reserves', 'V - Statutarische Reserven', 'V - Réserves statutaires']],
            ['it.bs.pn.vi', 'it.bs.pn', 1, 'liability_equity', 'T', null, 'fin_equity', $x.'AltreRiserve', false, ['VI - Altre riserve', 'VI - Other reserves', 'VI - Übrige Reserven', 'VI - Autres réserves']],
            ['it.bs.pn.round', 'it.bs.pn', 1, 'liability_equity', 'T', null, 'rounding', $x.'RiservaArrotondamentoUnitaEuro', true, ['Riserva da arrotondamento all\'unità di Euro', 'Reserve for rounding to the unit of Euro', 'Rundungsreserve auf ganze Euro', 'Réserve d\'arrondi à l\'unité d\'euro']],
            ['it.bs.pn.viii', 'it.bs.pn', 1, 'liability_equity', 'T', null, 'fin_equity', $x.'UtiliPerditePortatiANuovo', false, ['VIII - Utili (perdite) portati a nuovo', 'VIII - Retained earnings (losses)', 'VIII - Gewinn-/Verlustvortrag', 'VIII - Report à nouveau']],
            ['it.bs.pn.ix', 'it.bs.pn', 1, 'liability_equity', 'T', null, 'profit', $x.'UtilePerditaEsercizio', false, ['IX - Utile (perdita) dell\'esercizio', 'IX - Profit (loss) for the year', 'IX - Jahresgewinn (Jahresverlust)', 'IX - Résultat de l\'exercice']],
            ['it.bs.fr', 'it.bs.liabilities', 1, 'liability_equity', 'T', null, 'provisions', $x.'TotaleFondiRischiOneri', false, ['B) Fondi per rischi e oneri', 'B) Provisions for risks and charges', 'B) Rückstellungen für Risiken und Aufwendungen', 'B) Provisions pour risques et charges']],
            ['it.bs.fr.2', 'it.bs.fr', 1, 'liability_equity', 'D', null, 'provisions', $x.'FondoImposteDifferite', false, ['2) Per imposte, anche differite', '2) For taxes, including deferred taxes', '2) Für Steuern, einschliesslich latenter Steuern', '2) Pour impôts, y compris différés']],
            ['it.bs.fr.4', 'it.bs.fr', 1, 'liability_equity', 'D', null, 'provisions', $x.'AltriFondi', false, ['1-4) Altri fondi per rischi e oneri', '1-4) Other provisions', '1-4) Übrige Rückstellungen', '1-4) Autres provisions']],
            ['it.bs.tfr', 'it.bs.liabilities', 1, 'liability_equity', 'T', null, 'provisions', $x.'TrattamentoFineRapportoLavoroSubordinato', false, ['C) Trattamento di fine rapporto di lavoro subordinato', 'C) Employee severance indemnity (TFR)', 'C) Abfindungsrückstellung (TFR)', 'C) Indemnité de fin de contrat (TFR)']],
            ['it.bs.deb', 'it.bs.liabilities', 1, 'liability_equity', 'T', null, 'wc_payables', $x.'TotaleDebiti', false, ['D) Debiti', 'D) Payables', 'D) Verbindlichkeiten', 'D) Dettes']],
            ['it.bs.deb.4', 'it.bs.deb', 1, 'liability_equity', 'M', null, 'fin_debt', $x.'DebitiVersoBanche', false, ['4) Debiti verso banche', '4) Bank borrowings', '4) Bankverbindlichkeiten', '4) Dettes envers les banques']],
            ['it.bs.deb.5', 'it.bs.deb', 1, 'liability_equity', 'M', null, 'fin_debt', $x.'DebitiVersoAltriFinanziatori', false, ['5) Debiti verso altri finanziatori', '5) Payables to other lenders', '5) Verbindlichkeiten gegenüber übrigen Kreditgebern', '5) Dettes envers d\'autres bailleurs de fonds']],
            ['it.bs.deb.6', 'it.bs.deb', 1, 'liability_equity', 'M', null, 'wc_other', $x.'Acconti', false, ['6) Acconti', '6) Advances received', '6) Erhaltene Anzahlungen', '6) Acomptes reçus']],
            ['it.bs.deb.7', 'it.bs.deb', 1, 'liability_equity', 'M', null, 'wc_payables', $x.'DebitiVersoFornitori', false, ['7) Debiti verso fornitori', '7) Trade payables', '7) Verbindlichkeiten aus Lieferungen und Leistungen', '7) Dettes fournisseurs']],
            ['it.bs.deb.12', 'it.bs.deb', 1, 'liability_equity', 'M', null, 'wc_other', $x.'DebitiTributari', false, ['12) Debiti tributari', '12) Tax payables', '12) Steuerverbindlichkeiten', '12) Dettes fiscales']],
            ['it.bs.deb.13', 'it.bs.deb', 1, 'liability_equity', 'M', null, 'wc_other', $x.'DebitiIstitutiPrevidenza', false, ['13) Debiti verso istituti di previdenza e di sicurezza sociale', '13) Payables to social security institutions', '13) Verbindlichkeiten gegenüber Sozialversicherungen', '13) Dettes envers les organismes de sécurité sociale']],
            ['it.bs.deb.14', 'it.bs.deb', 1, 'liability_equity', 'M', null, 'wc_other', $x.'AltriDebiti', false, ['14) Altri debiti', '14) Other payables', '14) Übrige Verbindlichkeiten', '14) Autres dettes']],
            ['it.bs.rr', 'it.bs.liabilities', 1, 'liability_equity', 'T', null, 'wc_other', $x.'RateiRiscontiPassivi', false, ['E) Ratei e risconti passivi', 'E) Accrued expenses and deferred income', 'E) Passive Rechnungsabgrenzung', 'E) Comptes de régularisation passifs']],
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: string, 4: string, 5: ?array<int, mixed>, 6: ?string, 7: ?string, 8: bool, 9: list<string>}>
     */
    private static function italianIncomeStatement(): array
    {
        $x = 'itcc-ci:';

        return [
            ['it.is.a', null, 1, 'revenue', 'T', null, null, $x.'TotaleValoreProduzione', false, ['A) Valore della produzione', 'A) Value of production', 'A) Produktionswert', 'A) Valeur de la production']],
            ['it.is.a.1', 'it.is.a', 1, 'revenue', 'T', null, null, $x.'RicaviVenditePrestazioni', false, ['1) Ricavi delle vendite e delle prestazioni', '1) Revenue from sales and services', '1) Erlöse aus Lieferungen und Leistungen', '1) Chiffre d\'affaires']],
            ['it.is.a.2', 'it.is.a', 1, 'revenue', 'T', null, null, $x.'VariazioniRimanenzeProdottiInCorsoLavorazione', false, ['2-3) Variazioni delle rimanenze di prodotti e lavori in corso', '2-3) Change in inventories of products and work in progress', '2-3) Bestandesänderungen an Erzeugnissen und angefangenen Arbeiten', '2-3) Variation des stocks de produits et en-cours']],
            ['it.is.a.4', 'it.is.a', 1, 'revenue', 'T', null, null, $x.'IncrementiImmobilizzazioniLavoriInterni', false, ['4) Incrementi di immobilizzazioni per lavori interni', '4) Own work capitalised', '4) Aktivierte Eigenleistungen', '4) Production immobilisée']],
            ['it.is.a.5', 'it.is.a', 1, 'revenue', 'T', null, null, $x.'AltriRicaviProventi', false, ['5) Altri ricavi e proventi', '5) Other income', '5) Übrige Erträge', '5) Autres produits']],
            ['it.is.a.round', 'it.is.a', 1, 'revenue', 'T', null, null, $x.'ArrotondamentiAttivi', true, ['Arrotondamenti attivi', 'Rounding gains', 'Rundungsgewinne', 'Arrondis positifs']],
            ['it.is.b', null, 1, 'cost', 'T', null, null, $x.'TotaleCostiProduzione', false, ['B) Costi della produzione', 'B) Cost of production', 'B) Produktionskosten', 'B) Coûts de production']],
            ['it.is.b.6', 'it.is.b', 1, 'cost', 'T', null, null, $x.'CostiMateriePrimeSussidiarieConsumoMerci', false, ['6) Per materie prime, sussidiarie, di consumo e di merci', '6) Raw materials, consumables and goods', '6) Roh-, Hilfs- und Betriebsstoffe und Waren', '6) Matières premières, consommables et marchandises']],
            ['it.is.b.7', 'it.is.b', 1, 'cost', 'T', null, null, $x.'CostiServizi', false, ['7) Per servizi', '7) Services', '7) Dienstleistungen', '7) Services']],
            ['it.is.b.8', 'it.is.b', 1, 'cost', 'T', null, null, $x.'CostiGodimentoBeniTerzi', false, ['8) Per godimento di beni di terzi', '8) Use of third-party assets', '8) Miete und Leasing', '8) Location de biens de tiers']],
            ['it.is.b.9', 'it.is.b', 1, 'cost', 'T', null, null, $x.'CostiPersonale', false, ['9) Per il personale', '9) Personnel', '9) Personalaufwand', '9) Personnel']],
            ['it.is.b.10', 'it.is.b', 1, 'cost', 'T', null, 'noncash', $x.'AmmortamentiSvalutazioni', false, ['10) Ammortamenti e svalutazioni', '10) Depreciation, amortisation and write-downs', '10) Abschreibungen und Wertberichtigungen', '10) Amortissements et dépréciations']],
            ['it.is.b.11', 'it.is.b', 1, 'cost', 'T', null, null, $x.'VariazioniRimanenzeMaterie', false, ['11) Variazioni delle rimanenze di materie prime e merci', '11) Change in inventories of raw materials and goods', '11) Bestandesänderungen an Material und Waren', '11) Variation des stocks de matières et marchandises']],
            ['it.is.b.12', 'it.is.b', 1, 'cost', 'T', null, null, $x.'AccantonamentiPerRischi', false, ['12-13) Accantonamenti per rischi e altri accantonamenti', '12-13) Provisions for risks and other provisions', '12-13) Rückstellungen für Risiken und übrige Rückstellungen', '12-13) Provisions pour risques et autres provisions']],
            ['it.is.b.14', 'it.is.b', 1, 'cost', 'T', null, null, $x.'OneriDiversiGestione', false, ['14) Oneri diversi di gestione', '14) Other operating charges', '14) Übriger Betriebsaufwand', '14) Charges diverses de gestion']],
            ['it.is.b.round', 'it.is.b', 1, 'cost', 'T', null, null, $x.'ArrotondamentiPassivi', true, ['Arrotondamenti passivi', 'Rounding losses', 'Rundungsverluste', 'Arrondis négatifs']],
            ['it.is.diff', null, 1, 'subtotal', 'T', [['it.is.a', 1], ['it.is.b', -1]], null, $x.'DifferenzaValoreCostiProduzione', false, ['Differenza tra valore e costi della produzione (A - B)', 'Difference between value and cost of production (A - B)', 'Betriebsergebnis (A - B)', 'Différence entre valeur et coûts de production (A - B)']],
            ['it.is.c', null, 1, 'revenue', 'T', null, null, $x.'TotaleProventiOneriFinanziari', false, ['C) Proventi e oneri finanziari', 'C) Financial income and expenses', 'C) Finanzerfolg', 'C) Produits et charges financiers']],
            ['it.is.c.15', 'it.is.c', 1, 'revenue', 'T', null, null, $x.'ProventiPartecipazioni', false, ['15) Proventi da partecipazioni', '15) Income from equity investments', '15) Erträge aus Beteiligungen', '15) Produits de participations']],
            ['it.is.c.16', 'it.is.c', 1, 'revenue', 'T', null, null, $x.'AltriProventiFinanziari', false, ['16) Altri proventi finanziari', '16) Other financial income', '16) Übrige Finanzerträge', '16) Autres produits financiers']],
            ['it.is.c.17', 'it.is.c', -1, 'cost', 'T', null, null, $x.'InteressiAltriOneriFinanziari', false, ['17) Interessi e altri oneri finanziari', '17) Interest and other financial charges', '17) Zinsen und übriger Finanzaufwand', '17) Intérêts et autres charges financières']],
            ['it.is.c.17bis', 'it.is.c', 1, 'revenue', 'T', null, null, $x.'UtiliPerditeSuCambi', false, ['17-bis) Utili e perdite su cambi', '17-bis) Foreign exchange gains and losses', '17-bis) Kursgewinne und Kursverluste', '17-bis) Gains et pertes de change']],
            ['it.is.d', null, 1, 'revenue', 'T', null, null, $x.'TotaleRettificheValoreAttivitaFinanziarie', false, ['D) Rettifiche di valore di attività e passività finanziarie', 'D) Value adjustments of financial assets and liabilities', 'D) Wertberichtigungen auf Finanzanlagen', 'D) Corrections de valeur des actifs et passifs financiers']],
            ['it.is.d.18', 'it.is.d', 1, 'revenue', 'T', null, null, $x.'Rivalutazioni', false, ['18) Rivalutazioni', '18) Revaluations', '18) Aufwertungen', '18) Réévaluations']],
            ['it.is.d.19', 'it.is.d', -1, 'cost', 'T', null, null, $x.'Svalutazioni', false, ['19) Svalutazioni', '19) Write-downs', '19) Abwertungen', '19) Dépréciations']],
            ['it.is.pre', null, 1, 'subtotal', 'T', [['it.is.diff', 1], ['it.is.c', 1], ['it.is.d', 1]], null, $x.'RisultatoPrimaImposte', false, ['Risultato prima delle imposte', 'Result before taxes', 'Ergebnis vor Steuern', 'Résultat avant impôts']],
            ['it.is.tax', null, 1, 'cost', 'T', null, null, $x.'ImposteRedditoEsercizio', false, ['20) Imposte sul reddito dell\'esercizio', '20) Income taxes for the year', '20) Ertragssteuern des Geschäftsjahres', '20) Impôts sur le résultat de l\'exercice']],
            ['it.is.profit', null, 1, 'subtotal', 'T', [['it.is.pre', 1], ['it.is.tax', -1]], null, $x.'UtilePerditaEsercizio', false, ['21) Utile (perdita) dell\'esercizio', '21) Profit (loss) for the year', '21) Jahresgewinn (Jahresverlust)', '21) Résultat de l\'exercice']],
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: string, 4: string, 5: ?array<int, mixed>, 6: ?string, 7: ?string, 8: bool, 9: list<string>}>
     */
    private static function swissBalanceSheet(): array
    {
        $x = 'ch-co:';

        return [
            ['ch.bs.assets', null, 1, 'asset', 'D', null, null, $x.'TotalAssets', false, ['TOTALE ATTIVI', 'TOTAL ASSETS', 'TOTAL AKTIVEN', 'TOTAL DE L\'ACTIF']],
            ['ch.bs.ca', 'ch.bs.assets', 1, 'asset', 'D', null, 'wc_other', $x.'CurrentAssets', false, ['Attivo circolante', 'Current assets', 'Umlaufvermögen', 'Actif circulant']],
            ['ch.bs.cash', 'ch.bs.ca', 1, 'asset', 'D', null, 'cash', $x.'CashAndListedAssets', false, ['Liquidità e attivi detenuti a breve termine con quotazione in borsa', 'Cash and short-term held assets with a stock exchange price', 'Flüssige Mittel und kurzfristig gehaltene Aktiven mit Börsenkurs', 'Liquidités et actifs détenus à court terme cotés en bourse']],
            ['ch.bs.trade_rec', 'ch.bs.ca', 1, 'asset', 'D', null, 'wc_receivables', $x.'TradeReceivables', false, ['Crediti da forniture e prestazioni', 'Trade receivables', 'Forderungen aus Lieferungen und Leistungen', 'Créances résultant de la vente de biens et de prestations de services']],
            ['ch.bs.other_rec', 'ch.bs.ca', 1, 'asset', 'D', null, 'wc_other', $x.'OtherShortTermReceivables', false, ['Altri crediti a breve termine', 'Other short-term receivables', 'Übrige kurzfristige Forderungen', 'Autres créances à court terme']],
            ['ch.bs.inventory', 'ch.bs.ca', 1, 'asset', 'D', null, 'wc_inventory', $x.'InventoriesAndUnbilledServices', false, ['Scorte e prestazioni di servizi non fatturate', 'Inventories and unbilled services', 'Vorräte und nicht fakturierte Dienstleistungen', 'Stocks et prestations de services non facturées']],
            ['ch.bs.accr_assets', 'ch.bs.ca', 1, 'asset', 'D', null, 'wc_other', $x.'PrepaidExpensesAccruedIncome', false, ['Ratei e risconti attivi', 'Prepaid expenses and accrued income', 'Aktive Rechnungsabgrenzungen', 'Actifs de régularisation']],
            ['ch.bs.nca', 'ch.bs.assets', 1, 'asset', 'D', null, 'invest_tangible', $x.'NonCurrentAssets', false, ['Attivo fisso', 'Non-current assets', 'Anlagevermögen', 'Actif immobilisé']],
            ['ch.bs.fin_assets', 'ch.bs.nca', 1, 'asset', 'D', null, 'invest_financial', $x.'FinancialAssets', false, ['Immobilizzazioni finanziarie', 'Financial assets', 'Finanzanlagen', 'Immobilisations financières']],
            ['ch.bs.participations', 'ch.bs.nca', 1, 'asset', 'D', null, 'invest_financial', $x.'Participations', false, ['Partecipazioni', 'Participations', 'Beteiligungen', 'Participations']],
            ['ch.bs.tangible', 'ch.bs.nca', 1, 'asset', 'D', null, 'invest_tangible', $x.'TangibleFixedAssets', false, ['Immobilizzazioni materiali', 'Tangible fixed assets', 'Sachanlagen', 'Immobilisations corporelles']],
            ['ch.bs.intangible', 'ch.bs.nca', 1, 'asset', 'D', null, 'invest_intangible', $x.'IntangibleAssets', false, ['Valori immateriali', 'Intangible assets', 'Immaterielle Werte', 'Valeurs immatérielles']],
            ['ch.bs.unpaid_capital', 'ch.bs.nca', 1, 'asset', 'D', null, 'fin_equity', $x.'UnpaidShareCapital', false, ['Capitale sociale non versato', 'Unpaid share capital', 'Nicht einbezahltes Grundkapital', 'Capital social non libéré']],

            ['ch.bs.liabilities', null, 1, 'liability_equity', 'D', null, null, $x.'TotalLiabilitiesAndEquity', false, ['TOTALE PASSIVI', 'TOTAL LIABILITIES AND EQUITY', 'TOTAL PASSIVEN', 'TOTAL DU PASSIF']],
            ['ch.bs.debt', 'ch.bs.liabilities', 1, 'liability_equity', 'D', null, 'wc_payables', $x.'TotalLiabilities', false, ['Capitale di terzi', 'Liabilities', 'Fremdkapital', 'Capitaux étrangers']],
            ['ch.bs.stl', 'ch.bs.debt', 1, 'liability_equity', 'D', null, 'wc_payables', $x.'ShortTermLiabilities', false, ['Capitale di terzi a breve termine', 'Short-term liabilities', 'Kurzfristiges Fremdkapital', 'Capitaux étrangers à court terme']],
            ['ch.bs.trade_pay', 'ch.bs.stl', 1, 'liability_equity', 'D', null, 'wc_payables', $x.'TradePayables', false, ['Debiti da forniture e prestazioni', 'Trade payables', 'Verbindlichkeiten aus Lieferungen und Leistungen', 'Dettes résultant de l\'achat de biens et de prestations de services']],
            ['ch.bs.st_debt', 'ch.bs.stl', 1, 'liability_equity', 'D', null, 'fin_debt', $x.'ShortTermInterestBearingLiabilities', false, ['Debiti onerosi a breve termine', 'Short-term interest-bearing liabilities', 'Kurzfristige verzinsliche Verbindlichkeiten', 'Dettes à court terme portant intérêt']],
            ['ch.bs.other_st_pay', 'ch.bs.stl', 1, 'liability_equity', 'D', null, 'wc_other', $x.'OtherShortTermLiabilities', false, ['Altri debiti a breve termine', 'Other short-term liabilities', 'Übrige kurzfristige Verbindlichkeiten', 'Autres dettes à court terme']],
            ['ch.bs.st_prov', 'ch.bs.stl', 1, 'liability_equity', 'D', null, 'provisions', $x.'ShortTermProvisions', false, ['Accantonamenti a breve termine', 'Short-term provisions', 'Kurzfristige Rückstellungen', 'Provisions à court terme']],
            ['ch.bs.accr_liab', 'ch.bs.stl', 1, 'liability_equity', 'D', null, 'wc_other', $x.'AccruedExpensesDeferredIncome', false, ['Ratei e risconti passivi', 'Accrued expenses and deferred income', 'Passive Rechnungsabgrenzungen', 'Passifs de régularisation']],
            ['ch.bs.ltl', 'ch.bs.debt', 1, 'liability_equity', 'D', null, 'fin_debt', $x.'LongTermLiabilities', false, ['Capitale di terzi a lungo termine', 'Long-term liabilities', 'Langfristiges Fremdkapital', 'Capitaux étrangers à long terme']],
            ['ch.bs.lt_debt', 'ch.bs.ltl', 1, 'liability_equity', 'D', null, 'fin_debt', $x.'LongTermInterestBearingLiabilities', false, ['Debiti onerosi a lungo termine', 'Long-term interest-bearing liabilities', 'Langfristige verzinsliche Verbindlichkeiten', 'Dettes à long terme portant intérêt']],
            ['ch.bs.other_lt_pay', 'ch.bs.ltl', 1, 'liability_equity', 'D', null, 'fin_debt', $x.'OtherLongTermLiabilities', false, ['Altri debiti a lungo termine', 'Other long-term liabilities', 'Übrige langfristige Verbindlichkeiten', 'Autres dettes à long terme']],
            ['ch.bs.lt_prov', 'ch.bs.ltl', 1, 'liability_equity', 'D', null, 'provisions', $x.'LongTermProvisions', false, ['Accantonamenti a lungo termine e simili', 'Long-term provisions and similar items', 'Langfristige Rückstellungen und ähnliche', 'Provisions à long terme et similaires']],
            ['ch.bs.equity', 'ch.bs.liabilities', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'Equity', false, ['Capitale proprio', 'Equity', 'Eigenkapital', 'Capitaux propres']],
            ['ch.bs.share_capital', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'ShareCapital', false, ['Capitale azionario / capitale sociale', 'Share capital', 'Aktien-/Stammkapital', 'Capital-actions / capital social']],
            ['ch.bs.legal_cap_res', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'LegalCapitalReserve', false, ['Riserva legale da capitale', 'Legal capital reserve', 'Gesetzliche Kapitalreserve', 'Réserve légale issue du capital']],
            ['ch.bs.legal_profit_res', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'LegalProfitReserve', false, ['Riserva legale da utili', 'Legal reserve from profit', 'Gesetzliche Gewinnreserve', 'Réserve légale issue du bénéfice']],
            ['ch.bs.vol_res', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'VoluntaryProfitReserves', false, ['Riserve facoltative da utili', 'Voluntary reserves from profit', 'Freiwillige Gewinnreserven', 'Réserves facultatives issues du bénéfice']],
            ['ch.bs.own_shares', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'TreasuryShares', false, ['Quote di capitale proprie (negativo)', 'Treasury shares (negative)', 'Eigene Kapitalanteile (negativ)', 'Parts de capital propres (négatif)']],
            ['ch.bs.retained', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'fin_equity', $x.'RetainedEarnings', false, ['Utili / perdite riportati', 'Retained earnings (losses carried forward)', 'Gewinnvortrag / Verlustvortrag', 'Bénéfice / perte reporté']],
            ['ch.bs.round_res', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'rounding', $x.'RoundingDifference', true, ['Differenza di arrotondamento', 'Rounding difference', 'Rundungsdifferenz', 'Différence d\'arrondi']],
            ['ch.bs.profit', 'ch.bs.equity', 1, 'liability_equity', 'D', null, 'profit', $x.'AnnualProfitOrLoss', false, ['Utile / perdita dell\'esercizio', 'Annual profit or loss', 'Jahresgewinn / Jahresverlust', 'Bénéfice / perte de l\'exercice']],
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: string, 4: string, 5: ?array<int, mixed>, 6: ?string, 7: ?string, 8: bool, 9: list<string>}>
     */
    private static function swissTotalCost(): array
    {
        $x = 'ch-co:';

        return [
            ['ch.is.tc.rev', null, 1, 'revenue', 'D', null, null, $x.'NetRevenue', false, ['Ricavi netti da forniture e prestazioni', 'Net revenue from sales and services', 'Nettoerlöse aus Lieferungen und Leistungen', 'Produits nets des ventes et des prestations de services']],
            ['ch.is.tc.inv_change', null, 1, 'revenue', 'D', null, null, $x.'ChangeInInventories', false, ['Variazioni delle scorte di prodotti in corso e finiti', 'Change in inventories of work in progress and finished goods', 'Bestandesänderungen an angefangenen und fertigen Arbeiten', 'Variations de stocks de produits en cours et finis']],
            ['ch.is.tc.own_work', null, 1, 'revenue', 'D', null, null, $x.'OwnWorkCapitalised', false, ['Prestazioni proprie attivate', 'Own work capitalised', 'Aktivierte Eigenleistungen', 'Prestations propres activées']],
            ['ch.is.tc.material', null, 1, 'cost', 'D', null, null, $x.'MaterialAndThirdPartyServices', false, ['Costi per materiali e prestazioni di terzi', 'Cost of materials and third-party services', 'Materialaufwand und Aufwand für Drittleistungen', 'Charges de matières et de prestations de tiers']],
            ['ch.is.tc.personnel', null, 1, 'cost', 'D', null, null, $x.'PersonnelExpenses', false, ['Costi del personale', 'Personnel expenses', 'Personalaufwand', 'Charges de personnel']],
            ['ch.is.tc.other_opex', null, 1, 'cost', 'D', null, null, $x.'OtherOperatingExpenses', false, ['Altri costi d\'esercizio', 'Other operating expenses', 'Übriger betrieblicher Aufwand', 'Autres charges d\'exploitation']],
            ['ch.is.tc.ebitda', null, 1, 'subtotal', 'D', [['ch.is.tc.rev', 1], ['ch.is.tc.inv_change', 1], ['ch.is.tc.own_work', 1], ['ch.is.tc.material', -1], ['ch.is.tc.personnel', -1], ['ch.is.tc.other_opex', -1]], null, $x.'Ebitda', false, ['Risultato d\'esercizio prima di ammortamenti (EBITDA)', 'Operating result before depreciation (EBITDA)', 'Betriebsergebnis vor Abschreibungen (EBITDA)', 'Résultat d\'exploitation avant amortissements (EBITDA)']],
            ['ch.is.tc.depr', null, 1, 'cost', 'D', null, 'noncash', $x.'DepreciationAndImpairment', false, ['Ammortamenti e rettifiche di valore dell\'attivo fisso', 'Depreciation and value adjustments on fixed assets', 'Abschreibungen und Wertberichtigungen auf Positionen des Anlagevermögens', 'Amortissements et corrections de valeur sur l\'actif immobilisé']],
            ['ch.is.tc.ebit', null, 1, 'subtotal', 'D', [['ch.is.tc.ebitda', 1], ['ch.is.tc.depr', -1]], null, $x.'Ebit', false, ['Risultato d\'esercizio (EBIT)', 'Operating result (EBIT)', 'Betriebsergebnis (EBIT)', 'Résultat d\'exploitation (EBIT)']],
            ['ch.is.tc.fin_exp', null, 1, 'cost', 'D', null, null, $x.'FinancialExpenses', false, ['Costi finanziari', 'Financial expenses', 'Finanzaufwand', 'Charges financières']],
            ['ch.is.tc.fin_inc', null, 1, 'revenue', 'D', null, null, $x.'FinancialIncome', false, ['Ricavi finanziari', 'Financial income', 'Finanzertrag', 'Produits financiers']],
            ['ch.is.tc.nonop', null, 1, 'revenue', 'D', null, null, $x.'NonOperatingResult', false, ['Risultato estraneo all\'esercizio', 'Non-operating result', 'Betriebsfremder Erfolg', 'Résultat hors exploitation']],
            ['ch.is.tc.extra', null, 1, 'revenue', 'D', null, null, $x.'ExtraordinaryResult', false, ['Risultato straordinario, unico o di altri periodi', 'Extraordinary, one-off or prior-period result', 'Ausserordentlicher, einmaliger oder periodenfremder Erfolg', 'Résultat extraordinaire, unique ou hors période']],
            ['ch.is.tc.round_inc', null, 1, 'revenue', 'D', null, null, $x.'RoundingGains', true, ['Arrotondamenti attivi', 'Rounding gains', 'Rundungsgewinne', 'Arrondis positifs']],
            ['ch.is.tc.round_cost', null, 1, 'cost', 'D', null, null, $x.'RoundingLosses', true, ['Arrotondamenti passivi', 'Rounding losses', 'Rundungsverluste', 'Arrondis négatifs']],
            ['ch.is.tc.pretax', null, 1, 'subtotal', 'D', [['ch.is.tc.ebit', 1], ['ch.is.tc.fin_exp', -1], ['ch.is.tc.fin_inc', 1], ['ch.is.tc.nonop', 1], ['ch.is.tc.extra', 1], ['ch.is.tc.round_inc', 1], ['ch.is.tc.round_cost', -1]], null, $x.'ResultBeforeTaxes', false, ['Risultato prima delle imposte', 'Result before taxes', 'Ergebnis vor Steuern', 'Résultat avant impôts']],
            ['ch.is.tc.tax', null, 1, 'cost', 'D', null, null, $x.'DirectTaxes', false, ['Imposte dirette', 'Direct taxes', 'Direkte Steuern', 'Impôts directs']],
            ['ch.is.tc.profit', null, 1, 'subtotal', 'D', [['ch.is.tc.pretax', 1], ['ch.is.tc.tax', -1]], null, $x.'AnnualProfitOrLoss', false, ['Utile / perdita dell\'esercizio', 'Annual profit or loss', 'Jahresgewinn / Jahresverlust', 'Bénéfice / perte de l\'exercice']],
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: string, 4: string, 5: ?array<int, mixed>, 6: ?string, 7: ?string, 8: bool, 9: list<string>}>
     */
    private static function swissCostOfSales(): array
    {
        $x = 'ch-co:';

        return [
            ['ch.is.cs.rev', null, 1, 'revenue', 'D', null, null, $x.'NetRevenue', false, ['Ricavi netti da forniture e prestazioni', 'Net revenue from sales and services', 'Nettoerlöse aus Lieferungen und Leistungen', 'Produits nets des ventes et des prestations de services']],
            ['ch.is.cs.cogs', null, 1, 'cost', 'D', null, null, $x.'CostOfGoodsAndServicesSold', false, ['Costo dei beni e dei servizi venduti', 'Cost of goods and services sold', 'Aufwand für verkaufte Güter und Leistungen', 'Coût des biens et services vendus']],
            ['ch.is.cs.gross', null, 1, 'subtotal', 'D', [['ch.is.cs.rev', 1], ['ch.is.cs.cogs', -1]], null, $x.'GrossProfit', false, ['Utile lordo', 'Gross profit', 'Bruttoergebnis', 'Résultat brut']],
            ['ch.is.cs.admin_sales', null, 1, 'cost', 'D', null, null, $x.'AdministrativeAndSellingExpenses', false, ['Costi di amministrazione e di vendita', 'Administrative and selling expenses', 'Verwaltungs- und Vertriebsaufwand', 'Charges d\'administration et de vente']],
            ['ch.is.cs.ebit', null, 1, 'subtotal', 'D', [['ch.is.cs.gross', 1], ['ch.is.cs.admin_sales', -1]], null, $x.'Ebit', false, ['Risultato d\'esercizio (EBIT)', 'Operating result (EBIT)', 'Betriebsergebnis (EBIT)', 'Résultat d\'exploitation (EBIT)']],
            ['ch.is.cs.fin_exp', null, 1, 'cost', 'D', null, null, $x.'FinancialExpenses', false, ['Costi finanziari', 'Financial expenses', 'Finanzaufwand', 'Charges financières']],
            ['ch.is.cs.fin_inc', null, 1, 'revenue', 'D', null, null, $x.'FinancialIncome', false, ['Ricavi finanziari', 'Financial income', 'Finanzertrag', 'Produits financiers']],
            ['ch.is.cs.nonop', null, 1, 'revenue', 'D', null, null, $x.'NonOperatingResult', false, ['Risultato estraneo all\'esercizio', 'Non-operating result', 'Betriebsfremder Erfolg', 'Résultat hors exploitation']],
            ['ch.is.cs.extra', null, 1, 'revenue', 'D', null, null, $x.'ExtraordinaryResult', false, ['Risultato straordinario, unico o di altri periodi', 'Extraordinary, one-off or prior-period result', 'Ausserordentlicher, einmaliger oder periodenfremder Erfolg', 'Résultat extraordinaire, unique ou hors période']],
            ['ch.is.cs.round_inc', null, 1, 'revenue', 'D', null, null, $x.'RoundingGains', true, ['Arrotondamenti attivi', 'Rounding gains', 'Rundungsgewinne', 'Arrondis positifs']],
            ['ch.is.cs.round_cost', null, 1, 'cost', 'D', null, null, $x.'RoundingLosses', true, ['Arrotondamenti passivi', 'Rounding losses', 'Rundungsverluste', 'Arrondis négatifs']],
            ['ch.is.cs.pretax', null, 1, 'subtotal', 'D', [['ch.is.cs.ebit', 1], ['ch.is.cs.fin_exp', -1], ['ch.is.cs.fin_inc', 1], ['ch.is.cs.nonop', 1], ['ch.is.cs.extra', 1], ['ch.is.cs.round_inc', 1], ['ch.is.cs.round_cost', -1]], null, $x.'ResultBeforeTaxes', false, ['Risultato prima delle imposte', 'Result before taxes', 'Ergebnis vor Steuern', 'Résultat avant impôts']],
            ['ch.is.cs.tax', null, 1, 'cost', 'D', null, null, $x.'DirectTaxes', false, ['Imposte dirette', 'Direct taxes', 'Direkte Steuern', 'Impôts directs']],
            ['ch.is.cs.profit', null, 1, 'subtotal', 'D', [['ch.is.cs.pretax', 1], ['ch.is.cs.tax', -1]], null, $x.'AnnualProfitOrLoss', false, ['Utile / perdita dell\'esercizio', 'Annual profit or loss', 'Jahresgewinn / Jahresverlust', 'Bénéfice / perte de l\'exercice']],
        ];
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: int, 3: string, 4: string, 5: ?array<int, mixed>, 6: ?string, 7: ?string, 8: bool, 9: list<string>}>
     */
    private static function cashFlow(): array
    {
        $x = 'cf:';

        return [
            ['cf.a', null, 1, 'flow', 'T', null, null, $x.'OperatingCashFlow', false, ['A) Flussi finanziari derivanti dall\'attività operativa', 'A) Cash flows from operating activities', 'A) Geldfluss aus Betriebstätigkeit', 'A) Flux de trésorerie des activités d\'exploitation']],
            ['cf.a.profit', 'cf.a', 1, 'flow', 'T', null, null, $x.'ProfitForTheYear', false, ['Utile (perdita) dell\'esercizio', 'Profit (loss) for the year', 'Jahresgewinn (Jahresverlust)', 'Résultat de l\'exercice']],
            ['cf.a.dep', 'cf.a', 1, 'flow', 'T', null, null, $x.'DepreciationAmortisationAddBack', false, ['Ammortamenti e svalutazioni (rettifica non monetaria)', 'Depreciation and amortisation (non-cash adjustment)', 'Abschreibungen und Wertberichtigungen (nicht liquiditätswirksam)', 'Amortissements et dépréciations (ajustement sans effet de trésorerie)']],
            ['cf.a.prov', 'cf.a', 1, 'flow', 'T', null, null, $x.'ChangeInProvisions', false, ['Variazione dei fondi e del TFR', 'Change in provisions and severance indemnity', 'Veränderung der Rückstellungen', 'Variation des provisions']],
            ['cf.a.inv', 'cf.a', 1, 'flow', 'T', null, null, $x.'ChangeInInventories', false, ['Decremento (incremento) delle rimanenze', 'Decrease (increase) in inventories', 'Abnahme (Zunahme) der Vorräte', 'Diminution (augmentation) des stocks']],
            ['cf.a.rec', 'cf.a', 1, 'flow', 'T', null, null, $x.'ChangeInReceivables', false, ['Decremento (incremento) dei crediti verso clienti', 'Decrease (increase) in trade receivables', 'Abnahme (Zunahme) der Forderungen', 'Diminution (augmentation) des créances clients']],
            ['cf.a.pay', 'cf.a', 1, 'flow', 'T', null, null, $x.'ChangeInPayables', false, ['Incremento (decremento) dei debiti verso fornitori', 'Increase (decrease) in trade payables', 'Zunahme (Abnahme) der Verbindlichkeiten aus Lieferungen und Leistungen', 'Augmentation (diminution) des dettes fournisseurs']],
            ['cf.a.other', 'cf.a', 1, 'flow', 'T', null, null, $x.'ChangeInOtherWorkingCapital', false, ['Altre variazioni del capitale circolante netto', 'Other changes in net working capital', 'Übrige Veränderungen des Nettoumlaufvermögens', 'Autres variations du fonds de roulement net']],
            ['cf.a.receipts', 'cf.a', 1, 'flow', 'T', null, null, $x.'ReceiptsFromCustomers', false, ['Incassi da clienti e altri proventi', 'Receipts from customers and other income', 'Einzahlungen von Kunden und übrige Erträge', 'Encaissements des clients et autres produits']],
            ['cf.a.payments', 'cf.a', 1, 'flow', 'T', null, null, $x.'PaymentsToSuppliersAndPersonnel', false, ['Pagamenti a fornitori, personale e per altri costi operativi', 'Payments to suppliers, personnel and for other operating costs', 'Zahlungen an Lieferanten, Personal und für übrigen Betriebsaufwand', 'Paiements aux fournisseurs, au personnel et pour autres charges d\'exploitation']],
            ['cf.b', null, 1, 'flow', 'T', null, null, $x.'InvestingCashFlow', false, ['B) Flussi finanziari derivanti dall\'attività di investimento', 'B) Cash flows from investing activities', 'B) Geldfluss aus Investitionstätigkeit', 'B) Flux de trésorerie des activités d\'investissement']],
            ['cf.b.tang', 'cf.b', 1, 'flow', 'T', null, null, $x.'InvestmentsInTangibleAssets', false, ['(Investimenti) disinvestimenti in immobilizzazioni materiali', '(Investments in) disposals of tangible assets', '(Investitionen in) Abgänge von Sachanlagen', '(Investissements) désinvestissements en immobilisations corporelles']],
            ['cf.b.intang', 'cf.b', 1, 'flow', 'T', null, null, $x.'InvestmentsInIntangibleAssets', false, ['(Investimenti) disinvestimenti in immobilizzazioni immateriali', '(Investments in) disposals of intangible assets', '(Investitionen in) Abgänge von immateriellen Anlagen', '(Investissements) désinvestissements en immobilisations incorporelles']],
            ['cf.b.fin', 'cf.b', 1, 'flow', 'T', null, null, $x.'InvestmentsInFinancialAssets', false, ['(Investimenti) disinvestimenti in immobilizzazioni finanziarie', '(Investments in) disposals of financial assets', '(Investitionen in) Abgänge von Finanzanlagen', '(Investissements) désinvestissements en immobilisations financières']],
            ['cf.c', null, 1, 'flow', 'T', null, null, $x.'FinancingCashFlow', false, ['C) Flussi finanziari derivanti dall\'attività di finanziamento', 'C) Cash flows from financing activities', 'C) Geldfluss aus Finanzierungstätigkeit', 'C) Flux de trésorerie des activités de financement']],
            ['cf.c.debt', 'cf.c', 1, 'flow', 'T', null, null, $x.'ChangeInFinancialDebt', false, ['Incremento (decremento) dei debiti finanziari', 'Increase (decrease) in financial debt', 'Zunahme (Abnahme) der Finanzverbindlichkeiten', 'Augmentation (diminution) des dettes financières']],
            ['cf.c.equity', 'cf.c', 1, 'flow', 'T', null, null, $x.'ChangeInEquity', false, ['Mezzi propri: aumenti di capitale e dividendi', 'Equity: capital increases and dividends', 'Eigenkapital: Kapitalerhöhungen und Dividenden', 'Capitaux propres: augmentations de capital et dividendes']],
            ['cf.round', null, 1, 'flow', 'T', null, null, $x.'RoundingDifference', true, ['Arrotondamenti', 'Rounding', 'Rundungsdifferenz', 'Arrondis']],
            ['cf.net', null, 1, 'subtotal', 'T', [['cf.a', 1], ['cf.b', 1], ['cf.c', 1], ['cf.round', 1]], null, $x.'NetChangeInCash', false, ['Incremento (decremento) delle disponibilità liquide (A ± B ± C)', 'Net increase (decrease) in cash and cash equivalents (A ± B ± C)', 'Zunahme (Abnahme) der flüssigen Mittel (A ± B ± C)', 'Augmentation (diminution) des disponibilités (A ± B ± C)']],
            ['cf.open', null, 1, 'flow', 'T', null, null, $x.'CashAtBeginningOfPeriod', false, ['Disponibilità liquide all\'inizio dell\'esercizio', 'Cash and cash equivalents at the beginning of the year', 'Flüssige Mittel zu Beginn des Geschäftsjahres', 'Disponibilités au début de l\'exercice']],
            ['cf.close', null, 1, 'subtotal', 'T', [['cf.open', 1], ['cf.net', 1]], null, $x.'CashAtEndOfPeriod', false, ['Disponibilità liquide alla fine dell\'esercizio', 'Cash and cash equivalents at the end of the year', 'Flüssige Mittel am Ende des Geschäftsjahres', 'Disponibilités à la fin de l\'exercice']],
        ];
    }
}
