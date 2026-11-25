<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the budget control (Mod 019, issue #266): the budget grid (monthly or quarterly), revisions, scenarios,
 * transfers and the roll forward of the rolling forecast, the Excel import and the executive pack, the variance traffic
 * light and the volume and price variance, the spend lock (available budget, rules, commitments, controls log,
 * thresholds) and the override (deroga) requests.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * The keys with the BUDGETING.TYPE_ prefix extend the version types of the planning module; everything else lives under
 * BUDGETING_CONTROL.* so it never overlaps the dictionary of the planning screens.
 */
final class BudgetingSpendControlFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::planning(),
            self::variance(),
            self::spendControl(),
            self::overrides(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.BUDGETING_SPEND_CONTROL' => ['Controllo di spesa', 'Spend control', 'Ausgabenkontrolle', 'Contrôle des dépenses'],
            'NAV.BUDGETING_OVERRIDES' => ['Deroghe di budget', 'Budget overrides', 'Budgetausnahmen', 'Dérogations budgétaires'],
            'PAGE_TITLE.BUDGETING_SPEND_CONTROL' => ['Controllo di spesa e budget disponibile', 'Spend control and available budget', 'Ausgabenkontrolle und verfügbares Budget', 'Contrôle des dépenses et budget disponible'],
            'PAGE_TITLE.BUDGETING_OVERRIDES' => ['Richieste di deroga al budget', 'Budget override requests', 'Anträge auf Budgetausnahme', 'Demandes de dérogation budgétaire'],
            'BUDGETING_CONTROL.SPEND_CONTROL_SUBTITLE' => [
                'Budget disponibile = budget - (costi effettivi + impegni aperti), regole di blocco e impegni di RDA e ordini fornitore.',
                'Available budget = budget - (actual costs + open commitments), blocking rules and commitments of requisitions and purchase orders.',
                'Verfügbares Budget = Budget - (Ist-Kosten + offene Verpflichtungen), Sperregeln und Verpflichtungen aus Bestellanforderungen und Lieferantenbestellungen.',
                'Budget disponible = budget - (coûts réels + engagements ouverts), règles de blocage et engagements des demandes d’achat et commandes fournisseur.',
            ],
            'BUDGETING_CONTROL.OVERRIDES_SUBTITLE' => [
                'Richieste di spesa oltre il budget, decise dal CFO con traccia completa.',
                'Requests to spend beyond the budget, decided by the CFO with a full trail.',
                'Anträge für Ausgaben über das Budget hinaus, vom CFO entschieden und lückenlos protokolliert.',
                'Demandes de dépenses au-delà du budget, décidées par le CFO avec une piste complète.',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'BUDGETING.TYPE_REVISED' => ['Budget rivisto', 'Revised budget', 'Revidiertes Budget', 'Budget révisé'],
            'BUDGETING.TYPE_SCENARIO' => ['Scenario', 'Scenario', 'Szenario', 'Scénario'],
            'BUDGETING_CONTROL.SCENARIO_BEST_CASE' => ['Scenario migliore', 'Best case', 'Bestfall', 'Meilleur scénario'],
            'BUDGETING_CONTROL.SCENARIO_WORST_CASE' => ['Scenario peggiore', 'Worst case', 'Schlimmstfall', 'Pire scénario'],
            'BUDGETING_CONTROL.SCENARIO_CUSTOM' => ['Scenario personalizzato', 'Custom scenario', 'Eigenes Szenario', 'Scénario personnalisé'],
            'BUDGETING_CONTROL.GRANULARITY_MONTHLY' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuel'],
            'BUDGETING_CONTROL.GRANULARITY_QUARTERLY' => ['Trimestrale', 'Quarterly', 'Quartalsweise', 'Trimestriel'],
            'BUDGETING_CONTROL.MODE_ALLOW' => ['Consenti', 'Allow', 'Erlauben', 'Autoriser'],
            'BUDGETING_CONTROL.MODE_WARNING' => ['Avviso', 'Warning', 'Warnung', 'Avertissement'],
            'BUDGETING_CONTROL.MODE_HARD_BLOCK' => ['Blocco', 'Hard block', 'Harte Sperre', 'Blocage strict'],
            'BUDGETING_CONTROL.DOCUMENT_REQUISITION' => ['Richiesta di acquisto (RDA)', 'Purchase requisition', 'Bestellanforderung', 'Demande d’achat'],
            'BUDGETING_CONTROL.DOCUMENT_PURCHASE_ORDER' => ['Ordine fornitore', 'Purchase order', 'Lieferantenbestellung', 'Commande fournisseur'],
            'BUDGETING_CONTROL.STAGE_CREATE' => ['Creazione', 'Creation', 'Erstellung', 'Création'],
            'BUDGETING_CONTROL.STAGE_SUBMIT' => ['Invio', 'Submission', 'Einreichung', 'Soumission'],
            'BUDGETING_CONTROL.STAGE_APPROVE' => ['Approvazione', 'Approval', 'Genehmigung', 'Approbation'],
            'BUDGETING_CONTROL.OUTCOME_OK' => ['Nel budget', 'Within budget', 'Im Budget', 'Dans le budget'],
            'BUDGETING_CONTROL.OUTCOME_WARNING' => ['Avviso', 'Warning', 'Warnung', 'Avertissement'],
            'BUDGETING_CONTROL.OUTCOME_OVERRIDDEN' => ['Sbloccato da deroga', 'Lifted by override', 'Durch Ausnahme freigegeben', 'Levé par dérogation'],
            'BUDGETING_CONTROL.OUTCOME_BLOCKED' => ['Bloccato', 'Blocked', 'Gesperrt', 'Bloqué'],
            'BUDGETING_CONTROL.COMMITMENT_OPEN' => ['Aperto', 'Open', 'Offen', 'Ouvert'],
            'BUDGETING_CONTROL.COMMITMENT_CLOSED' => ['Chiuso', 'Closed', 'Geschlossen', 'Clos'],
            'BUDGETING_CONTROL.COMMITMENT_RELEASED' => ['Rilasciato', 'Released', 'Freigegeben', 'Libéré'],
            'BUDGETING_CONTROL.OVERRIDE_PENDING' => ['In attesa', 'Pending', 'Ausstehend', 'En attente'],
            'BUDGETING_CONTROL.OVERRIDE_APPROVED' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvée'],
            'BUDGETING_CONTROL.OVERRIDE_REJECTED' => ['Rifiutata', 'Rejected', 'Abgelehnt', 'Refusée'],
            'BUDGETING_CONTROL.OVERRIDE_CANCELLED' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'BUDGETING_CONTROL.OVERRIDE_CONSUMED' => ['Utilizzata', 'Used', 'Verbraucht', 'Utilisée'],
            'BUDGETING_CONTROL.SCOPE_DOCUMENT' => ['Singolo documento', 'Single document', 'Einzelnes Dokument', 'Document unique'],
            'BUDGETING_CONTROL.SCOPE_ALLOWANCE' => ['Autorizzazione temporanea', 'Temporary allowance', 'Befristete Ermächtigung', 'Autorisation temporaire'],
            'BUDGETING_CONTROL.LIGHT_GREEN' => ['Verde', 'Green', 'Grün', 'Vert'],
            'BUDGETING_CONTROL.LIGHT_AMBER' => ['Giallo', 'Amber', 'Gelb', 'Orange'],
            'BUDGETING_CONTROL.LIGHT_RED' => ['Rosso', 'Red', 'Rot', 'Rouge'],
            'BUDGETING_CONTROL.IMPORT_MODE_REPLACE' => ['Sostituisci tutto il budget', 'Replace the whole budget', 'Gesamtes Budget ersetzen', 'Remplacer tout le budget'],
            'BUDGETING_CONTROL.IMPORT_MODE_MERGE' => ['Unisci (sovrascrive solo le celle del file)', 'Merge (overwrites only the cells of the file)', 'Zusammenführen (überschreibt nur die Zellen der Datei)', 'Fusionner (écrase seulement les cellules du fichier)'],
            'BUDGETING_CONTROL.IMPORT_STATUS_IMPORTED' => ['Importato', 'Imported', 'Importiert', 'Importé'],
            'BUDGETING_CONTROL.IMPORT_STATUS_FAILED' => ['Respinto', 'Rejected', 'Abgewiesen', 'Rejeté'],
            'BUDGETING_CONTROL.IMPORT_STATUS_VALIDATED' => ['Valido', 'Valid', 'Gültig', 'Valide'],
            'BUDGETING_CONTROL.AUDIT_REVISE' => ['Revisione', 'Revision', 'Revision', 'Révision'],
            'BUDGETING_CONTROL.AUDIT_SCENARIO' => ['Scenario creato', 'Scenario created', 'Szenario erstellt', 'Scénario créé'],
            'BUDGETING_CONTROL.AUDIT_TRANSFER' => ['Trasferimento di budget', 'Budget transfer', 'Budgetübertragung', 'Transfert budgétaire'],
            'BUDGETING_CONTROL.AUDIT_GRID_SAVED' => ['Griglia salvata', 'Grid saved', 'Raster gespeichert', 'Grille enregistrée'],
            'BUDGETING_CONTROL.AUDIT_IMPORT' => ['Importazione', 'Import', 'Import', 'Importation'],
            'BUDGETING_CONTROL.AUDIT_ROLL_FORWARD' => ['Avanzamento del rolling forecast', 'Rolling forecast rolled forward', 'Rolling Forecast fortgeschrieben', 'Rolling forecast avancé'],
            'BUDGETING_CONTROL.AUDIT_REQUEST' => ['Richiesta', 'Request', 'Antrag', 'Demande'],
            'BUDGETING_CONTROL.AUDIT_APPROVE' => ['Approvazione', 'Approval', 'Genehmigung', 'Approbation'],
            'BUDGETING_CONTROL.AUDIT_REJECT' => ['Rifiuto', 'Rejection', 'Ablehnung', 'Refus'],
            'BUDGETING_CONTROL.AUDIT_CANCEL' => ['Annullamento', 'Cancellation', 'Stornierung', 'Annulation'],
            'BUDGETING_CONTROL.AUDIT_CONSUME' => ['Utilizzo', 'Use', 'Verbrauch', 'Utilisation'],
            'BUDGETING_CONTROL.AUDIT_CREATE' => ['Creazione', 'Creation', 'Erstellung', 'Création'],
            'BUDGETING_CONTROL.AUDIT_UPDATE' => ['Modifica', 'Change', 'Änderung', 'Modification'],
            'BUDGETING_CONTROL.AUDIT_DELETE' => ['Eliminazione', 'Deletion', 'Löschung', 'Suppression'],
            'BUDGETING_CONTROL.AUDIT_COMMIT' => ['Impegno', 'Commitment', 'Verpflichtung', 'Engagement'],
            'BUDGETING_CONTROL.AUDIT_RELEASE' => ['Rilascio', 'Release', 'Freigabe', 'Libération'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function planning(): array
    {
        return [
            'BUDGETING_CONTROL.TAB_GRID' => ['Griglia', 'Grid', 'Raster', 'Grille'],
            'BUDGETING_CONTROL.TAB_VOLUME_PRICE' => ['Volume e prezzo', 'Volume and price', 'Menge und Preis', 'Volume et prix'],
            'BUDGETING_CONTROL.TAB_AUDIT' => ['Tracciato di audit', 'Audit trail', 'Audit-Trail', 'Piste d’audit'],
            'BUDGETING_CONTROL.GRANULARITY' => ['Granularità', 'Granularity', 'Granularität', 'Granularité'],
            'BUDGETING_CONTROL.SCENARIO_KIND' => ['Tipo di scenario', 'Scenario type', 'Szenariotyp', 'Type de scénario'],
            'BUDGETING_CONTROL.SHOW_QUANTITIES' => ['Mostra quantità e prezzo unitario', 'Show quantity and unit price', 'Menge und Stückpreis anzeigen', 'Afficher quantité et prix unitaire'],
            'BUDGETING_CONTROL.ADD_GRID_ROW' => ['Aggiungi riga', 'Add row', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'BUDGETING_CONTROL.SAVE_GRID' => ['Salva la griglia', 'Save the grid', 'Raster speichern', 'Enregistrer la grille'],
            'BUDGETING_CONTROL.GRID_SAVED' => ['Griglia salvata.', 'Grid saved.', 'Raster gespeichert.', 'Grille enregistrée.'],
            'BUDGETING_CONTROL.TOTAL' => ['Totale', 'Total', 'Total', 'Total'],
            'BUDGETING_CONTROL.QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'BUDGETING_CONTROL.UNIT_PRICE' => ['Prezzo unitario', 'Unit price', 'Stückpreis', 'Prix unitaire'],
            'BUDGETING_CONTROL.REVISE' => ['Crea revisione', 'Create revision', 'Revision erstellen', 'Créer une révision'],
            'BUDGETING_CONTROL.NEW_SCENARIO' => ['Nuovo scenario', 'New scenario', 'Neues Szenario', 'Nouveau scénario'],
            'BUDGETING_CONTROL.ROLL_FORWARD' => ['Avanza il rolling forecast', 'Roll forecast forward', 'Rolling Forecast fortschreiben', 'Avancer le rolling forecast'],
            'BUDGETING_CONTROL.TRANSFER' => ['Trasferisci budget', 'Transfer budget', 'Budget übertragen', 'Transférer du budget'],
            'BUDGETING_CONTROL.IMPORT_EXCEL' => ['Importa da Excel', 'Import from Excel', 'Aus Excel importieren', 'Importer depuis Excel'],
            'BUDGETING_CONTROL.EXECUTIVE_PACK' => ['Pack per il comitato esecutivo', 'Executive pack', 'Executive-Paket', 'Dossier pour le comité exécutif'],
            'BUDGETING_CONTROL.TOOL_REVISE' => ['Revisione del budget approvato', 'Revision of the approved budget', 'Revision des genehmigten Budgets', 'Révision du budget approuvé'],
            'BUDGETING_CONTROL.TOOL_SCENARIO' => ['Nuovo scenario dal budget', 'New scenario from the budget', 'Neues Szenario aus dem Budget', 'Nouveau scénario à partir du budget'],
            'BUDGETING_CONTROL.TOOL_ROLL' => ['Avanza il rolling forecast', 'Roll the rolling forecast forward', 'Rolling Forecast fortschreiben', 'Avancer le rolling forecast'],
            'BUDGETING_CONTROL.TOOL_TRANSFER' => ['Trasferimento di budget tra righe', 'Budget transfer between lines', 'Budgetübertragung zwischen Zeilen', 'Transfert de budget entre lignes'],
            'BUDGETING_CONTROL.TOOL_IMPORT' => ['Importazione del budget da Excel o CSV', 'Budget import from Excel or CSV', 'Budgetimport aus Excel oder CSV', 'Importation du budget depuis Excel ou CSV'],
            'BUDGETING_CONTROL.REVISION_REASON' => ['Motivo della revisione', 'Reason for the revision', 'Grund der Revision', 'Motif de la révision'],
            'BUDGETING_CONTROL.SHIFT_MONTHS' => ['Mesi di avanzamento', 'Months to roll forward', 'Monate Fortschreibung', 'Mois d’avancement'],
            'BUDGETING_CONTROL.TRANSFER_FROM' => ['Da', 'From', 'Von', 'De'],
            'BUDGETING_CONTROL.TRANSFER_TO' => ['A', 'To', 'Nach', 'Vers'],
            'BUDGETING_CONTROL.IMPORT_HINT' => [
                'Una riga per conto, centro di costo, progetto e periodo (mese 1-12, trimestre 1-4 per i budget trimestrali). L’intero file è convalidato: se c’è un errore non viene importato nulla.',
                'One row per account, cost centre, project and period (month 1-12, quarter 1-4 for quarterly budgets). The whole file is validated: if there is an error nothing is imported.',
                'Eine Zeile je Konto, Kostenstelle, Projekt und Periode (Monat 1-12, Quartal 1-4 bei Quartalsbudgets). Die ganze Datei wird geprüft: Bei einem Fehler wird nichts importiert.',
                'Une ligne par compte, centre de coûts, projet et période (mois 1-12, trimestre 1-4 pour les budgets trimestriels). Tout le fichier est validé : en cas d’erreur rien n’est importé.',
            ],
            'BUDGETING_CONTROL.DOWNLOAD_TEMPLATE' => ['Scarica il modello', 'Download the template', 'Vorlage herunterladen', 'Télécharger le modèle'],
            'BUDGETING_CONTROL.IMPORT_FILE' => ['File Excel o CSV', 'Excel or CSV file', 'Excel- oder CSV-Datei', 'Fichier Excel ou CSV'],
            'BUDGETING_CONTROL.IMPORT' => ['Importa', 'Import', 'Importieren', 'Importer'],
            'BUDGETING_CONTROL.VALIDATE_ONLY' => ['Solo convalida', 'Validate only', 'Nur prüfen', 'Valider seulement'],
            'BUDGETING_CONTROL.IMPORT_COUNTS' => ['{imported} di {total} righe importate, {errors} con errori', '{imported} of {total} rows imported, {errors} with errors', '{imported} von {total} Zeilen importiert, {errors} mit Fehlern', '{imported} lignes sur {total} importées, {errors} avec erreurs'],
            'BUDGETING_CONTROL.IMPORT_HISTORY' => ['Importazioni', 'Imports', 'Importe', 'Importations'],
            'BUDGETING_CONTROL.AUDIT_TRAIL' => ['Tracciato delle modifiche', 'Change trail', 'Änderungsprotokoll', 'Piste des modifications'],
            'BUDGETING_CONTROL.NO_AUDIT' => ['Nessuna modifica registrata.', 'No changes recorded.', 'Keine Änderungen erfasst.', 'Aucune modification enregistrée.'],
            'BUDGETING_CONTROL.FILE' => ['File', 'File', 'Datei', 'Fichier'],
            'BUDGETING_CONTROL.ROW' => ['Riga', 'Row', 'Zeile', 'Ligne'],
            'BUDGETING_CONTROL.ROWS' => ['Righe', 'Rows', 'Zeilen', 'Lignes'],
            'BUDGETING_CONTROL.ERRORS' => ['Errori', 'Errors', 'Fehler', 'Erreurs'],
            'BUDGETING_CONTROL.FIELD' => ['Campo', 'Field', 'Feld', 'Champ'],
            'BUDGETING_CONTROL.MESSAGE' => ['Messaggio', 'Message', 'Meldung', 'Message'],
            'BUDGETING_CONTROL.PROJECT_INVALID' => ['Inserisci un identificativo di progetto valido (UUID).', 'Enter a valid project identifier (UUID).', 'Geben Sie eine gültige Projektkennung (UUID) ein.', 'Saisissez un identifiant de projet valide (UUID).'],
            'BUDGETING_CONTROL.UUID_INVALID' => ['Inserisci un identificativo valido (UUID).', 'Enter a valid identifier (UUID).', 'Geben Sie eine gültige Kennung (UUID) ein.', 'Saisissez un identifiant valide (UUID).'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function variance(): array
    {
        return [
            'BUDGETING_CONTROL.FILTERS' => ['Filtri', 'Filters', 'Filter', 'Filtres'],
            'BUDGETING_CONTROL.APPLY_FILTERS' => ['Applica i filtri', 'Apply filters', 'Filter anwenden', 'Appliquer les filtres'],
            'BUDGETING_CONTROL.CLEAR_FILTERS' => ['Azzera i filtri', 'Clear filters', 'Filter zurücksetzen', 'Réinitialiser les filtres'],
            'BUDGETING_CONTROL.CHART_TITLE' => ['Effettivo contro budget', 'Actual against budget', 'Ist gegen Budget', 'Réel contre budget'],
            'BUDGETING_CONTROL.CHART_LABEL' => ['Grafico a barre del budget e dell’effettivo per gruppo', 'Bar chart of budget and actual by group', 'Balkendiagramm von Budget und Ist je Gruppe', 'Graphique en barres du budget et du réel par groupe'],
            'BUDGETING_CONTROL.TRAFFIC_LIGHT' => ['Semaforo', 'Traffic light', 'Ampel', 'Feu tricolore'],
            'BUDGETING_CONTROL.TRAFFIC_LEGEND' => [
                'Verde: scostamento favorevole o entro il {amber}% del budget; giallo: fino al {red}%; rosso: oltre.',
                'Green: favourable variance or within {amber}% of the budget; amber: up to {red}%; red: beyond.',
                'Grün: günstige Abweichung oder innerhalb von {amber}% des Budgets; Gelb: bis {red}%; Rot: darüber.',
                'Vert : écart favorable ou dans les {amber}% du budget ; orange : jusqu’à {red}% ; rouge : au-delà.',
            ],
            'BUDGETING_CONTROL.VOLUME_VARIANCE' => ['Scostamento di volume', 'Volume variance', 'Mengenabweichung', 'Écart de volume'],
            'BUDGETING_CONTROL.PRICE_VARIANCE' => ['Scostamento di prezzo', 'Price variance', 'Preisabweichung', 'Écart de prix'],
            'BUDGETING_CONTROL.UNDECOMPOSED_VARIANCE' => ['Non scomponibile', 'Not decomposable', 'Nicht zerlegbar', 'Non décomposable'],
            'BUDGETING_CONTROL.NOT_DECOMPOSABLE' => ['Solo importi', 'Amounts only', 'Nur Beträge', 'Montants seulement'],
            'BUDGETING_CONTROL.DECOMPOSITION_HINT' => [
                'Volume = (quantità effettiva - quantità a budget) x prezzo a budget; prezzo = (prezzo effettivo - prezzo a budget) x quantità effettiva. Volume + prezzo = effettivo - budget. Servono la quantità a budget sulle righe e le quantità effettive.',
                'Volume = (actual quantity - budget quantity) x budget price; price = (actual price - budget price) x actual quantity. Volume + price = actual - budget. The budget quantity on the lines and the actual quantities are required.',
                'Menge = (Ist-Menge - Budgetmenge) x Budgetpreis; Preis = (Ist-Preis - Budgetpreis) x Ist-Menge. Menge + Preis = Ist - Budget. Erforderlich sind die Budgetmenge in den Zeilen und die Ist-Mengen.',
                'Volume = (quantité réelle - quantité budgétée) x prix budgété ; prix = (prix réel - prix budgété) x quantité réelle. Volume + prix = réel - budget. La quantité budgétée des lignes et les quantités réelles sont nécessaires.',
            ],
            'BUDGETING_CONTROL.BUDGET_QUANTITY' => ['Quantità a budget', 'Budget quantity', 'Budgetmenge', 'Quantité budgétée'],
            'BUDGETING_CONTROL.ACTUAL_QUANTITY' => ['Quantità effettiva', 'Actual quantity', 'Ist-Menge', 'Quantité réelle'],
            'BUDGETING_CONTROL.BUDGET_PRICE' => ['Prezzo a budget', 'Budget price', 'Budgetpreis', 'Prix budgété'],
            'BUDGETING_CONTROL.ACTUAL_PRICE' => ['Prezzo effettivo', 'Actual price', 'Ist-Preis', 'Prix réel'],
            'BUDGETING_CONTROL.ACTUAL_VOLUMES' => ['Quantità effettive', 'Actual quantities', 'Ist-Mengen', 'Quantités réelles'],
            'BUDGETING_CONTROL.ADD_ACTUAL_VOLUME' => ['Registra quantità effettiva', 'Record actual quantity', 'Ist-Menge erfassen', 'Saisir une quantité réelle'],
            'BUDGETING_CONTROL.NO_ACTUAL_VOLUMES' => ['Nessuna quantità effettiva registrata.', 'No actual quantities recorded.', 'Keine Ist-Mengen erfasst.', 'Aucune quantité réelle saisie.'],
            'BUDGETING_CONTROL.MONTH' => ['Mese', 'Month', 'Monat', 'Mois'],
            'BUDGETING_CONTROL.VOLUME_SAVED' => ['Quantità effettiva salvata.', 'Actual quantity saved.', 'Ist-Menge gespeichert.', 'Quantité réelle enregistrée.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function spendControl(): array
    {
        return [
            'BUDGETING_CONTROL.TAB_AVAILABILITY' => ['Budget disponibile', 'Available budget', 'Verfügbares Budget', 'Budget disponible'],
            'BUDGETING_CONTROL.TAB_RULES' => ['Regole', 'Rules', 'Regeln', 'Règles'],
            'BUDGETING_CONTROL.TAB_COMMITMENTS' => ['Impegni', 'Commitments', 'Verpflichtungen', 'Engagements'],
            'BUDGETING_CONTROL.TAB_CHECKS' => ['Controlli', 'Controls', 'Kontrollen', 'Contrôles'],
            'BUDGETING_CONTROL.TAB_SETTINGS' => ['Soglie', 'Thresholds', 'Schwellen', 'Seuils'],
            'BUDGETING_CONTROL.AVAILABLE_BUDGET' => ['Budget disponibile di un ambito', 'Available budget of a scope', 'Verfügbares Budget eines Bereichs', 'Budget disponible d’un périmètre'],
            'BUDGETING_CONTROL.SHOW_AVAILABILITY' => ['Calcola', 'Calculate', 'Berechnen', 'Calculer'],
            'BUDGETING_CONTROL.NO_BUDGET_IN_SCOPE' => ['Nessun budget approvato per questo ambito.', 'No approved budget for this scope.', 'Kein genehmigtes Budget für diesen Bereich.', 'Aucun budget approuvé pour ce périmètre.'],
            'BUDGETING_CONTROL.DATE' => ['Data', 'Date', 'Datum', 'Date'],
            'BUDGETING_CONTROL.COMMITTED' => ['Impegnato', 'Committed', 'Verpflichtet', 'Engagé'],
            'BUDGETING_CONTROL.AVAILABLE' => ['Disponibile', 'Available', 'Verfügbar', 'Disponible'],
            'BUDGETING_CONTROL.WHAT_IF' => ['Simulazione di una spesa', 'Spend simulation', 'Ausgabensimulation', 'Simulation d’une dépense'],
            'BUDGETING_CONTROL.DOCUMENT_TYPE' => ['Tipo di documento', 'Document type', 'Dokumenttyp', 'Type de document'],
            'BUDGETING_CONTROL.STAGE' => ['Fase', 'Stage', 'Phase', 'Étape'],
            'BUDGETING_CONTROL.RUN_CHECK' => ['Verifica', 'Check', 'Prüfen', 'Vérifier'],
            'BUDGETING_CONTROL.SHORTFALL' => ['Sforamento', 'Excess', 'Überschreitung', 'Dépassement'],
            'BUDGETING_CONTROL.NEW_RULE' => ['Nuova regola', 'New rule', 'Neue Regel', 'Nouvelle règle'],
            'BUDGETING_CONTROL.EDIT_RULE' => ['Modifica la regola', 'Edit the rule', 'Regel bearbeiten', 'Modifier la règle'],
            'BUDGETING_CONTROL.NO_RULES' => ['Nessuna regola: la spesa non è controllata.', 'No rules: spending is not controlled.', 'Keine Regeln: Ausgaben werden nicht kontrolliert.', 'Aucune règle : les dépenses ne sont pas contrôlées.'],
            'BUDGETING_CONTROL.MODE' => ['Modalità', 'Mode', 'Modus', 'Mode'],
            'BUDGETING_CONTROL.THRESHOLD' => ['Soglia (% del budget)', 'Threshold (% of budget)', 'Schwelle (% des Budgets)', 'Seuil (% du budget)'],
            'BUDGETING_CONTROL.THRESHOLD_HINT' => ['La regola scatta quando costi effettivi + impegni + importo superano questa percentuale del budget.', 'The rule applies when actual costs + commitments + the amount exceed this percentage of the budget.', 'Die Regel greift, wenn Ist-Kosten + Verpflichtungen + Betrag diesen Prozentsatz des Budgets übersteigen.', 'La règle s’applique quand coûts réels + engagements + montant dépassent ce pourcentage du budget.'],
            'BUDGETING_CONTROL.DOCUMENT_TYPES' => ['Documenti', 'Documents', 'Dokumente', 'Documents'],
            'BUDGETING_CONTROL.STAGES' => ['Fasi', 'Stages', 'Phasen', 'Étapes'],
            'BUDGETING_CONTROL.PRIORITY' => ['Priorità', 'Priority', 'Priorität', 'Priorité'],
            'BUDGETING_CONTROL.ACTIVE' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'BUDGETING_CONTROL.ENFORCE_WITHOUT_BUDGET' => ['Controlla anche senza budget', 'Control even without a budget', 'Auch ohne Budget kontrollieren', 'Contrôler même sans budget'],
            'BUDGETING_CONTROL.YES' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'BUDGETING_CONTROL.NO' => ['No', 'No', 'Nein', 'Non'],
            'BUDGETING_CONTROL.RULE_SAVED' => ['Regola salvata.', 'Rule saved.', 'Regel gespeichert.', 'Règle enregistrée.'],
            'BUDGETING_CONTROL.NUMBER' => ['Numero', 'Number', 'Nummer', 'Numéro'],
            'BUDGETING_CONTROL.CONSUMED' => ['Consumato', 'Consumed', 'Verbraucht', 'Consommé'],
            'BUDGETING_CONTROL.RELEASED' => ['Rilasciato', 'Released', 'Freigegeben', 'Libéré'],
            'BUDGETING_CONTROL.OPEN_AMOUNT' => ['Ancora impegnato', 'Still committed', 'Noch verpflichtet', 'Encore engagé'],
            'BUDGETING_CONTROL.NO_COMMITMENTS' => ['Nessun impegno.', 'No commitments.', 'Keine Verpflichtungen.', 'Aucun engagement.'],
            'BUDGETING_CONTROL.OUTCOME' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'BUDGETING_CONTROL.REQUESTED' => ['Richiesto', 'Requested', 'Beantragt', 'Demandé'],
            'BUDGETING_CONTROL.PROJECTED_PERCENT' => ['% del budget', '% of budget', '% des Budgets', '% du budget'],
            'BUDGETING_CONTROL.NO_CHECKS' => ['Nessun controllo con avviso o blocco.', 'No control ended in a warning or a block.', 'Keine Kontrolle mit Warnung oder Sperre.', 'Aucun contrôle avec avertissement ou blocage.'],
            'BUDGETING_CONTROL.AMBER_THRESHOLD' => ['Soglia gialla (% di scostamento)', 'Amber threshold (% variance)', 'Gelbe Schwelle (% Abweichung)', 'Seuil orange (% d’écart)'],
            'BUDGETING_CONTROL.RED_THRESHOLD' => ['Soglia rossa (% di scostamento)', 'Red threshold (% variance)', 'Rote Schwelle (% Abweichung)', 'Seuil rouge (% d’écart)'],
            'BUDGETING_CONTROL.OVERRIDE_VALIDITY_DAYS' => ['Validità predefinita di una deroga (giorni)', 'Default validity of an override (days)', 'Standardgültigkeit einer Ausnahme (Tage)', 'Validité par défaut d’une dérogation (jours)'],
            'BUDGETING_CONTROL.SAVE_SETTINGS' => ['Salva le soglie', 'Save the thresholds', 'Schwellen speichern', 'Enregistrer les seuils'],
            'BUDGETING_CONTROL.SETTINGS_SAVED' => ['Soglie salvate.', 'Thresholds saved.', 'Schwellen gespeichert.', 'Seuils enregistrés.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function overrides(): array
    {
        return [
            'BUDGETING_CONTROL.REQUEST_OVERRIDE' => ['Richiedi una deroga', 'Request an override', 'Ausnahme beantragen', 'Demander une dérogation'],
            'BUDGETING_CONTROL.OVERRIDE_SCOPE' => ['Ambito', 'Scope', 'Geltungsbereich', 'Portée'],
            'BUDGETING_CONTROL.DOCUMENT_ID' => ['Identificativo del documento', 'Document identifier', 'Dokumentkennung', 'Identifiant du document'],
            'BUDGETING_CONTROL.REASON' => ['Motivo', 'Reason', 'Begründung', 'Motif'],
            'BUDGETING_CONTROL.VALID_UNTIL' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valide jusqu’au'],
            'BUDGETING_CONTROL.APPROVED_AMOUNT' => ['Importo approvato', 'Approved amount', 'Genehmigter Betrag', 'Montant approuvé'],
            'BUDGETING_CONTROL.EXPIRED' => ['Scaduta', 'Expired', 'Abgelaufen', 'Expirée'],
            'BUDGETING_CONTROL.APPROVE' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'BUDGETING_CONTROL.REJECT' => ['Rifiuta', 'Reject', 'Ablehnen', 'Refuser'],
            'BUDGETING_CONTROL.CANCEL_OVERRIDE' => ['Annulla', 'Cancel', 'Stornieren', 'Annuler'],
            'BUDGETING_CONTROL.HISTORY' => ['Storico', 'History', 'Verlauf', 'Historique'],
            'BUDGETING_CONTROL.DECISION_APPROVE' => ['Approva la deroga', 'Approve the override', 'Ausnahme genehmigen', 'Approuver la dérogation'],
            'BUDGETING_CONTROL.DECISION_REJECT' => ['Rifiuta la deroga', 'Reject the override', 'Ausnahme ablehnen', 'Refuser la dérogation'],
            'BUDGETING_CONTROL.DECISION_CANCEL' => ['Annulla la deroga', 'Cancel the override', 'Ausnahme stornieren', 'Annuler la dérogation'],
            'BUDGETING_CONTROL.NO_OVERRIDES' => ['Nessuna richiesta di deroga.', 'No override requests.', 'Keine Ausnahmeanträge.', 'Aucune demande de dérogation.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'BUDGETING_CONTROL.REVISION_DONE' => ['Revisione creata in bozza.', 'Revision created as a draft.', 'Revision als Entwurf erstellt.', 'Révision créée en brouillon.'],
            'BUDGETING_CONTROL.SCENARIO_DONE' => ['Scenario creato in bozza.', 'Scenario created as a draft.', 'Szenario als Entwurf erstellt.', 'Scénario créé en brouillon.'],
            'BUDGETING_CONTROL.ROLL_DONE' => ['Rolling forecast avanzato.', 'Rolling forecast rolled forward.', 'Rolling Forecast fortgeschrieben.', 'Rolling forecast avancé.'],
            'BUDGETING_CONTROL.TRANSFER_DONE' => ['Trasferimento registrato.', 'Transfer recorded.', 'Übertragung erfasst.', 'Transfert enregistré.'],
            'BUDGETING_CONTROL.IMPORT_DONE' => ['Budget importato.', 'Budget imported.', 'Budget importiert.', 'Budget importé.'],
            'BUDGETING_CONTROL.OVERRIDE_REQUESTED' => ['Richiesta di deroga inviata.', 'Override request sent.', 'Ausnahmeantrag gesendet.', 'Demande de dérogation envoyée.'],
            'BUDGETING_CONTROL.OVERRIDE_DECIDED' => ['Decisione registrata.', 'Decision recorded.', 'Entscheidung erfasst.', 'Décision enregistrée.'],
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
