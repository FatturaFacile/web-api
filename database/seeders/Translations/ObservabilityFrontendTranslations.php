<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the observability module (Mod 202, Issue #448): the Performance Profiler & Slow Query Inspector of the platform
 * administrators (slow queries with plan, N+1 and index advice, slow endpoints, PostgreSQL statements, noisy neighbours, thresholds),
 * the status page of the institutional connectors (SDI, STS, Swissdec ELM, ZEFIX, UID-Register, VIES, SNB, ECB, eBill) and the banner
 * of the shell. The codes the API reports (status, connector name, plan status, reason) are keys too: OBSERVABILITY.STATUS.<UPPER>,
 * OBSERVABILITY.CONNECTOR.<UPPER_KEY>, OBSERVABILITY.PLAN_STATUS.<UPPER>, OBSERVABILITY.STATEMENTS.REASON_<UPPER>.
 * Each entry is [it, en, de, fr]; placeholders are {name}. Legal and technical acronyms (SDI, STS, ELM, ZEFIX, UID, VIES, SNB, ECB, SQL,
 * EXPLAIN, N+1, p50/p95/p99) stay untranslated.
 */
final class ObservabilityFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::statuses(),
            self::connectors(),
            self::statusPage(),
            self::banner(),
            self::profiler(),
            self::columns(),
            self::drawer(),
            self::settings(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.OBSERVABILITY' => ['Osservabilità', 'Observability', 'Observability', 'Observabilité'],
            'NAV.CONNECTOR_STATUS' => ['Stato dei connettori istituzionali', 'Institutional connectors status', 'Status der institutionellen Schnittstellen', 'État des connecteurs institutionnels'],
            'NAV.OBSERVABILITY_PROFILER' => ['Profiler prestazioni e query lente', 'Performance profiler and slow queries', 'Performance-Profiler und langsame Abfragen', 'Profileur de performance et requêtes lentes'],
            'PAGE_TITLE.CONNECTOR_STATUS' => ['Stato dei connettori istituzionali', 'Institutional connectors status', 'Status der institutionellen Schnittstellen', 'État des connecteurs institutionnels'],
            'PAGE_TITLE.OBSERVABILITY_PROFILER' => ['Profiler prestazioni e ispettore query lente', 'Performance profiler and slow query inspector', 'Performance-Profiler und Inspektor für langsame Abfragen', 'Profileur de performance et inspecteur de requêtes lentes'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function statuses(): array
    {
        return [
            'OBSERVABILITY.STATUS.OPERATIONAL' => ['Operativo', 'Operational', 'Betriebsbereit', 'Opérationnel'],
            'OBSERVABILITY.STATUS.DEGRADED' => ['Degradato', 'Degraded', 'Eingeschränkt', 'Dégradé'],
            'OBSERVABILITY.STATUS.OUTAGE' => ['Non raggiungibile', 'Unreachable', 'Nicht erreichbar', 'Injoignable'],
            'OBSERVABILITY.STATUS.MAINTENANCE' => ['In manutenzione', 'Under maintenance', 'In Wartung', 'En maintenance'],
            'OBSERVABILITY.STATUS.UNKNOWN' => ['Sconosciuto', 'Unknown', 'Unbekannt', 'Inconnu'],
            'OBSERVABILITY.GROUP.FISCAL' => ['Fiscale', 'Fiscal', 'Steuern', 'Fiscal'],
            'OBSERVABILITY.GROUP.REGISTRY' => ['Registri', 'Registries', 'Register', 'Registres'],
            'OBSERVABILITY.GROUP.RATES' => ['Tassi di cambio', 'Exchange rates', 'Wechselkurse', 'Taux de change'],
            'OBSERVABILITY.GROUP.PAYMENTS' => ['Pagamenti', 'Payments', 'Zahlungen', 'Paiements'],
            'OBSERVABILITY.UNIT.MS' => ['ms', 'ms', 'ms', 'ms'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function connectors(): array
    {
        return [
            'OBSERVABILITY.CONNECTOR.SDI' => ['Agenzia delle Entrate / SDI', 'Agenzia delle Entrate / SDI', 'Agenzia delle Entrate / SDI', 'Agenzia delle Entrate / SDI'],
            'OBSERVABILITY.CONNECTOR.STS' => ['Sistema Tessera Sanitaria (STS)', 'Sistema Tessera Sanitaria (STS)', 'Sistema Tessera Sanitaria (STS)', 'Sistema Tessera Sanitaria (STS)'],
            'OBSERVABILITY.CONNECTOR.SWISSDEC_ELM' => ['Swissdec ELM', 'Swissdec ELM', 'Swissdec ELM', 'Swissdec ELM'],
            'OBSERVABILITY.CONNECTOR.ZEFIX' => ['ZEFIX (registro di commercio svizzero)', 'ZEFIX (Swiss commercial register index)', 'ZEFIX (Zentraler Firmenindex)', 'ZEFIX (index central des raisons de commerce)'],
            'OBSERVABILITY.CONNECTOR.UID_REGISTER' => ['Registro UID (UST)', 'UID register (FSO)', 'UID-Register (BFS)', 'Registre IDE (OFS)'],
            'OBSERVABILITY.CONNECTOR.VIES' => ['VIES (verifica IVA UE)', 'VIES (EU VAT validation)', 'VIES (MwSt-Informationsaustausch EU)', 'VIES (validation de la TVA UE)'],
            'OBSERVABILITY.CONNECTOR.SNB' => ['BNS (tassi di cambio)', 'SNB (exchange rates)', 'SNB (Wechselkurse)', 'BNS (taux de change)'],
            'OBSERVABILITY.CONNECTOR.ECB' => ['BCE (tassi di cambio)', 'ECB (exchange rates)', 'EZB (Wechselkurse)', 'BCE (taux de change)'],
            'OBSERVABILITY.CONNECTOR.EBILL' => ['SIX Paynet / eBill', 'SIX Paynet / eBill', 'SIX Paynet / eBill', 'SIX Paynet / eBill'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function statusPage(): array
    {
        return [
            'OBSERVABILITY.STATUS_PAGE.SUBTITLE' => ['Raggiungibilità, tempi di risposta e disponibilità dei servizi istituzionali di Italia e Svizzera collegati al gestionale.', 'Reachability, response times and availability of the Italian and Swiss institutional services the ERP connects to.', 'Erreichbarkeit, Antwortzeiten und Verfügbarkeit der angebundenen institutionellen Dienste in Italien und der Schweiz.', 'Joignabilité, temps de réponse et disponibilité des services institutionnels italiens et suisses reliés à l’ERP.'],
            'OBSERVABILITY.STATUS_PAGE.OVERALL' => ['Stato generale', 'Overall status', 'Gesamtstatus', 'État général'],
            'OBSERVABILITY.STATUS_PAGE.REFRESH' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'OBSERVABILITY.STATUS_PAGE.PROBE_ALL' => ['Verifica ora tutti', 'Probe all now', 'Alle jetzt prüfen', 'Tout vérifier maintenant'],
            'OBSERVABILITY.STATUS_PAGE.PROBE' => ['Verifica ora', 'Probe now', 'Jetzt prüfen', 'Vérifier maintenant'],
            'OBSERVABILITY.STATUS_PAGE.MAINTENANCE_MESSAGE' => ['Messaggio di manutenzione', 'Maintenance message', 'Wartungsmeldung', 'Message de maintenance'],
            'OBSERVABILITY.STATUS_PAGE.MAINTENANCE_ON' => ['Imposta in manutenzione', 'Set under maintenance', 'In Wartung setzen', 'Mettre en maintenance'],
            'OBSERVABILITY.STATUS_PAGE.MAINTENANCE_OFF' => ['Termina la manutenzione', 'End maintenance', 'Wartung beenden', 'Terminer la maintenance'],
            'OBSERVABILITY.STATUS_PAGE.EMPTY' => ['Nessun connettore monitorato.', 'No connector is monitored.', 'Es wird keine Schnittstelle überwacht.', 'Aucun connecteur n’est surveillé.'],
            'OBSERVABILITY.STATUS_PAGE.LATENCY_LAST' => ['Ultimo tempo di risposta', 'Last response time', 'Letzte Antwortzeit', 'Dernier temps de réponse'],
            'OBSERVABILITY.STATUS_PAGE.LATENCY_AVG' => ['Tempo di risposta medio', 'Average response time', 'Durchschnittliche Antwortzeit', 'Temps de réponse moyen'],
            'OBSERVABILITY.STATUS_PAGE.UPTIME_24H' => ['Disponibilità 24 ore', 'Uptime 24 hours', 'Verfügbarkeit 24 Stunden', 'Disponibilité 24 heures'],
            'OBSERVABILITY.STATUS_PAGE.UPTIME_7D' => ['Disponibilità 7 giorni', 'Uptime 7 days', 'Verfügbarkeit 7 Tage', 'Disponibilité 7 jours'],
            'OBSERVABILITY.STATUS_PAGE.LAST_CHECKED' => ['Ultima verifica', 'Last checked', 'Zuletzt geprüft', 'Dernière vérification'],
            'OBSERVABILITY.STATUS_PAGE.NEVER_CHECKED' => ['Mai verificato', 'Never checked', 'Noch nie geprüft', 'Jamais vérifié'],
            'OBSERVABILITY.STATUS_PAGE.TLS_DAYS' => ['Giorni alla scadenza del certificato TLS', 'Days until the TLS certificate expires', 'Tage bis zum Ablauf des TLS-Zertifikats', 'Jours avant l’expiration du certificat TLS'],
            'OBSERVABILITY.STATUS_PAGE.HISTORY' => ['Ultime 24 ore (una barra per ora)', 'Last 24 hours (one bar per hour)', 'Letzte 24 Stunden (ein Balken pro Stunde)', 'Dernières 24 heures (une barre par heure)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function banner(): array
    {
        return [
            'OBSERVABILITY.BANNER.DISMISS' => ['Nascondi', 'Dismiss', 'Ausblenden', 'Masquer'],
            'OBSERVABILITY.BANNER.LINK' => ['Apri la pagina di stato', 'Open the status page', 'Statusseite öffnen', 'Ouvrir la page d’état'],
            'OBSERVABILITY.BANNER.MESSAGE_DEGRADED' => ['Servizi istituzionali rallentati: {connectors}. L’invio e le verifiche potrebbero richiedere più tempo del solito.', 'Institutional services are slow: {connectors}. Submissions and checks may take longer than usual.', 'Institutionelle Dienste sind verlangsamt: {connectors}. Übermittlungen und Prüfungen können länger dauern.', 'Services institutionnels ralentis : {connectors}. Les envois et les vérifications peuvent prendre plus de temps.'],
            'OBSERVABILITY.BANNER.MESSAGE_OUTAGE' => ['Servizi istituzionali non raggiungibili: {connectors}. Gli invii restano in coda e riprenderanno al ripristino.', 'Institutional services are unreachable: {connectors}. Submissions stay queued and resume once service is restored.', 'Institutionelle Dienste sind nicht erreichbar: {connectors}. Übermittlungen bleiben in der Warteschlange und werden nach der Wiederherstellung fortgesetzt.', 'Services institutionnels injoignables : {connectors}. Les envois restent en file d’attente et reprendront au rétablissement.'],
            'OBSERVABILITY.BANNER.MESSAGE_MAINTENANCE' => ['Manutenzione programmata dei servizi istituzionali: {connectors}.', 'Scheduled maintenance of institutional services: {connectors}.', 'Geplante Wartung institutioneller Dienste: {connectors}.', 'Maintenance planifiée des services institutionnels : {connectors}.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function profiler(): array
    {
        return [
            'OBSERVABILITY.PROFILER.SUBTITLE' => ['Le 20 query SQL e gli endpoint API più lenti delle ultime 24 ore, con piano di esecuzione, rilevamento N+1 e indici suggeriti.', 'The 20 slowest SQL queries and API endpoints of the last 24 hours, with execution plan, N+1 detection and suggested indexes.', 'Die 20 langsamsten SQL-Abfragen und API-Endpunkte der letzten 24 Stunden, mit Ausführungsplan, N+1-Erkennung und Indexvorschlägen.', 'Les 20 requêtes SQL et points d’accès API les plus lents des dernières 24 heures, avec plan d’exécution, détection N+1 et index suggérés.'],
            'OBSERVABILITY.TAB.SLOW_QUERIES' => ['Query SQL lente', 'Slow SQL queries', 'Langsame SQL-Abfragen', 'Requêtes SQL lentes'],
            'OBSERVABILITY.TAB.SLOW_ENDPOINTS' => ['Endpoint lenti', 'Slow endpoints', 'Langsame Endpunkte', 'Points d’accès lents'],
            'OBSERVABILITY.TAB.STATEMENTS' => ['Statistiche PostgreSQL', 'PostgreSQL statements', 'PostgreSQL-Statements', 'Statements PostgreSQL'],
            'OBSERVABILITY.TAB.NOISY' => ['Tenant rumorosi', 'Noisy neighbours', 'Laute Nachbarn', 'Voisins bruyants'],
            'OBSERVABILITY.TAB.SETTINGS' => ['Soglie e impostazioni', 'Thresholds and settings', 'Schwellenwerte und Einstellungen', 'Seuils et paramètres'],
            'OBSERVABILITY.KPI.SLOWEST_QUERY' => ['Query più lenta', 'Slowest query', 'Langsamste Abfrage', 'Requête la plus lente'],
            'OBSERVABILITY.KPI.SLOWEST_P95' => ['p95 peggiore degli endpoint', 'Worst endpoint p95', 'Schlechtester Endpunkt p95', 'Pire p95 des points d’accès'],
            'OBSERVABILITY.KPI.N_PLUS_ONE' => ['Query con pattern N+1', 'Queries with an N+1 pattern', 'Abfragen mit N+1-Muster', 'Requêtes avec un motif N+1'],
            'OBSERVABILITY.KPI.SUGGESTED_INDEXES' => ['Indici suggeriti', 'Suggested indexes', 'Vorgeschlagene Indizes', 'Index suggérés'],
            'OBSERVABILITY.KPI.NOISY_TENANTS' => ['Tenant segnalati', 'Flagged tenants', 'Markierte Mandanten', 'Locataires signalés'],
            'OBSERVABILITY.TAG.N_PLUS_ONE' => ['N+1: {count} query ripetute nella richiesta', 'N+1: {count} repeated queries in one request', 'N+1: {count} wiederholte Abfragen in einer Anfrage', 'N+1 : {count} requêtes répétées dans une requête'],
            'OBSERVABILITY.TAG.N_PLUS_ONE_SHORT' => ['N+1', 'N+1', 'N+1', 'N+1'],
            'OBSERVABILITY.TAG.INDEX' => ['Indice suggerito', 'Index suggested', 'Index vorgeschlagen', 'Index suggéré'],
            'OBSERVABILITY.TAG.NOISY' => ['Rumoroso', 'Noisy', 'Laut', 'Bruyant'],
            'OBSERVABILITY.EMPTY.QUERIES' => ['Nessuna query lenta nelle ultime 24 ore.', 'No slow query in the last 24 hours.', 'Keine langsame Abfrage in den letzten 24 Stunden.', 'Aucune requête lente dans les dernières 24 heures.'],
            'OBSERVABILITY.EMPTY.ENDPOINTS' => ['Nessun dato sugli endpoint nelle ultime 24 ore.', 'No endpoint data in the last 24 hours.', 'Keine Endpunktdaten in den letzten 24 Stunden.', 'Aucune donnée de point d’accès dans les dernières 24 heures.'],
            'OBSERVABILITY.EMPTY.STATEMENTS' => ['Nessuna statistica disponibile.', 'No statistics available.', 'Keine Statistiken verfügbar.', 'Aucune statistique disponible.'],
            'OBSERVABILITY.EMPTY.NOISY' => ['Nessun tenant attivo nell’ultima ora.', 'No tenant was active in the last hour.', 'Kein Mandant war in der letzten Stunde aktiv.', 'Aucun locataire actif dans la dernière heure.'],
            'OBSERVABILITY.STATEMENTS.UNAVAILABLE' => ['Le statistiche pg_stat_statements non sono disponibili', 'The pg_stat_statements statistics are not available', 'Die pg_stat_statements-Statistiken sind nicht verfügbar', 'Les statistiques pg_stat_statements ne sont pas disponibles'],
            'OBSERVABILITY.STATEMENTS.REASON_NOT_POSTGRES' => ['Il database in uso non è PostgreSQL: le statistiche sono disponibili solo in produzione e in CI.', 'The database in use is not PostgreSQL: the statistics exist only in production and CI.', 'Die verwendete Datenbank ist nicht PostgreSQL: Die Statistiken gibt es nur in Produktion und CI.', 'La base de données utilisée n’est pas PostgreSQL : les statistiques n’existent qu’en production et en CI.'],
            'OBSERVABILITY.STATEMENTS.REASON_EXTENSION_MISSING' => ['L’estensione pg_stat_statements non è abilitata: aggiungerla a shared_preload_libraries ed eseguire CREATE EXTENSION pg_stat_statements.', 'The pg_stat_statements extension is not enabled: add it to shared_preload_libraries and run CREATE EXTENSION pg_stat_statements.', 'Die Erweiterung pg_stat_statements ist nicht aktiviert: in shared_preload_libraries eintragen und CREATE EXTENSION pg_stat_statements ausführen.', 'L’extension pg_stat_statements n’est pas activée : l’ajouter à shared_preload_libraries et exécuter CREATE EXTENSION pg_stat_statements.'],
            'OBSERVABILITY.STATEMENTS.REASON_PERMISSION_DENIED' => ['L’utente del database non può leggere pg_stat_statements: concedere il ruolo pg_read_all_stats.', 'The database user cannot read pg_stat_statements: grant the pg_read_all_stats role.', 'Der Datenbankbenutzer darf pg_stat_statements nicht lesen: Rolle pg_read_all_stats zuweisen.', 'L’utilisateur de la base ne peut pas lire pg_stat_statements : lui accorder le rôle pg_read_all_stats.'],
            'OBSERVABILITY.STATEMENTS.REASON_UNKNOWN' => ['Motivo non indicato dal server.', 'The server gave no reason.', 'Der Server hat keinen Grund angegeben.', 'Le serveur n’a indiqué aucun motif.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function columns(): array
    {
        return [
            'OBSERVABILITY.COL.QUERY' => ['Query', 'Query', 'Abfrage', 'Requête'],
            'OBSERVABILITY.COL.AVG_MS' => ['Media (ms)', 'Average (ms)', 'Durchschnitt (ms)', 'Moyenne (ms)'],
            'OBSERVABILITY.COL.MAX_MS' => ['Massimo (ms)', 'Maximum (ms)', 'Maximum (ms)', 'Maximum (ms)'],
            'OBSERVABILITY.COL.EXECUTIONS' => ['Esecuzioni', 'Executions', 'Ausführungen', 'Exécutions'],
            'OBSERVABILITY.COL.ORIGIN' => ['Origine (rotta o job)', 'Origin (route or job)', 'Ursprung (Route oder Job)', 'Origine (route ou job)'],
            'OBSERVABILITY.COL.FLAGS' => ['Segnalazioni', 'Flags', 'Markierungen', 'Signalements'],
            'OBSERVABILITY.COL.LAST_SEEN' => ['Ultima rilevazione', 'Last seen', 'Zuletzt erfasst', 'Dernière détection'],
            'OBSERVABILITY.COL.ACTIONS' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'OBSERVABILITY.COL.ENDPOINT' => ['Endpoint', 'Endpoint', 'Endpunkt', 'Point d’accès'],
            'OBSERVABILITY.COL.REQUESTS' => ['Richieste', 'Requests', 'Anfragen', 'Requêtes'],
            'OBSERVABILITY.COL.P50' => ['p50 (ms)', 'p50 (ms)', 'p50 (ms)', 'p50 (ms)'],
            'OBSERVABILITY.COL.P95' => ['p95 (ms)', 'p95 (ms)', 'p95 (ms)', 'p95 (ms)'],
            'OBSERVABILITY.COL.P99' => ['p99 (ms)', 'p99 (ms)', 'p99 (ms)', 'p99 (ms)'],
            'OBSERVABILITY.COL.AVG_DB_QUERIES' => ['Query DB per richiesta', 'DB queries per request', 'DB-Abfragen pro Anfrage', 'Requêtes BD par requête'],
            'OBSERVABILITY.COL.AVG_DB_MS' => ['Tempo DB medio (ms)', 'Average DB time (ms)', 'Durchschnittliche DB-Zeit (ms)', 'Temps BD moyen (ms)'],
            'OBSERVABILITY.COL.ERROR_RATE' => ['Tasso di errore', 'Error rate', 'Fehlerrate', 'Taux d’erreur'],
            'OBSERVABILITY.COL.TRACE_ID' => ['ID di traccia', 'Trace ID', 'Trace-ID', 'ID de trace'],
            'OBSERVABILITY.COL.CALLS' => ['Chiamate', 'Calls', 'Aufrufe', 'Appels'],
            'OBSERVABILITY.COL.MEAN_MS' => ['Media (ms)', 'Mean (ms)', 'Mittelwert (ms)', 'Moyenne (ms)'],
            'OBSERVABILITY.COL.TOTAL_MS' => ['Totale (ms)', 'Total (ms)', 'Total (ms)', 'Total (ms)'],
            'OBSERVABILITY.COL.ROWS' => ['Righe', 'Rows', 'Zeilen', 'Lignes'],
            'OBSERVABILITY.COL.TENANT' => ['Tenant', 'Tenant', 'Mandant', 'Locataire'],
            'OBSERVABILITY.COL.DB_MS' => ['Tempo DB (ms)', 'DB time (ms)', 'DB-Zeit (ms)', 'Temps BD (ms)'],
            'OBSERVABILITY.COL.SHARE' => ['Quota del carico', 'Share of the load', 'Anteil an der Last', 'Part de la charge'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function drawer(): array
    {
        return [
            'OBSERVABILITY.DRAWER.TITLE' => ['Dettaglio della query', 'Query detail', 'Abfragedetail', 'Détail de la requête'],
            'OBSERVABILITY.DRAWER.N_PLUS_ONE_HINT' => ['La stessa query viene ripetuta molte volte nella stessa richiesta: caricare le relazioni in anticipo (eager loading) o raggruppare le letture.', 'The same query runs many times in one request: load the relations up front (eager loading) or batch the reads.', 'Dieselbe Abfrage läuft in einer Anfrage mehrfach: Relationen vorab laden (Eager Loading) oder Lesezugriffe bündeln.', 'La même requête s’exécute de nombreuses fois dans une requête : charger les relations en amont (eager loading) ou regrouper les lectures.'],
            'OBSERVABILITY.DRAWER.SQL' => ['SQL normalizzato (senza dati personali)', 'Normalized SQL (no personal data)', 'Normalisiertes SQL (ohne personenbezogene Daten)', 'SQL normalisé (sans données personnelles)'],
            'OBSERVABILITY.DRAWER.PLAN' => ['Piano di esecuzione (EXPLAIN)', 'Execution plan (EXPLAIN)', 'Ausführungsplan (EXPLAIN)', 'Plan d’exécution (EXPLAIN)'],
            'OBSERVABILITY.DRAWER.INDEXES' => ['Indici suggeriti', 'Suggested indexes', 'Vorgeschlagene Indizes', 'Index suggérés'],
            'OBSERVABILITY.DRAWER.NO_INDEXES' => ['Nessun indice mancante rilevato per questa query.', 'No missing index detected for this query.', 'Für diese Abfrage wurde kein fehlender Index erkannt.', 'Aucun index manquant détecté pour cette requête.'],
            'OBSERVABILITY.KIND.SLOW' => ['Lenta', 'Slow', 'Langsam', 'Lente'],
            'OBSERVABILITY.KIND.N_PLUS_ONE' => ['Pattern N+1', 'N+1 pattern', 'N+1-Muster', 'Motif N+1'],
            'OBSERVABILITY.PLAN_STATUS.AVAILABLE' => ['Piano disponibile.', 'Plan available.', 'Plan verfügbar.', 'Plan disponible.'],
            'OBSERVABILITY.PLAN_STATUS.UNSUPPORTED' => ['Il piano non è disponibile: il database in uso non è PostgreSQL.', 'The plan is not available: the database in use is not PostgreSQL.', 'Der Plan ist nicht verfügbar: Die verwendete Datenbank ist nicht PostgreSQL.', 'Le plan n’est pas disponible : la base de données utilisée n’est pas PostgreSQL.'],
            'OBSERVABILITY.PLAN_STATUS.SKIPPED' => ['Piano non calcolato: solo le SELECT vengono analizzate, le scritture non vengono mai rieseguite.', 'Plan not computed: only SELECT statements are analyzed, writes are never re-run.', 'Plan nicht berechnet: Nur SELECT-Anweisungen werden analysiert, Schreibzugriffe werden nie erneut ausgeführt.', 'Plan non calculé : seules les instructions SELECT sont analysées, les écritures ne sont jamais rejouées.'],
            'OBSERVABILITY.PLAN_STATUS.FAILED' => ['Il calcolo del piano non è riuscito (timeout o statement non più valido).', 'Computing the plan failed (timeout or the statement is no longer valid).', 'Die Berechnung des Plans ist fehlgeschlagen (Timeout oder Statement nicht mehr gültig).', 'Le calcul du plan a échoué (délai dépassé ou instruction devenue invalide).'],
            'OBSERVABILITY.PLAN.ON' => ['su', 'on', 'auf', 'sur'],
            'OBSERVABILITY.PLAN.COST' => ['costo', 'cost', 'Kosten', 'coût'],
            'OBSERVABILITY.PLAN.ROWS' => ['righe stimate', 'estimated rows', 'geschätzte Zeilen', 'lignes estimées'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function settings(): array
    {
        return [
            'OBSERVABILITY.SETTINGS.SLOW_QUERY_THRESHOLD_MS' => ['Soglia query lenta (ms)', 'Slow query threshold (ms)', 'Schwelle für langsame Abfragen (ms)', 'Seuil de requête lente (ms)'],
            'OBSERVABILITY.SETTINGS.N_PLUS_ONE_THRESHOLD' => ['Ripetizioni per segnalare un N+1', 'Repetitions that flag an N+1', 'Wiederholungen für eine N+1-Markierung', 'Répétitions signalant un N+1'],
            'OBSERVABILITY.SETTINGS.ALERT_ENDPOINT_P95_MS' => ['Allarme p95 endpoint (ms)', 'Endpoint p95 alert (ms)', 'Alarm Endpunkt-p95 (ms)', 'Alerte p95 des points d’accès (ms)'],
            'OBSERVABILITY.SETTINGS.ALERT_TENANT_SHARE_PCT' => ['Allarme quota tenant sul carico (%)', 'Tenant load share alert (%)', 'Alarm Mandantenanteil an der Last (%)', 'Alerte part de charge du locataire (%)'],
            'OBSERVABILITY.SETTINGS.ALERT_CONNECTOR_LATENCY_MS' => ['Allarme latenza connettori (ms)', 'Connector latency alert (ms)', 'Alarm Latenz der Schnittstellen (ms)', 'Alerte latence des connecteurs (ms)'],
            'OBSERVABILITY.SETTINGS.RETENTION_DAYS' => ['Conservazione dei dati (giorni)', 'Data retention (days)', 'Aufbewahrung der Daten (Tage)', 'Conservation des données (jours)'],
            'OBSERVABILITY.SETTINGS.EXPLAIN_ENABLED' => ['Calcola il piano EXPLAIN delle SELECT lente', 'Compute the EXPLAIN plan of slow SELECTs', 'EXPLAIN-Plan langsamer SELECTs berechnen', 'Calculer le plan EXPLAIN des SELECT lents'],
            'OBSERVABILITY.SETTINGS.SAVED' => ['Impostazioni salvate.', 'Settings saved.', 'Einstellungen gespeichert.', 'Paramètres enregistrés.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'OBSERVABILITY.ACTION.RELOAD' => ['Ricarica', 'Reload', 'Neu laden', 'Recharger'],
            'OBSERVABILITY.ACTION.DETAILS' => ['Dettaglio', 'Details', 'Details', 'Détail'],
            'OBSERVABILITY.ACTION.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'OBSERVABILITY.ACTION.COPY' => ['Copia', 'Copy', 'Kopieren', 'Copier'],
            'OBSERVABILITY.ACTION.COPY_TRACE' => ['Copia ID di traccia', 'Copy trace ID', 'Trace-ID kopieren', 'Copier l’ID de trace'],
            'OBSERVABILITY.ACTION.COPY_SQL' => ['Copia SQL', 'Copy SQL', 'SQL kopieren', 'Copier le SQL'],
            'OBSERVABILITY.COPIED' => ['Copiato negli appunti.', 'Copied to the clipboard.', 'In die Zwischenablage kopiert.', 'Copié dans le presse-papiers.'],
            'OBSERVABILITY.COPY_FAILED' => ['Impossibile copiare: il browser ha negato l’accesso agli appunti.', 'Could not copy: the browser denied access to the clipboard.', 'Kopieren nicht möglich: Der Browser hat den Zugriff auf die Zwischenablage verweigert.', 'Copie impossible : le navigateur a refusé l’accès au presse-papiers.'],
            'OBSERVABILITY.ERROR.GENERIC' => ['Impossibile caricare i dati di osservabilità. Riprovare tra poco.', 'The observability data could not be loaded. Try again shortly.', 'Die Observability-Daten konnten nicht geladen werden. Bitte gleich erneut versuchen.', 'Les données d’observabilité n’ont pas pu être chargées. Réessayez dans un instant.'],
            'OBSERVABILITY.ERROR.FORBIDDEN' => ['Questa sezione è riservata agli amministratori della piattaforma.', 'This section is reserved for platform administrators.', 'Dieser Bereich ist Plattformadministratoren vorbehalten.', 'Cette section est réservée aux administrateurs de la plateforme.'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'en' => 1,
            'de' => 2,
            'fr' => 3,
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $resolved = [];

        foreach (self::definitions() as $key => $tuple) {
            $resolved[$key] = $tuple[$index];
        }

        return $resolved;
    }
}
