<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the Executive DSS command center (Mod 093, Issue #214): global filter, balanced scorecard, strategic alerts,
 * variance bridge, profitability matrix with the Whale Curve, what-if simulator and drill-down. Each entry is [it, en, de, fr];
 * the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are written {name}.
 */
final class ExecutiveDssFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'EXEC_DSS.NAV' => ['Cruscotto direzionale DSS', 'Executive DSS', 'Management-Cockpit DSS', 'Cockpit de direction DSS'],
            'EXEC_DSS.TITLE' => ['Cruscotto direzionale e analisi strategica', 'Executive DSS command center', 'Management-Cockpit und strategische Analyse', 'Centre de pilotage de direction et analyse stratégique'],
            'EXEC_DSS.SUBTITLE' => ['Balanced scorecard, redditività, ponte delle varianti e simulazioni what-if su dati consolidati.', 'Balanced scorecard, profitability, variance bridge and what-if simulations on consolidated data.', 'Balanced Scorecard, Rentabilität, Abweichungsbrücke und What-if-Simulationen auf konsolidierten Daten.', 'Balanced scorecard, rentabilité, pont des écarts et simulations what-if sur données consolidées.'],
            'EXEC_DSS.FILTERS' => ['Filtri strategici', 'Strategic filters', 'Strategische Filter', 'Filtres stratégiques'],
            'EXEC_DSS.ALL_ENTITIES' => ['Tutte le società', 'All legal entities', 'Alle Gesellschaften', 'Toutes les entités'],
            'EXEC_DSS.ALL_COST_CENTERS' => ['Tutti i centri di costo', 'All cost centers', 'Alle Kostenstellen', 'Tous les centres de coûts'],
            'EXEC_DSS.FX_AS_OF' => ['Cambio al {date}', 'Rate as of {date}', 'Kurs per {date}', 'Taux au {date}'],
            'EXEC_DSS.PERIOD_MTD' => ['MTD', 'MTD', 'MTD', 'MTD'],
            'EXEC_DSS.PERIOD_QTD' => ['QTD', 'QTD', 'QTD', 'QTD'],
            'EXEC_DSS.PERIOD_YTD' => ['YTD', 'YTD', 'YTD', 'YTD'],
            'EXEC_DSS.PERIOD_L12M' => ['12 mesi', 'L12M', '12 Monate', '12 mois'],
            'EXEC_DSS.PERIOD_CUSTOM' => ['Personalizzato', 'Custom', 'Benutzerdefiniert', 'Personnalisé'],
            'EXEC_DSS.BASELINE_PRIOR_PERIOD' => ['Periodo precedente', 'Prior period', 'Vorperiode', 'Période précédente'],
            'EXEC_DSS.BASELINE_PRIOR_YEAR' => ['Anno precedente', 'Prior year', 'Vorjahr', 'Année précédente'],
            'EXEC_DSS.BASELINE_BUDGET' => ['Budget', 'Budget', 'Budget', 'Budget'],
            'EXEC_DSS.EXPORT_BOARD_PACK' => ['Esporta Board Pack (PDF)', 'Export Board Pack (PDF)', 'Board Pack exportieren (PDF)', 'Exporter le Board Pack (PDF)'],
            'EXEC_DSS.EXPORT_XLSX' => ['Excel', 'Excel', 'Excel', 'Excel'],
            'EXEC_DSS.EXPORT_ERROR' => ['Impossibile generare il Board Pack', 'The Board Pack could not be generated', 'Das Board Pack konnte nicht erstellt werden', 'Le Board Pack n’a pas pu être généré'],
            'EXEC_DSS.EXPORT_DONE' => ['Board Pack generato', 'Board Pack generated', 'Board Pack erstellt', 'Board Pack généré'],
            'EXEC_DSS.LOAD_ERROR' => ['Impossibile caricare il cruscotto', 'The command center could not be loaded', 'Das Cockpit konnte nicht geladen werden', 'Le centre de pilotage n’a pas pu être chargé'],
            'EXEC_DSS.RETRY' => ['Riprova', 'Retry', 'Erneut versuchen', 'Réessayer'],
            'EXEC_DSS.BUDGET_MISSING' => ['Nessun budget approvato per il periodo: il confronto con il budget non è disponibile.', 'There is no approved budget for the period: the comparison with the budget is not available.', 'Für den Zeitraum gibt es kein genehmigtes Budget: der Budgetvergleich ist nicht verfügbar.', 'Aucun budget approuvé pour la période : la comparaison avec le budget n’est pas disponible.'],
            'EXEC_DSS.WHATIF_OPEN' => ['Simulatore what-if', 'What-if simulator', 'What-if-Simulator', 'Simulateur what-if'],
            'EXEC_DSS.WHATIF_TITLE' => ['Simulatore di scenari strategici', 'Strategic scenario simulator', 'Simulator für strategische Szenarien', 'Simulateur de scénarios stratégiques'],
            'EXEC_DSS.GROUP_FINANCIAL' => ['Salute finanziaria', 'Financial health', 'Finanzielle Gesundheit', 'Santé financière'],
            'EXEC_DSS.GROUP_CUSTOMER' => ['Clienti e area commerciale', 'Customer and commercial', 'Kunden und Vertrieb', 'Clients et commercial'],
            'EXEC_DSS.GROUP_EFFICIENCY' => ['Efficienza interna', 'Internal efficiency', 'Interne Effizienz', 'Efficacité interne'],
            'EXEC_DSS.KPI_REVENUE' => ['Ricavi', 'Revenue', 'Umsatz', 'Chiffre d’affaires'],
            'EXEC_DSS.KPI_GROSS_MARGIN_PCT' => ['Margine lordo %', 'Gross margin %', 'Bruttomarge %', 'Marge brute %'],
            'EXEC_DSS.KPI_EBITDA' => ['EBITDA', 'EBITDA', 'EBITDA', 'EBITDA'],
            'EXEC_DSS.KPI_NET_CASH_FLOW' => ['Flusso di cassa netto', 'Net cash flow', 'Netto-Cashflow', 'Flux de trésorerie net'],
            'EXEC_DSS.KPI_CASH_CONVERSION_CYCLE' => ['Ciclo di conversione di cassa (giorni)', 'Cash conversion cycle (days)', 'Cash-Conversion-Cycle (Tage)', 'Cycle de conversion de trésorerie (jours)'],
            'EXEC_DSS.KPI_NET_REVENUE_RETENTION' => ['Net Revenue Retention', 'Net revenue retention', 'Net Revenue Retention', 'Rétention nette du CA'],
            'EXEC_DSS.KPI_CUSTOMER_CONCENTRATION_INDEX' => ['Concentrazione clienti (primo cliente)', 'Customer concentration (top customer)', 'Kundenkonzentration (grösster Kunde)', 'Concentration clients (premier client)'],
            'EXEC_DSS.KPI_QUOTE_WIN_RATE' => ['Tasso di successo offerte', 'Quote win rate', 'Offerten-Erfolgsquote', 'Taux de succès des offres'],
            'EXEC_DSS.KPI_AVERAGE_DSO' => ['DSO medio (giorni)', 'Average DSO (days)', 'Durchschnittliche DSO (Tage)', 'DSO moyen (jours)'],
            'EXEC_DSS.KPI_OPERATING_EXPENSE_RATIO' => ['Incidenza costi operativi', 'Operating expense ratio', 'Betriebsaufwandquote', 'Ratio de charges d’exploitation'],
            'EXEC_DSS.KPI_REVENUE_PER_FTE' => ['Ricavi per FTE', 'Revenue per FTE', 'Umsatz pro FTE', 'CA par ETP'],
            'EXEC_DSS.KPI_INVENTORY_TURNOVER' => ['Rotazione scorte', 'Inventory turnover', 'Lagerumschlag', 'Rotation des stocks'],
            'EXEC_DSS.KPI_ON_TIME_DELIVERY_PCT' => ['Consegne puntuali', 'On-time delivery', 'Termintreue', 'Livraisons à temps'],
            'EXEC_DSS.NO_COMPARISON' => ['Nessun confronto', 'No comparison', 'Kein Vergleich', 'Aucune comparaison'],
            'EXEC_DSS.NOT_AVAILABLE' => ['Dato non ancora disponibile', 'No data source yet', 'Noch keine Datenquelle', 'Pas encore de source de données'],
            'EXEC_DSS.TARGET' => ['Obiettivo raggiunto', 'Target reached', 'Ziel erreicht', 'Objectif atteint'],
            'EXEC_DSS.EMPTY' => ['Nessun dato per il periodo selezionato', 'No data for the selected period', 'Keine Daten für den gewählten Zeitraum', 'Aucune donnée pour la période sélectionnée'],
            'EXEC_DSS.ALERTS_TITLE' => ['Allerte strategiche', 'Strategic alerts', 'Strategische Warnungen', 'Alertes stratégiques'],
            'EXEC_DSS.EXPLORERS' => ['Analisi interattive', 'Interactive analysis', 'Interaktive Analysen', 'Analyses interactives'],
            'EXEC_DSS.SEVERITY_CRITICAL' => ['Critica', 'Critical', 'Kritisch', 'Critique'],
            'EXEC_DSS.SEVERITY_WARNING' => ['Attenzione', 'Warning', 'Warnung', 'Avertissement'],
            'EXEC_DSS.SEVERITY_INFO' => ['Info', 'Info', 'Info', 'Info'],
            'EXEC_DSS.ALERT_MARGIN_EROSION' => ['Erosione del margine lordo', 'Gross margin erosion', 'Erosion der Bruttomarge', 'Érosion de la marge brute'],
            'EXEC_DSS.ALERT_DSO_SPIKE' => ['Aumento dei giorni di incasso (DSO)', 'DSO spike', 'Anstieg der Debitorenlaufzeit (DSO)', 'Hausse du DSO'],
            'EXEC_DSS.ALERT_CUSTOMER_CONCENTRATION' => ['Concentrazione su un singolo cliente', 'Single-customer concentration', 'Konzentration auf einen einzelnen Kunden', 'Concentration sur un seul client'],
            'EXEC_DSS.ALERT_BUDGET_OVERRUN' => ['Sforamento di budget', 'Budget overrun', 'Budgetüberschreitung', 'Dépassement budgétaire'],
            'EXEC_DSS.ALERT_STATISTICAL_OUTLIER' => ['Valore anomalo rispetto alla storia', 'Outlier against history', 'Ausreisser gegenüber der Historie', 'Valeur aberrante par rapport à l’historique'],
            'EXEC_DSS.ALERT_DETAIL' => ['Valore {value}, soglia {threshold}', 'Value {value}, threshold {threshold}', 'Wert {value}, Schwelle {threshold}', 'Valeur {value}, seuil {threshold}'],
            'EXEC_DSS.INSPECT' => ['Analizza', 'Inspect', 'Analysieren', 'Analyser'],
            'EXEC_DSS.NO_ALERTS' => ['Nessuna allerta attiva', 'No active alerts', 'Keine aktiven Warnungen', 'Aucune alerte active'],
            'EXEC_DSS.BRIDGE_TITLE' => ['Ponte delle varianti', 'Variance bridge', 'Abweichungsbrücke', 'Pont des écarts'],
            'EXEC_DSS.BRIDGE_EBITDA' => ['EBITDA', 'EBITDA', 'EBITDA', 'EBITDA'],
            'EXEC_DSS.BRIDGE_REVENUE' => ['Ricavi', 'Revenue', 'Umsatz', 'Chiffre d’affaires'],
            'EXEC_DSS.BRIDGE_INCREASE' => ['Aumento', 'Increase', 'Zunahme', 'Hausse'],
            'EXEC_DSS.BRIDGE_DECREASE' => ['Diminuzione', 'Decrease', 'Abnahme', 'Baisse'],
            'EXEC_DSS.BRIDGE_TOTAL' => ['Totale', 'Total', 'Total', 'Total'],
            'EXEC_DSS.STEP_BASELINE' => ['Base di confronto', 'Baseline', 'Vergleichsbasis', 'Base de comparaison'],
            'EXEC_DSS.STEP_VOLUME' => ['Volume', 'Volume', 'Menge', 'Volume'],
            'EXEC_DSS.STEP_PRICE' => ['Prezzo', 'Price', 'Preis', 'Prix'],
            'EXEC_DSS.STEP_COGS' => ['Costo del venduto', 'COGS', 'Warenaufwand', 'Coût des ventes'],
            'EXEC_DSS.STEP_OPEX' => ['Costi operativi', 'OPEX', 'Betriebsaufwand', 'Charges d’exploitation'],
            'EXEC_DSS.STEP_FX' => ['Cambio', 'FX', 'Währung', 'Change'],
            'EXEC_DSS.STEP_ACTUAL' => ['Consuntivo', 'Actual', 'Ist', 'Réalisé'],
            'EXEC_DSS.VOLUME_FALLBACK' => ['Senza righe di fattura con quantità l’intera variazione dei ricavi è attribuita al prezzo.', 'Without invoice lines carrying quantities the whole revenue change is attributed to price.', 'Ohne Rechnungspositionen mit Mengen wird die gesamte Umsatzveränderung dem Preis zugeordnet.', 'Sans lignes de facture avec quantités, toute la variation du chiffre d’affaires est attribuée au prix.'],
            'EXEC_DSS.PROFIT_TITLE' => ['Matrice di redditività e Whale Curve', 'Profitability matrix and Whale Curve', 'Rentabilitätsmatrix und Whale Curve', 'Matrice de rentabilité et Whale Curve'],
            'EXEC_DSS.DIM_CUSTOMER' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'EXEC_DSS.DIM_PRODUCT' => ['Prodotto', 'Product', 'Produkt', 'Produit'],
            'EXEC_DSS.DIM_CHANNEL' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'EXEC_DSS.DIM_COST_CENTER' => ['Centro di costo', 'Cost center', 'Kostenstelle', 'Centre de coûts'],
            'EXEC_DSS.DIM_LEGAL_ENTITY' => ['Società', 'Legal entity', 'Gesellschaft', 'Entité juridique'],
            'EXEC_DSS.PARETO_CORE' => ['Nucleo 80/20: {count} su {total}', '80/20 core: {count} of {total}', '80/20-Kern: {count} von {total}', 'Noyau 80/20 : {count} sur {total}'],
            'EXEC_DSS.ERODING' => ['{count} in perdita', '{count} loss-making', '{count} verlustbringend', '{count} déficitaires'],
            'EXEC_DSS.WHALE_CURVE' => ['Whale Curve: profitto netto cumulato', 'Whale Curve: cumulative net profit', 'Whale Curve: kumulierter Nettogewinn', 'Whale Curve : profit net cumulé'],
            'EXEC_DSS.PARETO_LINE' => ['Soglia 80/20', '80/20 cut-off', '80/20-Grenze', 'Seuil 80/20'],
            'EXEC_DSS.WHALE_TOTAL' => ['100% del profitto', '100% of profit', '100 % des Gewinns', '100 % du profit'],
            'EXEC_DSS.COL_ENTITY' => ['Entità', 'Entity', 'Einheit', 'Entité'],
            'EXEC_DSS.COL_NET_REVENUE' => ['Ricavi netti', 'Net revenue', 'Nettoerlös', 'CA net'],
            'EXEC_DSS.COL_GROSS_MARGIN' => ['Margine lordo', 'Gross margin', 'Bruttomarge', 'Marge brute'],
            'EXEC_DSS.COL_CM1' => ['Margine di contribuzione I', 'Contribution margin I', 'Deckungsbeitrag I', 'Marge de contribution I'],
            'EXEC_DSS.COL_CM2' => ['Margine di contribuzione II', 'Contribution margin II', 'Deckungsbeitrag II', 'Marge de contribution II'],
            'EXEC_DSS.COL_EBITDA' => ['EBITDA', 'EBITDA', 'EBITDA', 'EBITDA'],
            'EXEC_DSS.COL_NET_PROFIT' => ['Utile operativo netto', 'Net operating profit', 'Betriebsergebnis netto', 'Résultat d’exploitation net'],
            'EXEC_DSS.COL_MARGIN_PCT' => ['Margine netto %', 'Net margin %', 'Nettomarge %', 'Marge nette %'],
            'EXEC_DSS.SIM_PRICE_PCT' => ['Prezzi', 'Price', 'Preise', 'Prix'],
            'EXEC_DSS.SIM_COGS_PCT' => ['Costo del venduto', 'COGS', 'Warenaufwand', 'Coût des ventes'],
            'EXEC_DSS.SIM_OPEX_PCT' => ['Costi operativi', 'OPEX', 'Betriebsaufwand', 'Charges d’exploitation'],
            'EXEC_DSS.SIM_DSO_DAYS_DELTA' => ['Giorni di incasso (DSO)', 'DSO days', 'Debitorenlaufzeit (DSO, Tage)', 'Jours de DSO'],
            'EXEC_DSS.SIM_FX_SHIFT_PCT' => ['Variazione cambio EUR/CHF', 'EUR/CHF shift', 'EUR/CHF-Veränderung', 'Variation du taux EUR/CHF'],
            'EXEC_DSS.SIM_FTE_DELTA' => ['Variazione FTE', 'FTE change', 'FTE-Änderung', 'Variation des ETP'],
            'EXEC_DSS.SIM_EBITDA' => ['EBITDA simulato', 'Simulated EBITDA', 'Simuliertes EBITDA', 'EBITDA simulé'],
            'EXEC_DSS.SIM_NET_CASH_FLOW' => ['Flusso di cassa netto simulato', 'Simulated net cash flow', 'Simulierter Netto-Cashflow', 'Flux de trésorerie net simulé'],
            'EXEC_DSS.SIM_BREAK_EVEN' => ['Ricavi di pareggio', 'Break-even revenue', 'Break-even-Umsatz', 'CA d’équilibre'],
            'EXEC_DSS.SIM_BASELINE' => ['Base', 'Baseline', 'Basis', 'Base'],
            'EXEC_DSS.SIM_NO_BREAK_EVEN' => ['Con questo scenario il margine di contribuzione non è positivo: il pareggio non è raggiungibile.', 'With this scenario the contribution margin is not positive: break-even cannot be reached.', 'In diesem Szenario ist der Deckungsbeitrag nicht positiv: der Break-even ist nicht erreichbar.', 'Avec ce scénario la marge de contribution n’est pas positive : le point mort est inatteignable.'],
            'EXEC_DSS.SIM_HINT' => ['Muovi i cursori per simulare uno scenario sui dati del periodo.', 'Move the sliders to simulate a scenario on the data of the period.', 'Bewegen Sie die Regler, um ein Szenario auf den Daten des Zeitraums zu simulieren.', 'Déplacez les curseurs pour simuler un scénario sur les données de la période.'],
            'EXEC_DSS.SIM_RESET' => ['Azzera scenario', 'Reset scenario', 'Szenario zurücksetzen', 'Réinitialiser le scénario'],
            'EXEC_DSS.METRIC_REVENUE' => ['Ricavi', 'Revenue', 'Umsatz', 'Chiffre d’affaires'],
            'EXEC_DSS.METRIC_COGS' => ['Costo del venduto', 'Cost of goods sold', 'Warenaufwand', 'Coût des ventes'],
            'EXEC_DSS.METRIC_OPEX' => ['Costi operativi', 'Operating expenses', 'Betriebsaufwand', 'Charges d’exploitation'],
            'EXEC_DSS.METRIC_DEPRECIATION' => ['Ammortamenti', 'Depreciation', 'Abschreibungen', 'Amortissements'],
            'EXEC_DSS.METRIC_CASH' => ['Movimenti di cassa', 'Cash movements', 'Geldbewegungen', 'Mouvements de trésorerie'],
            'EXEC_DSS.METRIC_RECEIVABLE' => ['Crediti verso clienti', 'Receivables', 'Forderungen', 'Créances clients'],
            'EXEC_DSS.METRIC_PAYABLE' => ['Debiti verso fornitori', 'Payables', 'Verbindlichkeiten', 'Dettes fournisseurs'],
            'EXEC_DSS.METRIC_INVENTORY' => ['Scorte', 'Inventory', 'Vorräte', 'Stocks'],
            'EXEC_DSS.DRILL_TOTAL' => ['Totale', 'Total', 'Total', 'Total'],
            'EXEC_DSS.DRILL_RECORDS' => ['registrazioni', 'records', 'Buchungen', 'écritures'],
            'EXEC_DSS.DRILL_DATE' => ['Data', 'Date', 'Datum', 'Date'],
            'EXEC_DSS.DRILL_ACCOUNT' => ['Conto', 'Account', 'Konto', 'Compte'],
            'EXEC_DSS.DRILL_COUNTERPARTY' => ['Controparte', 'Counterparty', 'Gegenpartei', 'Contrepartie'],
            'EXEC_DSS.DRILL_AMOUNT' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'EXEC_DSS.DRILL_SOURCE' => ['Documento', 'Source', 'Beleg', 'Document'],
            'EXEC_DSS.DRILL_OPEN_SOURCE' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'EXEC_DSS.DRILL_REPORT_BUILDER' => ['Continua nel Report Builder', 'Continue in the report builder', 'Im Report Builder fortfahren', 'Continuer dans le Report Builder'],
            'EXEC_DSS.DRILL_KPI_DASHBOARDS' => ['Cruscotti KPI', 'KPI dashboards', 'KPI-Dashboards', 'Tableaux de bord KPI'],
            'EXEC_DSS.DRILL_ERROR' => ['Impossibile caricare le registrazioni', 'The records could not be loaded', 'Die Buchungen konnten nicht geladen werden', 'Les écritures n’ont pas pu être chargées'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'de' => 2,
            'fr' => 3,
            default => 1,
        };

        return array_map(static fn (array $values): string => $values[$index], self::definitions());
    }
}
