<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the executive and role-based KPI dashboards (Mod 090): cockpit tabs, filter bar, KPI titles and
 * descriptions, chart tooltips, threshold alerts and the drill-down drawer. Each entry is [it, en, de, fr]; the Italian text
 * serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are written {name}.
 */
final class KpiDashboardsFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'KPI_DASHBOARDS.NAV' => ['Cruscotti KPI', 'KPI dashboards', 'KPI-Dashboards', 'Tableaux de bord KPI'],
            'KPI_DASHBOARDS.TITLE' => ['Cruscotti direzionali', 'Executive dashboards', 'Management-Dashboards', 'Tableaux de bord de direction'],
            'KPI_DASHBOARDS.SUBTITLE' => ['Indicatori in tempo reale per ruolo, con drill-down ai documenti', 'Real-time indicators by role, with drill-down to the documents', 'Echtzeit-Kennzahlen nach Rolle mit Drill-down zu den Belegen', 'Indicateurs en temps réel par rôle, avec accès aux documents'],
            'KPI_DASHBOARDS.LOAD_ERROR' => ['Impossibile caricare il cruscotto', 'The dashboard could not be loaded', 'Das Dashboard konnte nicht geladen werden', 'Le tableau de bord n’a pas pu être chargé'],
            'KPI_DASHBOARDS.FORBIDDEN' => ['Il tuo ruolo non può aprire questo cruscotto', 'Your role may not open this dashboard', 'Ihre Rolle darf dieses Dashboard nicht öffnen', 'Votre rôle ne peut pas ouvrir ce tableau de bord'],
            'KPI_DASHBOARDS.MASKED' => ['Dato riservato', 'Restricted figure', 'Vertrauliche Angabe', 'Donnée confidentielle'],
            'KPI_DASHBOARDS.NO_DATA' => ['Nessun dato per il periodo', 'No data for the period', 'Keine Daten für den Zeitraum', 'Aucune donnée pour la période'],
            'KPI_DASHBOARDS.LAST_UPDATED' => ['Aggiornato {minutes} min fa', 'Last updated {minutes} min ago', 'Vor {minutes} Min. aktualisiert', 'Mis à jour il y a {minutes} min'],
            'KPI_DASHBOARDS.OFFLINE' => ['Dati offline', 'Offline data', 'Offline-Daten', 'Données hors ligne'],

            'KPI_DASHBOARDS.DASHBOARD_CEO' => ['CEO / Direzione', 'CEO / Executive board', 'CEO / Geschäftsleitung', 'CEO / Direction'],
            'KPI_DASHBOARDS.DASHBOARD_CFO' => ['CFO / Finanza e controllo', 'CFO / Finance and controlling', 'CFO / Finanzen und Controlling', 'CFO / Finance et contrôle de gestion'],
            'KPI_DASHBOARDS.DASHBOARD_SALES' => ['Vendite e CRM', 'Sales and CRM', 'Verkauf und CRM', 'Ventes et CRM'],
            'KPI_DASHBOARDS.DASHBOARD_OPERATIONS' => ['Supply chain e operazioni', 'Supply chain and operations', 'Lieferkette und Betrieb', 'Chaîne logistique et opérations'],
            'KPI_DASHBOARDS.DASHBOARD_HR' => ['Risorse umane', 'HR and people', 'Personal', 'Ressources humaines'],
            'KPI_DASHBOARDS.DASHBOARD_CUSTOM' => ['Il mio cruscotto', 'My custom cockpit', 'Mein Dashboard', 'Mon tableau de bord'],

            'KPI_DASHBOARDS.FILTER_ENTITIES' => ['Società', 'Legal entities', 'Gesellschaften', 'Sociétés'],
            'KPI_DASHBOARDS.FILTER_CONSOLIDATED' => ['Gruppo consolidato (elimina intercompany)', 'Consolidated group (eliminate intercompany)', 'Konsolidierte Gruppe (Intercompany eliminieren)', 'Groupe consolidé (éliminer l’intragroupe)'],
            'KPI_DASHBOARDS.FILTER_COST_CENTERS' => ['Centri di costo', 'Cost centers', 'Kostenstellen', 'Centres de coûts'],
            'KPI_DASHBOARDS.FILTER_CURRENCY' => ['Valuta di visualizzazione', 'Display currency', 'Anzeigewährung', 'Devise d’affichage'],
            'KPI_DASHBOARDS.FILTER_PERIOD' => ['Periodo', 'Time horizon', 'Zeithorizont', 'Période'],
            'KPI_DASHBOARDS.PRESET_MTD' => ['Mese corrente', 'MTD', 'Laufender Monat', 'Mois en cours'],
            'KPI_DASHBOARDS.PRESET_QTD' => ['Trimestre corrente', 'QTD', 'Laufendes Quartal', 'Trimestre en cours'],
            'KPI_DASHBOARDS.PRESET_YTD' => ['Anno corrente', 'YTD', 'Laufendes Jahr', 'Année en cours'],
            'KPI_DASHBOARDS.PRESET_LAST_12M' => ['Ultimi 12 mesi', 'Last 12 months', 'Letzte 12 Monate', '12 derniers mois'],
            'KPI_DASHBOARDS.PRESET_CUSTOM' => ['Intervallo personalizzato', 'Custom range', 'Eigener Zeitraum', 'Période personnalisée'],
            'KPI_DASHBOARDS.COMPARE_YOY' => ['vs anno precedente', 'vs. prior year (YoY)', 'vs. Vorjahr', 'vs année précédente'],
            'KPI_DASHBOARDS.COMPARE_MOM' => ['vs periodo precedente', 'vs. prior period (MoM)', 'vs. Vorperiode', 'vs période précédente'],
            'KPI_DASHBOARDS.COMPARE_YTD' => ['vs anno precedente (cumulato)', 'vs. prior year to date', 'vs. Vorjahr kumuliert', 'vs année précédente cumulée'],
            'KPI_DASHBOARDS.COMPARE_BUDGET' => ['vs budget', 'vs. budget', 'vs. Budget', 'vs budget'],
            'KPI_DASHBOARDS.FX_AVERAGE' => ['Cambio medio {from}/{to}: {rate}', 'Average rate {from}/{to}: {rate}', 'Durchschnittskurs {from}/{to}: {rate}', 'Taux moyen {from}/{to} : {rate}'],
            'KPI_DASHBOARDS.FX_CLOSING' => ['Cambio di chiusura {from}/{to}: {rate}', 'Closing rate {from}/{to}: {rate}', 'Stichtagskurs {from}/{to}: {rate}', 'Taux de clôture {from}/{to} : {rate}'],
            'KPI_DASHBOARDS.FX_MISSING' => ['Nessun cambio pubblicato: importi non convertiti', 'No published rate: amounts not converted', 'Kein Kurs veröffentlicht: Beträge nicht umgerechnet', 'Aucun taux publié : montants non convertis'],

            'KPI_DASHBOARDS.EDIT_LAYOUT' => ['Modifica layout', 'Edit layout', 'Layout bearbeiten', 'Modifier la disposition'],
            'KPI_DASHBOARDS.SAVE_LAYOUT' => ['Salva layout', 'Save layout', 'Layout speichern', 'Enregistrer la disposition'],
            'KPI_DASHBOARDS.LAYOUT_SAVED' => ['Layout salvato', 'Layout saved', 'Layout gespeichert', 'Disposition enregistrée'],
            'KPI_DASHBOARDS.LAYOUT_ERROR' => ['Impossibile salvare il layout', 'The layout could not be saved', 'Das Layout konnte nicht gespeichert werden', 'La disposition n’a pas pu être enregistrée'],
            'KPI_DASHBOARDS.ADD_WIDGET' => ['Aggiungi indicatore', 'Add KPI widget', 'Kennzahl hinzufügen', 'Ajouter un indicateur'],
            'KPI_DASHBOARDS.REMOVE_WIDGET' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'KPI_DASHBOARDS.REFRESH' => ['Aggiorna dati', 'Refresh data', 'Daten aktualisieren', 'Actualiser les données'],

            'KPI_DASHBOARDS.DRILL_TITLE' => ['Documenti di origine', 'Source documents', 'Quellbelege', 'Documents sources'],
            'KPI_DASHBOARDS.DRILL_EXPORT' => ['Esporta CSV', 'Export CSV', 'CSV exportieren', 'Exporter en CSV'],
            'KPI_DASHBOARDS.DRILL_OPEN_FULL' => ['Apri vista completa filtrata', 'Open full filtered view', 'Vollständige gefilterte Ansicht öffnen', 'Ouvrir la vue filtrée complète'],
            'KPI_DASHBOARDS.DRILL_EMPTY' => ['Nessun documento per questa selezione', 'No documents for this selection', 'Keine Belege für diese Auswahl', 'Aucun document pour cette sélection'],
            'KPI_DASHBOARDS.DRILL_ERROR' => ['Impossibile caricare i documenti', 'The documents could not be loaded', 'Die Belege konnten nicht geladen werden', 'Les documents n’ont pas pu être chargés'],

            'KPI_DASHBOARDS.ALERTS' => ['Soglie di allarme', 'Threshold alerts', 'Schwellenwert-Alarme', 'Alertes de seuil'],
            'KPI_DASHBOARDS.ALERT_CONFIGURE' => ['Configura soglia', 'Configure threshold', 'Schwellenwert konfigurieren', 'Configurer le seuil'],
            'KPI_DASHBOARDS.ALERT_SAVED' => ['Soglia salvata', 'Threshold saved', 'Schwellenwert gespeichert', 'Seuil enregistré'],
            'KPI_DASHBOARDS.ALERT_WARNING' => ['Attenzione: {kpi} a {value}', 'Warning: {kpi} at {value}', 'Warnung: {kpi} bei {value}', 'Attention : {kpi} à {value}'],
            'KPI_DASHBOARDS.ALERT_CRITICAL' => ['Critico: {kpi} a {value}', 'Critical: {kpi} at {value}', 'Kritisch: {kpi} bei {value}', 'Critique : {kpi} à {value}'],
            'KPI_DASHBOARDS.ALERT_ACKNOWLEDGE' => ['Presa visione', 'Acknowledge', 'Zur Kenntnis genommen', 'Prendre acte'],
            'KPI_DASHBOARDS.ALERT_CHANNEL_IN_APP' => ['Nell’app', 'In-app', 'In der App', 'Dans l’application'],
            'KPI_DASHBOARDS.ALERT_CHANNEL_PUSH' => ['Notifica push', 'Push notification', 'Push-Benachrichtigung', 'Notification push'],
            'KPI_DASHBOARDS.ALERT_CHANNEL_EMAIL' => ['E-mail', 'Email', 'E-Mail', 'E-mail'],

            'KPI_DASHBOARDS.SERIES_ACTUAL' => ['Effettivo', 'Actual', 'Ist', 'Réel'],
            'KPI_DASHBOARDS.SERIES_BUDGET' => ['Budget', 'Budget', 'Budget', 'Budget'],
            'KPI_DASHBOARDS.SERIES_INCREASE' => ['Aumento', 'Increase', 'Zunahme', 'Hausse'],
            'KPI_DASHBOARDS.SERIES_DECREASE' => ['Diminuzione', 'Decrease', 'Abnahme', 'Baisse'],
            'KPI_DASHBOARDS.SERIES_TOTAL' => ['Totale', 'Total', 'Total', 'Total'],
            'KPI_DASHBOARDS.SIDE_RECEIVABLES' => ['Crediti', 'Receivables', 'Forderungen', 'Créances'],
            'KPI_DASHBOARDS.SIDE_PAYABLES' => ['Debiti', 'Payables', 'Verbindlichkeiten', 'Dettes'],
            'KPI_DASHBOARDS.STEP_REVENUE' => ['Ricavi', 'Revenue', 'Umsatz', 'Chiffre d’affaires'],
            'KPI_DASHBOARDS.STEP_COGS' => ['Costo del venduto', 'COGS', 'Warenaufwand', 'Coût des ventes'],
            'KPI_DASHBOARDS.STEP_OPEX' => ['Costi operativi', 'Operating expenses', 'Betriebsaufwand', 'Charges d’exploitation'],
            'KPI_DASHBOARDS.STEP_EBITDA' => ['EBITDA', 'EBITDA', 'EBITDA', 'EBITDA'],
            'KPI_DASHBOARDS.STEP_RECEIVABLES' => ['Crediti', 'Receivables', 'Forderungen', 'Créances'],
            'KPI_DASHBOARDS.STEP_INVENTORY' => ['Magazzino', 'Inventory', 'Lager', 'Stocks'],
            'KPI_DASHBOARDS.STEP_PAYABLES' => ['Debiti fornitori', 'Payables', 'Verbindlichkeiten', 'Dettes fournisseurs'],
            'KPI_DASHBOARDS.STEP_NET_WORKING_CAPITAL' => ['Capitale circolante netto', 'Net working capital', 'Nettoumlaufvermögen', 'BFR net'],
            'KPI_DASHBOARDS.STEP_AVAILABLE_TIME' => ['Tempo disponibile', 'Available time', 'Verfügbare Zeit', 'Temps disponible'],
            'KPI_DASHBOARDS.STEP_AVAILABILITY_LOSS' => ['Perdita di disponibilità', 'Availability loss', 'Verfügbarkeitsverlust', 'Perte de disponibilité'],
            'KPI_DASHBOARDS.STEP_PERFORMANCE_LOSS' => ['Perdita di prestazione', 'Performance loss', 'Leistungsverlust', 'Perte de performance'],
            'KPI_DASHBOARDS.STEP_QUALITY_LOSS' => ['Perdita di qualità', 'Quality loss', 'Qualitätsverlust', 'Perte de qualité'],
            'KPI_DASHBOARDS.STEP_OEE' => ['OEE', 'OEE', 'OEE', 'OEE'],
            'KPI_DASHBOARDS.SEVERITY_WARNING' => ['Attenzione', 'Warning', 'Warnung', 'Attention'],
            'KPI_DASHBOARDS.SEVERITY_CRITICAL' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'KPI_DASHBOARDS.COL_NAME' => ['Nome', 'Name', 'Name', 'Nom'],
            'KPI_DASHBOARDS.COL_REVENUE' => ['Fatturato', 'Revenue', 'Umsatz', 'Chiffre d’affaires'],
            'KPI_DASHBOARDS.COL_MARGIN' => ['Margine', 'Margin', 'Marge', 'Marge'],
            'KPI_DASHBOARDS.FILTER_FROM' => ['Dal', 'From', 'Von', 'Du'],
            'KPI_DASHBOARDS.FILTER_TO' => ['Al', 'To', 'Bis', 'Au'],
            'KPI_DASHBOARDS.FILTER_COMPARISON' => ['Confronto', 'Comparison', 'Vergleich', 'Comparaison'],
            'KPI_DASHBOARDS.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'KPI_DASHBOARDS.RESIZE' => ['Larghezza', 'Width', 'Breite', 'Largeur'],
            'KPI_DASHBOARDS.UNIT_DAYS' => ['giorni', 'days', 'Tage', 'jours'],
            'KPI_DASHBOARDS.UNIT_MONTHS' => ['mesi', 'months', 'Monate', 'mois'],
            'KPI_DASHBOARDS.UNIT_HOURS' => ['ore', 'hours', 'Stunden', 'heures'],
            'KPI_DASHBOARDS.SELF_SUSTAINING' => ['Nessun consumo di cassa', 'Not burning cash', 'Kein Cash-Burn', 'Pas de consommation de trésorerie'],
            'KPI_DASHBOARDS.BUCKET_CURRENT' => ['Non scaduto', 'Current', 'Nicht fällig', 'Non échu'],
            'KPI_DASHBOARDS.BUCKET_1_30' => ['1–30 giorni', '1–30 days', '1–30 Tage', '1–30 jours'],
            'KPI_DASHBOARDS.BUCKET_31_60' => ['31–60 giorni', '31–60 days', '31–60 Tage', '31–60 jours'],
            'KPI_DASHBOARDS.BUCKET_61_90' => ['61–90 giorni', '61–90 days', '61–90 Tage', '61–90 jours'],
            'KPI_DASHBOARDS.BUCKET_OVER_90' => ['Oltre 90 giorni', 'Over 90 days', 'Über 90 Tage', 'Plus de 90 jours'],

            'KPI_DASHBOARDS.KPI_REVENUE_YTD_VS_BUDGET' => ['Ricavi da inizio anno vs budget', 'YTD revenue vs. budget', 'Umsatz YTD vs. Budget', 'Chiffre d’affaires cumulé vs budget'],
            'KPI_DASHBOARDS.KPI_EBITDA' => ['EBITDA e margine EBITDA', 'EBITDA and EBITDA margin', 'EBITDA und EBITDA-Marge', 'EBITDA et marge d’EBITDA'],
            'KPI_DASHBOARDS.KPI_NET_FINANCIAL_POSITION' => ['Posizione finanziaria netta', 'Net financial position', 'Nettofinanzposition', 'Position financière nette'],
            'KPI_DASHBOARDS.KPI_ORDER_BACKLOG' => ['Portafoglio ordini', 'Order backlog', 'Auftragsbestand', 'Carnet de commandes'],
            'KPI_DASHBOARDS.KPI_HEADCOUNT_FTE' => ['Organico (FTE)', 'Headcount (FTE)', 'Personalbestand (FTE)', 'Effectif (ETP)'],
            'KPI_DASHBOARDS.KPI_DSO' => ['DSO – giorni di incasso', 'DSO – days sales outstanding', 'DSO – Debitorenlaufzeit', 'DSO – délai de paiement clients'],
            'KPI_DASHBOARDS.KPI_DPO' => ['DPO – giorni di pagamento', 'DPO – days payable outstanding', 'DPO – Kreditorenlaufzeit', 'DPO – délai de paiement fournisseurs'],
            'KPI_DASHBOARDS.KPI_NET_WORKING_CAPITAL' => ['Capitale circolante netto', 'Net working capital', 'Nettoumlaufvermögen', 'Besoin en fonds de roulement net'],
            'KPI_DASHBOARDS.KPI_CASH_RUNWAY' => ['Autonomia di cassa (mesi)', 'Cash runway (months)', 'Cash-Reichweite (Monate)', 'Autonomie de trésorerie (mois)'],
            'KPI_DASHBOARDS.KPI_AR_AP_AGING' => ['Scadenzario crediti e debiti', 'AR / AP aging schedule', 'Fälligkeitsliste Forderungen / Verbindlichkeiten', 'Échéancier clients / fournisseurs'],
            'KPI_DASHBOARDS.KPI_VAT_LIABILITY' => ['Debito IVA / MWST', 'VAT / MWST liability', 'MWST- / USt-Schuld', 'Dette de TVA'],
            'KPI_DASHBOARDS.KPI_WEIGHTED_PIPELINE' => ['Pipeline ponderata', 'Weighted pipeline value', 'Gewichtete Pipeline', 'Pipeline pondérée'],
            'KPI_DASHBOARDS.KPI_WIN_RATE' => ['Tasso di chiusura vinta', 'Win / close rate', 'Abschlussquote', 'Taux de réussite'],
            'KPI_DASHBOARDS.KPI_GROSS_MARGIN' => ['Margine lordo %', 'Gross margin %', 'Bruttomarge %', 'Marge brute %'],
            'KPI_DASHBOARDS.KPI_TOP_CUSTOMERS' => ['Top 10 clienti', 'Top 10 customers', 'Top 10 Kunden', 'Top 10 clients'],
            'KPI_DASHBOARDS.KPI_TOP_PRODUCTS' => ['Top 10 prodotti', 'Top 10 products', 'Top 10 Produkte', 'Top 10 produits'],
            'KPI_DASHBOARDS.KPI_INVENTORY_TURNOVER' => ['Rotazione di magazzino', 'Inventory turnover', 'Lagerumschlag', 'Rotation des stocks'],
            'KPI_DASHBOARDS.KPI_OTIF' => ['OTIF – consegne puntuali e complete', 'OTIF – on time in full', 'OTIF – pünktlich und vollständig', 'OTIF – à temps et complet'],
            'KPI_DASHBOARDS.KPI_OEE' => ['OEE – efficienza complessiva impianti', 'OEE – overall equipment effectiveness', 'OEE – Gesamtanlageneffektivität', 'OEE – efficacité globale des équipements'],
            'KPI_DASHBOARDS.KPI_SCRAP_RATE' => ['Percentuale di scarti', 'Scrap / defect rate', 'Ausschussquote', 'Taux de rebuts'],
            'KPI_DASHBOARDS.KPI_HEADCOUNT_TURNOVER' => ['Turnover del personale', 'Staff turnover', 'Personalfluktuation', 'Rotation du personnel'],
            'KPI_DASHBOARDS.KPI_ABSENTEEISM' => ['Tasso di assenteismo', 'Absenteeism rate', 'Absenzenquote', 'Taux d’absentéisme'],
            'KPI_DASHBOARDS.KPI_OVERTIME_TREND' => ['Trend straordinari', 'Overtime trend', 'Überstundenverlauf', 'Évolution des heures supplémentaires'],
            'KPI_DASHBOARDS.KPI_BILLABLE_UTILIZATION' => ['Tasso di saturazione fatturabile', 'Billable utilization rate', 'Auslastung (fakturierbar)', 'Taux d’utilisation facturable'],

            'KPI_DASHBOARDS.KPI_REVENUE_YTD_VS_BUDGET_DESC' => ['Ricavi del periodo rispetto al budget approvato', 'Revenue of the period against the approved budget', 'Umsatz der Periode gegenüber dem genehmigten Budget', 'Chiffre d’affaires de la période par rapport au budget approuvé'],
            'KPI_DASHBOARDS.KPI_EBITDA_DESC' => ['Ricavi – costo del venduto – costi operativi, esclusi ammortamenti', 'Revenue – COGS – operating expenses, before depreciation', 'Umsatz – Warenaufwand – Betriebsaufwand, vor Abschreibungen', 'Chiffre d’affaires – coût des ventes – charges d’exploitation, avant amortissements'],
            'KPI_DASHBOARDS.KPI_NET_FINANCIAL_POSITION_DESC' => ['Liquidità meno debiti finanziari alla data finale', 'Liquidity minus financial debt at the end date', 'Liquidität abzüglich Finanzschulden am Stichtag', 'Liquidités moins dettes financières à la date de fin'],
            'KPI_DASHBOARDS.KPI_ORDER_BACKLOG_DESC' => ['Valore delle righe d’ordine confermate non ancora consegnate', 'Value of confirmed order lines not yet delivered', 'Wert der bestätigten, noch nicht gelieferten Auftragspositionen', 'Valeur des lignes de commande confirmées non encore livrées'],
            'KPI_DASHBOARDS.KPI_HEADCOUNT_FTE_DESC' => ['Equivalenti a tempo pieno attivi nelle società selezionate', 'Active full-time equivalents in the selected entities', 'Aktive Vollzeitäquivalente in den gewählten Gesellschaften', 'Équivalents temps plein actifs dans les sociétés sélectionnées'],
            'KPI_DASHBOARDS.KPI_DSO_DESC' => ['Crediti aperti sul fatturato del periodo, in giorni', 'Open receivables over period sales, in days', 'Offene Forderungen im Verhältnis zum Umsatz, in Tagen', 'Créances ouvertes rapportées aux ventes, en jours'],
            'KPI_DASHBOARDS.KPI_DPO_DESC' => ['Debiti aperti sugli acquisti del periodo, in giorni', 'Open payables over period purchases, in days', 'Offene Verbindlichkeiten im Verhältnis zum Einkauf, in Tagen', 'Dettes ouvertes rapportées aux achats, en jours'],
            'KPI_DASHBOARDS.KPI_NET_WORKING_CAPITAL_DESC' => ['Crediti + magazzino – debiti verso fornitori', 'Receivables + inventory – payables', 'Forderungen + Lager – Verbindlichkeiten', 'Créances + stocks – dettes fournisseurs'],
            'KPI_DASHBOARDS.KPI_CASH_RUNWAY_DESC' => ['Liquidità disponibile divisa per il consumo mensile medio', 'Available cash divided by the average monthly burn', 'Verfügbare Liquidität geteilt durch den durchschnittlichen Monatsverbrauch', 'Trésorerie disponible divisée par la consommation mensuelle moyenne'],
            'KPI_DASHBOARDS.KPI_AR_AP_AGING_DESC' => ['Importi aperti per fascia di scaduto', 'Open amounts by days past due', 'Offene Beträge nach Überfälligkeit', 'Montants ouverts par tranche d’échéance'],
            'KPI_DASHBOARDS.KPI_VAT_LIABILITY_DESC' => ['IVA a debito meno IVA a credito, per giurisdizione (ESTV/AFC e Agenzia delle Entrate)', 'Output VAT minus input VAT by jurisdiction (ESTV/AFC and Agenzia delle Entrate)', 'Umsatzsteuer abzüglich Vorsteuer je Jurisdiktion (ESTV/AFC und Agenzia delle Entrate)', 'TVA collectée moins TVA déductible par juridiction (AFC et Agenzia delle Entrate)'],
            'KPI_DASHBOARDS.KPI_WEIGHTED_PIPELINE_DESC' => ['Opportunità aperte ponderate per probabilità, per fase', 'Open opportunities weighted by probability, by stage', 'Offene Chancen nach Wahrscheinlichkeit gewichtet, je Phase', 'Opportunités ouvertes pondérées par la probabilité, par étape'],
            'KPI_DASHBOARDS.KPI_WIN_RATE_DESC' => ['Opportunità vinte su quelle chiuse nel periodo', 'Won opportunities over those closed in the period', 'Gewonnene Chancen im Verhältnis zu den abgeschlossenen', 'Opportunités gagnées sur celles clôturées dans la période'],
            'KPI_DASHBOARDS.KPI_GROSS_MARGIN_DESC' => ['(Ricavi – costo del venduto) / ricavi', '(Revenue – COGS) / revenue', '(Umsatz – Warenaufwand) / Umsatz', '(Chiffre d’affaires – coût des ventes) / chiffre d’affaires'],
            'KPI_DASHBOARDS.KPI_TOP_CUSTOMERS_DESC' => ['Clienti per fatturato, con margine', 'Customers by revenue, with margin', 'Kunden nach Umsatz, mit Marge', 'Clients par chiffre d’affaires, avec marge'],
            'KPI_DASHBOARDS.KPI_TOP_PRODUCTS_DESC' => ['Prodotti per fatturato, con margine', 'Products by revenue, with margin', 'Produkte nach Umsatz, mit Marge', 'Produits par chiffre d’affaires, avec marge'],
            'KPI_DASHBOARDS.KPI_INVENTORY_TURNOVER_DESC' => ['Costo del venduto annualizzato sul magazzino medio', 'Annualized COGS over average inventory', 'Annualisierter Warenaufwand im Verhältnis zum durchschnittlichen Lager', 'Coût des ventes annualisé rapporté au stock moyen'],
            'KPI_DASHBOARDS.KPI_OTIF_DESC' => ['Ordini consegnati completi e entro la data richiesta', 'Orders delivered in full and by the requested date', 'Aufträge vollständig und termingerecht geliefert', 'Commandes livrées complètes et à la date demandée'],
            'KPI_DASHBOARDS.KPI_OEE_DESC' => ['Disponibilità × prestazione × qualità degli ordini di produzione chiusi', 'Availability × performance × quality of the finished production orders', 'Verfügbarkeit × Leistung × Qualität der abgeschlossenen Fertigungsaufträge', 'Disponibilité × performance × qualité des ordres de fabrication terminés'],
            'KPI_DASHBOARDS.KPI_SCRAP_RATE_DESC' => ['Quantità scartata sulla quantità prodotta', 'Scrapped quantity over produced quantity', 'Ausschussmenge im Verhältnis zur Produktionsmenge', 'Quantité mise au rebut rapportée à la quantité produite'],
            'KPI_DASHBOARDS.KPI_HEADCOUNT_TURNOVER_DESC' => ['Uscite del periodo sull’organico medio', 'Leavers of the period over average headcount', 'Austritte der Periode im Verhältnis zum durchschnittlichen Bestand', 'Départs de la période rapportés à l’effectif moyen'],
            'KPI_DASHBOARDS.KPI_ABSENTEEISM_DESC' => ['Ore di malattia e infortunio sulle ore contrattuali', 'Sick and accident hours over contractual hours', 'Krankheits- und Unfallstunden im Verhältnis zu den Sollstunden', 'Heures de maladie et d’accident rapportées aux heures contractuelles'],
            'KPI_DASHBOARDS.KPI_OVERTIME_TREND_DESC' => ['Ore e costo degli straordinari approvati (costo riservato)', 'Approved overtime hours and cost (cost restricted)', 'Genehmigte Überstunden und Kosten (Kosten vertraulich)', 'Heures supplémentaires approuvées et coût (coût confidentiel)'],
            'KPI_DASHBOARDS.KPI_BILLABLE_UTILIZATION_DESC' => ['Ore fatturabili sulle ore totali approvate, per team', 'Billable over total approved hours, by team', 'Fakturierbare im Verhältnis zu den genehmigten Gesamtstunden, je Team', 'Heures facturables rapportées aux heures approuvées, par équipe'],
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
