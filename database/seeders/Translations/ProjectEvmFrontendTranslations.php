<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the project profitability and earned value dashboard (Mod 082) and of its threshold alert messages
 * (stored notifications of the Notification & Alert Engine). Each entry is [it, en, de, fr]; the Italian text serves it-CH
 * and it-IT, the German text de-CH and the French text fr-CH. Placeholders are written {name}.
 */
final class ProjectEvmFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'PROJECT_EVM.NAV' => ['Redditività ed EVM', 'Profitability and EVM', 'Rentabilität und EVM', 'Rentabilité et EVM'],
            'PROJECT_EVM.TITLE' => ['Redditività di commessa ed Earned Value', 'Project profitability and earned value', 'Projektrentabilität und Earned Value', 'Rentabilité de projet et valeur acquise'],
            'PROJECT_EVM.SUBTITLE' => ['Margine in tempo reale, indici CPI/SPI e budget a finire', 'Real-time margin, CPI/SPI indices and budget at completion', 'Marge in Echtzeit, CPI/SPI-Indizes und Budget bei Fertigstellung', 'Marge en temps réel, indices CPI/SPI et budget à l’achèvement'],
            'PROJECT_EVM.PROJECT' => ['Commessa', 'Project', 'Projekt', 'Projet'],
            'PROJECT_EVM.LOAD_ERROR' => ['Impossibile caricare i dati di redditività', 'The profitability data could not be loaded', 'Die Rentabilitätsdaten konnten nicht geladen werden', 'Les données de rentabilité n’ont pas pu être chargées'],
            'PROJECT_EVM.SAVE_ERROR' => ['Operazione non riuscita', 'The operation failed', 'Der Vorgang ist fehlgeschlagen', 'L’opération a échoué'],
            'PROJECT_EVM.EMPTY' => ['Nessuna commessa disponibile', 'No project available', 'Kein Projekt verfügbar', 'Aucun projet disponible'],
            'PROJECT_EVM.NO_SNAPSHOT' => ['Nessuno snapshot: ricalcola per ottenere i valori', 'No snapshot yet: recompute to get the figures', 'Noch kein Snapshot: neu berechnen, um die Werte zu erhalten', 'Aucun instantané : recalculez pour obtenir les valeurs'],
            'PROJECT_EVM.SNAPSHOT_DATE' => ['Snapshot del {date}', 'Snapshot of {date}', 'Snapshot vom {date}', 'Instantané du {date}'],
            'PROJECT_EVM.RECOMPUTE' => ['Ricalcola snapshot', 'Recompute snapshot', 'Snapshot neu berechnen', 'Recalculer l’instantané'],
            'PROJECT_EVM.RECOMPUTED' => ['Snapshot ricalcolato', 'Snapshot recomputed', 'Snapshot neu berechnet', 'Instantané recalculé'],
            'PROJECT_EVM.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'PROJECT_EVM.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],

            'PROJECT_EVM.KPI_BAC' => ['Budget a finire (BAC)', 'Budget at completion (BAC)', 'Budget bei Fertigstellung (BAC)', 'Budget à l’achèvement (BAC)'],
            'PROJECT_EVM.KPI_CPI' => ['Indice di costo (CPI)', 'Cost performance index (CPI)', 'Kostenindex (CPI)', 'Indice de coût (CPI)'],
            'PROJECT_EVM.KPI_SPI' => ['Indice di tempo (SPI)', 'Schedule performance index (SPI)', 'Terminindex (SPI)', 'Indice de délai (SPI)'],
            'PROJECT_EVM.KPI_EAC' => ['Stima a finire (EAC)', 'Estimate at completion (EAC)', 'Prognose bei Fertigstellung (EAC)', 'Estimation à l’achèvement (EAC)'],
            'PROJECT_EVM.KPI_VAC' => ['Scostamento a finire (VAC)', 'Variance at completion (VAC)', 'Abweichung bei Fertigstellung (VAC)', 'Écart à l’achèvement (VAC)'],
            'PROJECT_EVM.KPI_MARGIN' => ['Margine lordo', 'Gross margin', 'Bruttomarge', 'Marge brute'],
            'PROJECT_EVM.KPI_ABSORPTION' => ['Assorbimento del budget', 'Budget absorption', 'Budgetauslastung', 'Absorption du budget'],
            'PROJECT_EVM.LIGHT_GREEN' => ['In linea', 'On track', 'Im Plan', 'Conforme'],
            'PROJECT_EVM.LIGHT_AMBER' => ['Da monitorare', 'Watch', 'Beobachten', 'À surveiller'],
            'PROJECT_EVM.LIGHT_RED' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'PROJECT_EVM.ABSORPTION_WARNING' => ['Oltre l’80% del budget assorbito', 'More than 80% of the budget is absorbed', 'Mehr als 80 % des Budgets sind ausgeschöpft', 'Plus de 80 % du budget est absorbé'],
            'PROJECT_EVM.ALERTS_OK' => ['Nessuna soglia superata', 'No threshold breached', 'Keine Schwelle überschritten', 'Aucun seuil dépassé'],
            'PROJECT_EVM.ALERTS_BREACHED' => ['Soglie superate: {count}', 'Thresholds breached: {count}', 'Überschrittene Schwellen: {count}', 'Seuils dépassés : {count}'],

            'PROJECT_EVM.SCURVE_TITLE' => ['Curva a S', 'S-curve', 'S-Kurve', 'Courbe en S'],
            'PROJECT_EVM.GRANULARITY' => ['Granularità', 'Granularity', 'Granularität', 'Granularité'],
            'PROJECT_EVM.GRAN_DAY' => ['Giorno', 'Day', 'Tag', 'Jour'],
            'PROJECT_EVM.GRAN_WEEK' => ['Settimana', 'Week', 'Woche', 'Semaine'],
            'PROJECT_EVM.GRAN_MONTH' => ['Mese', 'Month', 'Monat', 'Mois'],
            'PROJECT_EVM.SERIES_PV' => ['Valore pianificato (PV)', 'Planned value (PV)', 'Plan-Wert (PV)', 'Valeur planifiée (PV)'],
            'PROJECT_EVM.SERIES_EV' => ['Valore maturato (EV)', 'Earned value (EV)', 'Fertigstellungswert (EV)', 'Valeur acquise (EV)'],
            'PROJECT_EVM.SERIES_AC' => ['Costo effettivo (AC)', 'Actual cost (AC)', 'Istkosten (AC)', 'Coût réel (AC)'],
            'PROJECT_EVM.SERIES_EAC' => ['Previsione EAC', 'EAC forecast', 'EAC-Prognose', 'Prévision EAC'],
            'PROJECT_EVM.SCOPE_PROJECT' => ['Solo questa commessa', 'This project only', 'Nur dieses Projekt', 'Ce projet uniquement'],
            'PROJECT_EVM.SCOPE_PORTFOLIO' => ['Portafoglio', 'Portfolio', 'Portfolio', 'Portefeuille'],

            'PROJECT_EVM.WATERFALL_TITLE' => ['Cascata della redditività', 'Profitability waterfall', 'Rentabilitäts-Wasserfall', 'Cascade de rentabilité'],
            'PROJECT_EVM.STEP_REVENUE' => ['Ricavi riconosciuti', 'Recognised revenue', 'Erfasster Umsatz', 'Produits reconnus'],
            'PROJECT_EVM.STEP_GROSS_MARGIN' => ['Margine lordo', 'Gross margin', 'Bruttomarge', 'Marge brute'],
            'PROJECT_EVM.SRC_TIMESHEETLABOR' => ['Manodopera (fogli ore)', 'Labour (timesheets)', 'Arbeit (Zeiterfassung)', 'Main-d’œuvre (feuilles d’heures)'],
            'PROJECT_EVM.SRC_EMPLOYEEEXPENSE' => ['Note spese', 'Employee expenses', 'Spesen', 'Notes de frais'],
            'PROJECT_EVM.SRC_VENDORINVOICE' => ['Fatture e ricevimenti fornitori', 'Vendor invoices and receipts', 'Lieferantenrechnungen und Wareneingänge', 'Factures et réceptions fournisseurs'],
            'PROJECT_EVM.SRC_WAREHOUSEMATERIALISSUE' => ['Prelievi di magazzino', 'Warehouse issues', 'Lagerentnahmen', 'Sorties de stock'],
            'PROJECT_EVM.SRC_OVERHEADALLOCATION' => ['Costi generali ribaltati', 'Allocated overhead', 'Umgelegte Gemeinkosten', 'Frais généraux répartis'],
            'PROJECT_EVM.SRC_ADJUSTMENT' => ['Rettifiche', 'Adjustments', 'Korrekturen', 'Ajustements'],

            'PROJECT_EVM.WBS_TITLE' => ['Costi per WBS', 'Cost by WBS', 'Kosten je PSP', 'Coûts par WBS'],
            'PROJECT_EVM.COL_WBS' => ['WBS', 'WBS', 'PSP', 'WBS'],
            'PROJECT_EVM.COL_BAC' => ['Budget (BAC)', 'Budget (BAC)', 'Budget (BAC)', 'Budget (BAC)'],
            'PROJECT_EVM.COL_COMMITTED' => ['Impegnato', 'Committed', 'Verpflichtet', 'Engagé'],
            'PROJECT_EVM.COL_ACTUAL' => ['Effettivo', 'Actual', 'Ist', 'Réel'],
            'PROJECT_EVM.COL_EXPOSURE' => ['Esposizione totale', 'Total exposure', 'Gesamtexposition', 'Exposition totale'],
            'PROJECT_EVM.COL_EV' => ['EV', 'EV', 'EV', 'EV'],
            'PROJECT_EVM.COL_PV' => ['PV', 'PV', 'PV', 'PV'],
            'PROJECT_EVM.COL_CV' => ['CV', 'CV', 'CV', 'CV'],
            'PROJECT_EVM.COL_SV' => ['SV', 'SV', 'SV', 'SV'],
            'PROJECT_EVM.COL_CPI' => ['CPI', 'CPI', 'CPI', 'CPI'],
            'PROJECT_EVM.COL_SPI' => ['SPI', 'SPI', 'SPI', 'SPI'],
            'PROJECT_EVM.COL_EAC' => ['EAC', 'EAC', 'EAC', 'EAC'],
            'PROJECT_EVM.COL_ETC' => ['ETC', 'ETC', 'ETC', 'ETC'],
            'PROJECT_EVM.COL_VAC' => ['VAC', 'VAC', 'VAC', 'VAC'],
            'PROJECT_EVM.COL_MARGIN' => ['Margine', 'Margin', 'Marge', 'Marge'],
            'PROJECT_EVM.COL_ACTIONS' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'PROJECT_EVM.PROJECT_TOTAL' => ['Totale commessa', 'Project total', 'Projekttotal', 'Total du projet'],
            'PROJECT_EVM.NO_NODES' => ['Nessun nodo WBS', 'No WBS node', 'Kein PSP-Element', 'Aucun nœud WBS'],

            'PROJECT_EVM.EDIT_ETC' => ['Modifica ETC', 'Edit ETC', 'ETC bearbeiten', 'Modifier l’ETC'],
            'PROJECT_EVM.ETC_TITLE' => ['Stima a finire bottom-up', 'Bottom-up estimate to complete', 'Bottom-up-Restkostenschätzung', 'Estimation à terminaison ascendante'],
            'PROJECT_EVM.ETC_LABEL' => ['ETC manuale', 'Manual ETC', 'Manueller ETC', 'ETC manuel'],
            'PROJECT_EVM.ETC_REASON' => ['Motivazione', 'Reason', 'Begründung', 'Motif'],
            'PROJECT_EVM.ETC_HINT' => ['Con una stima manuale l’EAC diventa AC + ETC.', 'With a manual estimate the EAC becomes AC + ETC.', 'Mit einer manuellen Schätzung wird der EAC zu AC + ETC.', 'Avec une estimation manuelle, l’EAC devient AC + ETC.'],
            'PROJECT_EVM.ETC_RESET' => ['Torna alla formula BAC / CPI', 'Back to the BAC / CPI formula', 'Zurück zur Formel BAC / CPI', 'Retour à la formule BAC / CPI'],
            'PROJECT_EVM.ETC_SAVED' => ['Stima salvata', 'Estimate saved', 'Schätzung gespeichert', 'Estimation enregistrée'],
            'PROJECT_EVM.MANUAL_ETC' => ['ETC manuale', 'Manual ETC', 'Manueller ETC', 'ETC manuel'],

            'PROJECT_EVM.RECON_TITLE' => ['Riconciliazione con la contabilità', 'Reconciliation with accounting', 'Abstimmung mit der Buchhaltung', 'Rapprochement avec la comptabilité'],
            'PROJECT_EVM.RECON_STANDARD' => ['Principio contabile', 'Accounting standard', 'Rechnungslegungsstandard', 'Norme comptable'],
            'PROJECT_EVM.RECON_MANAGERIAL_COST' => ['Costo gestionale di commessa', 'Managerial project cost', 'Managementkosten des Projekts', 'Coût de gestion du projet'],
            'PROJECT_EVM.RECON_STATUTORY_COST' => ['Costo cumulato a bilancio', 'Statutory cumulative cost', 'Kumulierte Kosten laut Abschluss', 'Coût cumulé statutaire'],
            'PROJECT_EVM.RECON_STATUTORY_REVENUE' => ['Ricavi cumulati a bilancio', 'Statutory cumulative revenue', 'Kumulierter Umsatz laut Abschluss', 'Produits cumulés statutaires'],
            'PROJECT_EVM.RECON_DIFFERENCE' => ['Differenza di costo', 'Cost difference', 'Kostendifferenz', 'Écart de coût'],
            'PROJECT_EVM.RECON_OVERHEAD' => ['Costi generali dai centri di costo', 'Overhead from cost centres', 'Gemeinkosten aus Kostenstellen', 'Frais généraux des centres de coûts'],
            'PROJECT_EVM.RECON_OK' => ['Riconciliato', 'Reconciled', 'Abgestimmt', 'Rapproché'],
            'PROJECT_EVM.RECON_GAP' => ['Da riconciliare', 'To reconcile', 'Abzustimmen', 'À rapprocher'],
            'PROJECT_EVM.RECON_NONE' => ['Nessuna valutazione contabile ancora registrata', 'No statutory valuation recorded yet', 'Noch keine Bewertung im Abschluss erfasst', 'Aucune valorisation comptable enregistrée'],

            'PROJECT_EVM.OVERHEAD_TITLE' => ['Ribalta costi generali', 'Allocate overhead', 'Gemeinkosten umlegen', 'Répartir les frais généraux'],
            'PROJECT_EVM.OH_RUN_KEY' => ['Chiave esecuzione', 'Run key', 'Laufschlüssel', 'Clé d’exécution'],
            'PROJECT_EVM.OH_COST_CENTER' => ['Centro di costo (ID)', 'Cost centre (ID)', 'Kostenstelle (ID)', 'Centre de coûts (ID)'],
            'PROJECT_EVM.OH_POOL' => ['Importo del pool', 'Pool amount', 'Pool-Betrag', 'Montant de l’enveloppe'],
            'PROJECT_EVM.OH_PERIOD' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'PROJECT_EVM.OH_DRIVER' => ['Driver di ripartizione', 'Allocation driver', 'Umlageschlüssel', 'Clé de répartition'],
            'PROJECT_EVM.DRIVER_LABOR_HOURS' => ['Ore lavorate', 'Labour hours', 'Arbeitsstunden', 'Heures travaillées'],
            'PROJECT_EVM.DRIVER_DIRECT_COST' => ['Costi diretti', 'Direct cost', 'Direkte Kosten', 'Coûts directs'],
            'PROJECT_EVM.DRIVER_CUSTOM_WEIGHTS' => ['Pesi personalizzati', 'Custom weights', 'Eigene Gewichte', 'Pondérations personnalisées'],
            'PROJECT_EVM.ALLOCATE_OVERHEAD' => ['Ribalta costi generali', 'Allocate overhead', 'Gemeinkosten umlegen', 'Répartir les frais généraux'],
            'PROJECT_EVM.OH_SUBMIT' => ['Ribalta', 'Allocate', 'Umlegen', 'Répartir'],
            'PROJECT_EVM.OH_DONE' => ['Costi generali ribaltati su {count} commesse', 'Overhead allocated to {count} projects', 'Gemeinkosten auf {count} Projekte umgelegt', 'Frais généraux répartis sur {count} projets'],

            'PROJECTS.EVM_ALERT_CPI' => ['{project}: indice di costo {value} sotto la soglia {threshold} ({severity})', '{project}: cost performance index {value} is below the {threshold} threshold ({severity})', '{project}: Kostenindex {value} liegt unter der Schwelle {threshold} ({severity})', '{project} : l’indice de coût {value} est sous le seuil {threshold} ({severity})'],
            'PROJECTS.EVM_ALERT_SPI' => ['{project}: indice di tempo {value} sotto la soglia {threshold} ({severity})', '{project}: schedule performance index {value} is below the {threshold} threshold ({severity})', '{project}: Terminindex {value} liegt unter der Schwelle {threshold} ({severity})', '{project} : l’indice de délai {value} est sous le seuil {threshold} ({severity})'],
            'PROJECTS.EVM_ALERT_ABSORPTION' => ['{project}: budget assorbito al {value}%, oltre la soglia dell’{threshold}% ({severity})', '{project}: {value}% of the budget absorbed, above the {threshold}% threshold ({severity})', '{project}: {value} % des Budgets ausgeschöpft, über der Schwelle von {threshold} % ({severity})', '{project} : {value} % du budget absorbé, au-dessus du seuil de {threshold} % ({severity})'],
            'PROJECTS.EVM_SEVERITY_WARNING' => ['attenzione', 'warning', 'Warnung', 'avertissement'],
            'PROJECTS.EVM_SEVERITY_CRITICAL' => ['critico', 'critical', 'kritisch', 'critique'],
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
