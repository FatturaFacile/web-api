<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * Frontend strings of the sales pipeline screens (Kanban board, forecast and quota dashboard, pipeline configuration,
 * loss questionnaire, quick notes) in it, en, de and fr. Merged by FrontendTranslationSeeder::dictionary(); the
 * catalog labels themselves (pipelines, stages, loss reasons) come localized from the CRM API.
 */
final class SalesPipelineFrontendTranslations
{
    /**
     * Key => [it, de, fr, en].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function table(): array
    {
        return [
            'NAV.SALES_PIPELINE' => ['Pipeline vendite', 'Verkaufspipeline', 'Pipeline des ventes', 'Sales pipeline'],
            'SALESPIPE.TITLE' => ['Pipeline vendite', 'Verkaufspipeline', 'Pipeline des ventes', 'Sales pipeline'],
            'SALESPIPE.SUBTITLE' => ['Trattative, previsioni e obiettivi di budget', 'Opportunities, Prognosen und Budgetziele', 'Opportunités, prévisions et objectifs budgétaires', 'Deals, forecasts and budget targets'],
            'SALESPIPE.TAB_BOARD' => ['Kanban', 'Kanban', 'Kanban', 'Kanban'],
            'SALESPIPE.TAB_FORECAST' => ['Previsioni e quote', 'Prognose und Quoten', 'Prévisions et quotas', 'Forecast & quotas'],
            'SALESPIPE.TAB_SETTINGS' => ['Configurazione', 'Konfiguration', 'Configuration', 'Configuration'],
            'SALESPIPE.PIPELINE' => ['Pipeline', 'Pipeline', 'Pipeline', 'Pipeline'],
            'SALESPIPE.OWNER' => ['Commerciale', 'Vertriebsmitarbeiter', 'Commercial', 'Sales rep'],
            'SALESPIPE.ALL_OWNERS' => ['Tutti i commerciali', 'Alle Vertriebsmitarbeiter', 'Tous les commerciaux', 'All sales reps'],
            'SALESPIPE.UNASSIGNED' => ['Non assegnato', 'Nicht zugewiesen', 'Non attribué', 'Unassigned'],
            'SALESPIPE.CLOSE_PERIOD' => ['Chiusura prevista', 'Erwarteter Abschluss', 'Clôture prévue', 'Expected close'],
            'SALESPIPE.SEARCH' => ['Cerca trattativa o numero', 'Opportunity oder Nummer suchen', 'Rechercher une opportunité ou un numéro', 'Search deal or number'],
            'SALESPIPE.CATEGORY' => ['Categoria di previsione', 'Prognosekategorie', 'Catégorie de prévision', 'Forecast category'],
            'SALESPIPE.ALL_CATEGORIES' => ['Tutte le categorie', 'Alle Kategorien', 'Toutes les catégories', 'All categories'],
            'SALESPIPE.CAT_PIPELINE' => ['Pipeline', 'Pipeline', 'Pipeline', 'Pipeline'],
            'SALESPIPE.CAT_BEST_CASE' => ['Best Case', 'Best Case', 'Best Case', 'Best Case'],
            'SALESPIPE.CAT_COMMIT' => ['Commit', 'Commit', 'Commit', 'Commit'],
            'SALESPIPE.CAT_CLOSED' => ['Chiusa', 'Abgeschlossen', 'Clôturée', 'Closed'],
            'SALESPIPE.CAT_OMITTED' => ['Esclusa', 'Ausgeschlossen', 'Exclue', 'Omitted'],
            'SALESPIPE.VALUE' => ['Valore', 'Wert', 'Valeur', 'Value'],
            'SALESPIPE.WEIGHTED' => ['Ponderato', 'Gewichtet', 'Pondéré', 'Weighted'],
            'SALESPIPE.DEALS' => ['trattative', 'Opportunities', 'opportunités', 'deals'],
            'SALESPIPE.PROBABILITY' => ['Probabilità', 'Wahrscheinlichkeit', 'Probabilité', 'Probability'],
            'SALESPIPE.EXPECTED_CLOSE' => ['Chiusura attesa', 'Erwarteter Abschluss', 'Clôture attendue', 'Expected close'],
            'SALESPIPE.LOADING' => ['Caricamento della pipeline…', 'Pipeline wird geladen…', 'Chargement du pipeline…', 'Loading pipeline…'],
            'SALESPIPE.LOAD_ERROR' => ['Impossibile caricare i dati della pipeline.', 'Die Pipelinedaten konnten nicht geladen werden.', 'Impossible de charger les données du pipeline.', 'Could not load the pipeline data.'],
            'SALESPIPE.RETRY' => ['Riprova', 'Erneut versuchen', 'Réessayer', 'Retry'],
            'SALESPIPE.REFRESH' => ['Aggiorna', 'Aktualisieren', 'Actualiser', 'Refresh'],
            'SALESPIPE.EMPTY_BOARD' => ['Nessuna pipeline configurata. Installa il catalogo standard dalle impostazioni.', 'Keine Pipeline konfiguriert. Installieren Sie den Standardkatalog in den Einstellungen.', 'Aucun pipeline configuré. Installez le catalogue standard dans les paramètres.', 'No pipeline configured. Install the standard catalog from the settings.'],
            'SALESPIPE.EMPTY_COLUMN' => ['Nessuna trattativa', 'Keine Opportunities', 'Aucune opportunité', 'No deals'],
            'SALESPIPE.MOVE_FAILED' => ['Spostamento non riuscito: la trattativa è tornata nella fase precedente.', 'Verschieben fehlgeschlagen: die Opportunity wurde in die vorherige Phase zurückgesetzt.', 'Déplacement impossible : l\'opportunité est revenue à l\'étape précédente.', 'Move failed: the deal went back to its previous stage.'],
            'SALESPIPE.MOVED' => ['Trattativa spostata', 'Opportunity verschoben', 'Opportunité déplacée', 'Deal moved'],
            'SALESPIPE.LIVE_UPDATE' => ['La board è stata aggiornata da un altro utente.', 'Das Board wurde von einem anderen Benutzer aktualisiert.', 'Le tableau a été mis à jour par un autre utilisateur.', 'The board was updated by another user.'],
            'SALESPIPE.LIVE_ON' => ['Sincronizzazione in tempo reale attiva', 'Echtzeit-Synchronisierung aktiv', 'Synchronisation en temps réel active', 'Real-time sync on'],
            'SALESPIPE.LIVE_OFF' => ['Aggiornamento periodico', 'Periodische Aktualisierung', 'Actualisation périodique', 'Periodic refresh'],
            'SALESPIPE.LOSS_TITLE' => ['Motivo della perdita', 'Verlustgrund', 'Motif de la perte', 'Loss reason'],
            'SALESPIPE.LOSS_INTRO' => ['Per chiudere la trattativa come persa indica il motivo e il competitor vincente.', 'Geben Sie zum Abschluss als verloren den Grund und den siegreichen Wettbewerber an.', 'Pour clôturer l\'opportunité comme perdue, indiquez le motif et le concurrent gagnant.', 'To close the deal as lost, enter the reason and the winning competitor.'],
            'SALESPIPE.LOSS_REASON' => ['Motivo', 'Grund', 'Motif', 'Reason'],
            'SALESPIPE.LOSS_REASON_REQUIRED' => ['Il motivo di perdita è obbligatorio', 'Der Verlustgrund ist erforderlich', 'Le motif de perte est obligatoire', 'The loss reason is required'],
            'SALESPIPE.COMPETITOR' => ['Competitor vincente', 'Siegreicher Wettbewerber', 'Concurrent gagnant', 'Winning competitor'],
            'SALESPIPE.COMPETITOR_REQUIRED' => ['Il competitor vincente è obbligatorio', 'Der siegreiche Wettbewerber ist erforderlich', 'Le concurrent gagnant est obligatoire', 'The winning competitor is required'],
            'SALESPIPE.LOSS_NOTES' => ['Dettagli (facoltativo)', 'Details (optional)', 'Détails (facultatif)', 'Details (optional)'],
            'SALESPIPE.CONFIRM_LOSS' => ['Chiudi come persa', 'Als verloren abschliessen', 'Clôturer comme perdue', 'Close as lost'],
            'SALESPIPE.CANCEL' => ['Annulla', 'Abbrechen', 'Annuler', 'Cancel'],
            'SALESPIPE.NOTE' => ['Nota rapida', 'Schnellnotiz', 'Note rapide', 'Quick note'],
            'SALESPIPE.NOTE_PLACEHOLDER' => ['Scrivi un aggiornamento sulla trattativa…', 'Schreiben Sie ein Update zur Opportunity…', 'Écrivez une mise à jour sur l\'opportunité…', 'Write an update about the deal…'],
            'SALESPIPE.NOTE_APPEND' => ['Aggiungi in coda con data e ora', 'Mit Datum und Uhrzeit anhängen', 'Ajouter à la suite avec date et heure', 'Append with date and time'],
            'SALESPIPE.NOTE_SAVE' => ['Salva nota', 'Notiz speichern', 'Enregistrer la note', 'Save note'],
            'SALESPIPE.NOTE_SAVED' => ['Nota salvata', 'Notiz gespeichert', 'Note enregistrée', 'Note saved'],
            'SALESPIPE.NOTE_CURRENT' => ['Nota attuale', 'Aktuelle Notiz', 'Note actuelle', 'Current note'],
            'SALESPIPE.GRAN_MONTH' => ['Mensile', 'Monatlich', 'Mensuel', 'Monthly'],
            'SALESPIPE.GRAN_QUARTER' => ['Trimestrale', 'Vierteljährlich', 'Trimestriel', 'Quarterly'],
            'SALESPIPE.YEAR' => ['Anno', 'Jahr', 'Année', 'Year'],
            'SALESPIPE.QUOTA' => ['Obiettivo', 'Ziel', 'Objectif', 'Quota'],
            'SALESPIPE.CLOSED_WON' => ['Chiuso vinto', 'Gewonnen', 'Clôturé gagné', 'Closed won'],
            'SALESPIPE.CLOSED_LOST' => ['Chiuso perso', 'Verloren', 'Clôturé perdu', 'Closed lost'],
            'SALESPIPE.ATTAINMENT' => ['Raggiungimento', 'Zielerreichung', 'Atteinte', 'Attainment'],
            'SALESPIPE.GAP' => ['Gap rispetto all\'obiettivo', 'Lücke zum Ziel', 'Écart par rapport à l\'objectif', 'Gap to quota'],
            'SALESPIPE.COMMIT_FORECAST' => ['Previsione Commit', 'Commit-Prognose', 'Prévision Commit', 'Commit forecast'],
            'SALESPIPE.BEST_CASE_FORECAST' => ['Previsione Best Case', 'Best-Case-Prognose', 'Prévision Best Case', 'Best case forecast'],
            'SALESPIPE.WEIGHTED_FORECAST' => ['Previsione ponderata', 'Gewichtete Prognose', 'Prévision pondérée', 'Weighted forecast'],
            'SALESPIPE.PERIOD_BUCKETS' => ['Previsione per periodo', 'Prognose pro Periode', 'Prévision par période', 'Forecast by period'],
            'SALESPIPE.PERIOD' => ['Periodo', 'Periode', 'Période', 'Period'],
            'SALESPIPE.REPS' => ['Obiettivi per commerciale', 'Ziele pro Vertriebsmitarbeiter', 'Objectifs par commercial', 'Quota by sales rep'],
            'SALESPIPE.WHOLE_YEAR' => ['Intero anno', 'Ganzes Jahr', 'Année entière', 'Whole year'],
            'SALESPIPE.QUARTER' => ['Trimestre', 'Quartal', 'Trimestre', 'Quarter'],
            'SALESPIPE.MONTH' => ['Mese', 'Monat', 'Mois', 'Month'],
            'SALESPIPE.NO_DATA' => ['Nessun dato per il periodo selezionato', 'Keine Daten für den gewählten Zeitraum', 'Aucune donnée pour la période sélectionnée', 'No data for the selected period'],
            'SALESPIPE.LOSS_ANALYSIS' => ['Analisi delle perdite', 'Verlustanalyse', 'Analyse des pertes', 'Loss analysis'],
            'SALESPIPE.LOSS_BY_REASON' => ['Per motivo', 'Nach Grund', 'Par motif', 'By reason'],
            'SALESPIPE.LOSS_BY_COMPETITOR' => ['Per competitor vincente', 'Nach Wettbewerber', 'Par concurrent gagnant', 'By winning competitor'],
            'SALESPIPE.COUNT' => ['N.', 'Anz.', 'N°', 'Count'],
            'SALESPIPE.AMOUNT' => ['Importo', 'Betrag', 'Montant', 'Amount'],
            'SALESPIPE.QUOTAS' => ['Obiettivi di budget', 'Budgetziele', 'Objectifs budgétaires', 'Budget quotas'],
            'SALESPIPE.QUOTA_NEW' => ['Nuovo obiettivo', 'Neues Ziel', 'Nouvel objectif', 'New quota'],
            'SALESPIPE.QUOTA_EDIT' => ['Modifica obiettivo', 'Ziel bearbeiten', 'Modifier l\'objectif', 'Edit quota'],
            'SALESPIPE.QUOTA_USER' => ['Commerciale (ID utente)', 'Vertriebsmitarbeiter (Benutzer-ID)', 'Commercial (ID utilisateur)', 'Sales rep (user ID)'],
            'SALESPIPE.QUOTA_START' => ['Inizio periodo', 'Periodenbeginn', 'Début de période', 'Period start'],
            'SALESPIPE.QUOTA_END' => ['Fine periodo', 'Periodenende', 'Fin de période', 'Period end'],
            'SALESPIPE.QUOTA_TARGET' => ['Importo obiettivo', 'Zielbetrag', 'Montant objectif', 'Target amount'],
            'SALESPIPE.QUOTA_SAVED' => ['Obiettivo salvato', 'Ziel gespeichert', 'Objectif enregistré', 'Quota saved'],
            'SALESPIPE.QUOTA_DELETED' => ['Obiettivo eliminato', 'Ziel gelöscht', 'Objectif supprimé', 'Quota deleted'],
            'SALESPIPE.QUOTA_PERIOD_INVALID' => ['La fine periodo non può precedere l\'inizio', 'Das Periodenende darf nicht vor dem Beginn liegen', 'La fin de période ne peut pas précéder le début', 'The period end cannot precede the start'],
            'SALESPIPE.PIPELINES' => ['Pipeline', 'Pipelines', 'Pipelines', 'Pipelines'],
            'SALESPIPE.PIPELINE_NEW' => ['Nuova pipeline', 'Neue Pipeline', 'Nouveau pipeline', 'New pipeline'],
            'SALESPIPE.PIPELINE_EDIT' => ['Modifica pipeline', 'Pipeline bearbeiten', 'Modifier le pipeline', 'Edit pipeline'],
            'SALESPIPE.CODE' => ['Codice', 'Code', 'Code', 'Code'],
            'SALESPIPE.NAME' => ['Nome', 'Name', 'Nom', 'Name'],
            'SALESPIPE.BUSINESS_LINE' => ['Linea di business', 'Geschäftsbereich', 'Ligne d\'activité', 'Business line'],
            'SALESPIPE.MARKET' => ['Mercato', 'Markt', 'Marché', 'Market'],
            'SALESPIPE.IS_DEFAULT' => ['Predefinita', 'Standard', 'Par défaut', 'Default'],
            'SALESPIPE.IS_ACTIVE' => ['Attiva', 'Aktiv', 'Active', 'Active'],
            'SALESPIPE.INSTALL_STANDARD_STAGES' => ['Crea le fasi standard', 'Standardphasen anlegen', 'Créer les étapes standard', 'Create the standard stages'],
            'SALESPIPE.STAGES' => ['Fasi', 'Phasen', 'Étapes', 'Stages'],
            'SALESPIPE.STAGE_NEW' => ['Nuova fase', 'Neue Phase', 'Nouvelle étape', 'New stage'],
            'SALESPIPE.STAGE_EDIT' => ['Modifica fase', 'Phase bearbeiten', 'Modifier l\'étape', 'Edit stage'],
            'SALESPIPE.STAGE_PROBABILITY' => ['Probabilità di vincita (%)', 'Gewinnwahrscheinlichkeit (%)', 'Probabilité de gain (%)', 'Win probability (%)'],
            'SALESPIPE.STAGE_CATEGORY' => ['Categoria di previsione predefinita', 'Standard-Prognosekategorie', 'Catégorie de prévision par défaut', 'Default forecast category'],
            'SALESPIPE.STAGE_WON' => ['Fase vinta', 'Gewonnene Phase', 'Étape gagnée', 'Won stage'],
            'SALESPIPE.STAGE_LOST' => ['Fase persa', 'Verlorene Phase', 'Étape perdue', 'Lost stage'],
            'SALESPIPE.STAGE_ORDER' => ['Ordine', 'Reihenfolge', 'Ordre', 'Order'],
            'SALESPIPE.STAGE_REPRICE' => ['Applica la nuova probabilità alle trattative aperte della fase', 'Neue Wahrscheinlichkeit auf offene Opportunities der Phase anwenden', 'Appliquer la nouvelle probabilité aux opportunités ouvertes de l\'étape', 'Apply the new probability to the open deals of the stage'],
            'SALESPIPE.SAVE' => ['Salva', 'Speichern', 'Enregistrer', 'Save'],
            'SALESPIPE.SAVED' => ['Salvato', 'Gespeichert', 'Enregistré', 'Saved'],
            'SALESPIPE.DELETE' => ['Elimina', 'Löschen', 'Supprimer', 'Delete'],
            'SALESPIPE.DELETED' => ['Eliminato', 'Gelöscht', 'Supprimé', 'Deleted'],
            'SALESPIPE.DELETE_CONFIRM' => ['Eliminare definitivamente? Se è in uso puoi solo disattivarla.', 'Endgültig löschen? Wenn in Verwendung, kann nur deaktiviert werden.', 'Supprimer définitivement ? Si utilisé, seule la désactivation est possible.', 'Delete permanently? If it is in use you can only deactivate it.'],
            'SALESPIPE.IN_USE' => ['In uso da trattative', 'Von Opportunities verwendet', 'Utilisé par des opportunités', 'Used by deals'],
            'SALESPIPE.INSTALL_CATALOG' => ['Installa il catalogo standard', 'Standardkatalog installieren', 'Installer le catalogue standard', 'Install the standard catalog'],
            'SALESPIPE.CATALOG_INSTALLED' => ['Catalogo installato', 'Katalog installiert', 'Catalogue installé', 'Catalog installed'],
            'SALESPIPE.LOSS_REASONS' => ['Motivi di perdita', 'Verlustgründe', 'Motifs de perte', 'Loss reasons'],
            'SALESPIPE.LOSS_REASON_NEW' => ['Nuovo motivo di perdita', 'Neuer Verlustgrund', 'Nouveau motif de perte', 'New loss reason'],
            'SALESPIPE.LOSS_REASON_EDIT' => ['Modifica motivo di perdita', 'Verlustgrund bearbeiten', 'Modifier le motif de perte', 'Edit loss reason'],
            'SALESPIPE.REQUIRES_COMPETITOR' => ['Richiede un competitor specifico', 'Erfordert einen bestimmten Wettbewerber', 'Exige un concurrent précis', 'Requires a specific competitor'],
            'SALESPIPE.LABELS' => ['Etichette per lingua', 'Bezeichnungen pro Sprache', 'Libellés par langue', 'Labels by language'],
            'SALESPIPE.REQUIRED' => ['Campo obbligatorio', 'Pflichtfeld', 'Champ obligatoire', 'Required field'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $column = match ($locale) {
            'it' => 0,
            'de' => 1,
            'fr' => 2,
            'en' => 3,
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        return array_map(static fn (array $row): string => $row[$column], self::table());
    }
}
