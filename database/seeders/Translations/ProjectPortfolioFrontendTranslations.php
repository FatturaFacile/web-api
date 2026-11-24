<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the project portfolio, WBS tree, Gantt (CPM, baselines) and Kanban board (Mod 078). Each entry is
 * [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Placeholders are written {name}. CUP, CIG, SAL and WBS stay untranslated (legal and domain acronyms).
 */
final class ProjectPortfolioFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            // Navigation and page titles
            'PORTFOLIO.NAV' => ['Portafoglio progetti', 'Project portfolio', 'Projektportfolio', 'Portefeuille de projets'],
            'PORTFOLIO.WBS_NAV' => ['WBS e Gantt', 'WBS and Gantt', 'WBS und Gantt', 'WBS et Gantt'],
            'PORTFOLIO.KANBAN_NAV' => ['Kanban', 'Kanban', 'Kanban', 'Kanban'],
            'PORTFOLIO.TITLE' => ['Portafoglio progetti', 'Project portfolio', 'Projektportfolio', 'Portefeuille de projets'],
            'PORTFOLIO.SUBTITLE' => ['Visione d’insieme di commesse, budget e avanzamento', 'Overview of projects, budgets and progress', 'Überblick über Projekte, Budgets und Fortschritt', 'Vue d’ensemble des projets, budgets et avancement'],
            'PORTFOLIO.WORKSPACE' => ['Pianificazione progetto', 'Project planning', 'Projektplanung', 'Planification du projet'],
            'PORTFOLIO.WORKSPACE_SUBTITLE' => ['Struttura WBS, dipendenze e diagramma di Gantt con percorso critico', 'WBS structure, dependencies and Gantt chart with critical path', 'WBS-Struktur, Abhängigkeiten und Gantt-Diagramm mit kritischem Pfad', 'Structure WBS, dépendances et diagramme de Gantt avec chemin critique'],
            'PORTFOLIO.KANBAN_TITLE' => ['Board Kanban delle attività', 'Task Kanban board', 'Kanban-Board der Aufgaben', 'Tableau Kanban des tâches'],
            'PORTFOLIO.KANBAN_SUBTITLE' => ['Sposta le attività WBS tra gli stati di avanzamento', 'Move WBS tasks between progress states', 'WBS-Aufgaben zwischen Fortschrittsstatus verschieben', 'Déplacez les tâches WBS entre les états d’avancement'],
            'PORTFOLIO.BACK_TO_PORTFOLIO' => ['Torna al portafoglio', 'Back to the portfolio', 'Zurück zum Portfolio', 'Retour au portefeuille'],

            // Generic actions and messages
            'PORTFOLIO.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'PORTFOLIO.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'PORTFOLIO.CREATE' => ['Crea', 'Create', 'Erstellen', 'Créer'],
            'PORTFOLIO.EDIT' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'PORTFOLIO.DELETE' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'PORTFOLIO.APPLY' => ['Applica', 'Apply', 'Anwenden', 'Appliquer'],
            'PORTFOLIO.SAVED' => ['Salvato', 'Saved', 'Gespeichert', 'Enregistré'],
            'PORTFOLIO.SAVE_ERROR' => ['Operazione non riuscita', 'The operation failed', 'Der Vorgang ist fehlgeschlagen', 'L’opération a échoué'],
            'PORTFOLIO.LOAD_ERROR' => ['Impossibile caricare i dati', 'The data could not be loaded', 'Die Daten konnten nicht geladen werden', 'Les données n’ont pas pu être chargées'],
            'PORTFOLIO.DAYS' => ['giorni', 'days', 'Tage', 'jours'],

            // Portfolio overview
            'PORTFOLIO.NEW_PROJECT' => ['Nuovo progetto', 'New project', 'Neues Projekt', 'Nouveau projet'],
            'PORTFOLIO.EDIT_PROJECT' => ['Modifica progetto', 'Edit project', 'Projekt bearbeiten', 'Modifier le projet'],
            'PORTFOLIO.FROM_TEMPLATE' => ['Crea da modello', 'Create from template', 'Aus Vorlage erstellen', 'Créer à partir d’un modèle'],
            'PORTFOLIO.CREATED_FROM_TEMPLATE' => ['Progetto creato dal modello', 'Project created from the template', 'Projekt aus der Vorlage erstellt', 'Projet créé à partir du modèle'],
            'PORTFOLIO.EMPTY' => ['Nessun progetto', 'No projects', 'Keine Projekte', 'Aucun projet'],
            'PORTFOLIO.SEARCH' => ['Cerca per codice o nome', 'Search by code or name', 'Nach Code oder Name suchen', 'Rechercher par code ou nom'],
            'PORTFOLIO.FILTER_STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'PORTFOLIO.FILTER_TYPE' => ['Tipo di progetto', 'Project type', 'Projektart', 'Type de projet'],
            'PORTFOLIO.KPI_PROJECTS' => ['Progetti', 'Projects', 'Projekte', 'Projets'],
            'PORTFOLIO.KPI_ACTIVE' => ['Attivi', 'Active', 'Aktiv', 'Actifs'],
            'PORTFOLIO.KPI_ON_HOLD' => ['In pausa', 'On hold', 'Pausiert', 'En pause'],
            'PORTFOLIO.KPI_COMPLETED' => ['Completati', 'Completed', 'Abgeschlossen', 'Terminés'],
            'PORTFOLIO.KPI_PLANNED_COST' => ['Costo pianificato', 'Planned cost', 'Geplante Kosten', 'Coût planifié'],
            'PORTFOLIO.KPI_ACTUAL_COST' => ['Costo effettivo', 'Actual cost', 'Istkosten', 'Coût réel'],
            'PORTFOLIO.KPI_PLANNED_REVENUE' => ['Ricavo pianificato', 'Planned revenue', 'Geplanter Ertrag', 'Revenu planifié'],
            'PORTFOLIO.KPI_PROGRESS' => ['Avanzamento medio', 'Average progress', 'Durchschnittlicher Fortschritt', 'Avancement moyen'],
            'PORTFOLIO.COL_CODE' => ['Codice', 'Code', 'Code', 'Code'],
            'PORTFOLIO.COL_NAME' => ['Nome', 'Name', 'Name', 'Nom'],
            'PORTFOLIO.COL_TYPE' => ['Tipo', 'Type', 'Art', 'Type'],
            'PORTFOLIO.COL_STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'PORTFOLIO.COL_DATES' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'PORTFOLIO.COL_PROGRESS' => ['Avanzamento', 'Progress', 'Fortschritt', 'Avancement'],
            'PORTFOLIO.COL_PLANNED_COST' => ['Costo pianificato', 'Planned cost', 'Geplante Kosten', 'Coût planifié'],
            'PORTFOLIO.COL_ACTUAL_COST' => ['Costo effettivo', 'Actual cost', 'Istkosten', 'Coût réel'],
            'PORTFOLIO.COL_ACTIONS' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'PORTFOLIO.OPEN_WBS' => ['WBS e Gantt', 'WBS and Gantt', 'WBS und Gantt', 'WBS et Gantt'],
            'PORTFOLIO.OPEN_KANBAN' => ['Kanban', 'Kanban', 'Kanban', 'Kanban'],
            'PORTFOLIO.STATUS_CHANGED' => ['Stato aggiornato', 'Status updated', 'Status aktualisiert', 'Statut mis à jour'],

            // Project form
            'PORTFOLIO.F_CODE' => ['Codice progetto', 'Project code', 'Projektcode', 'Code du projet'],
            'PORTFOLIO.F_NAME' => ['Nome', 'Name', 'Name', 'Nom'],
            'PORTFOLIO.F_CONTEXT' => ['Contesto fiscale', 'Fiscal context', 'Steuerlicher Kontext', 'Contexte fiscal'],
            'PORTFOLIO.CONTEXT_IT' => ['Italia (CUP e CIG per commesse pubbliche)', 'Italy (CUP and CIG for public contracts)', 'Italien (CUP und CIG für öffentliche Aufträge)', 'Italie (CUP et CIG pour les marchés publics)'],
            'PORTFOLIO.CONTEXT_CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse'],
            'PORTFOLIO.F_LEGAL_ENTITY' => ['Entità legale (UUID)', 'Legal entity (UUID)', 'Rechtseinheit (UUID)', 'Entité juridique (UUID)'],
            'PORTFOLIO.F_CUSTOMER' => ['Cliente (UUID)', 'Customer (UUID)', 'Kunde (UUID)', 'Client (UUID)'],
            'PORTFOLIO.F_MANAGER' => ['Project manager (UUID)', 'Project manager (UUID)', 'Projektleiter (UUID)', 'Chef de projet (UUID)'],
            'PORTFOLIO.F_TYPE' => ['Tipo di progetto', 'Project type', 'Projektart', 'Type de projet'],
            'PORTFOLIO.F_BILLING' => ['Modello di fatturazione', 'Billing model', 'Abrechnungsmodell', 'Modèle de facturation'],
            'PORTFOLIO.F_COST_CENTER' => ['Centro di costo (UUID)', 'Cost center (UUID)', 'Kostenstelle (UUID)', 'Centre de coûts (UUID)'],
            'PORTFOLIO.F_START' => ['Data di inizio', 'Start date', 'Startdatum', 'Date de début'],
            'PORTFOLIO.F_END' => ['Data di fine', 'End date', 'Enddatum', 'Date de fin'],
            'PORTFOLIO.F_TEMPLATE' => ['Modello di progetto', 'Project template', 'Projektvorlage', 'Modèle de projet'],
            'PORTFOLIO.ERR_UUID' => ['Inserisci un UUID valido', 'Enter a valid UUID', 'Gültige UUID eingeben', 'Saisissez un UUID valide'],
            'PORTFOLIO.ERR_END_BEFORE_START' => ['La fine non può precedere l’inizio', 'The end cannot be before the start', 'Das Ende darf nicht vor dem Beginn liegen', 'La fin ne peut pas précéder le début'],
            'PORTFOLIO.ERR_CUP' => ['Il CUP è composto da 15 caratteri alfanumerici', 'The CUP has 15 alphanumeric characters', 'Der CUP besteht aus 15 alphanumerischen Zeichen', 'Le CUP comporte 15 caractères alphanumériques'],
            'PORTFOLIO.ERR_CIG' => ['Il CIG è composto da 10 caratteri alfanumerici', 'The CIG has 10 alphanumeric characters', 'Der CIG besteht aus 10 alphanumerischen Zeichen', 'Le CIG comporte 10 caractères alphanumériques'],

            // Project types, billing models and statuses
            'PORTFOLIO.TYPE_CLIENT_BILLABLE' => ['Fatturabile al cliente', 'Client billable', 'Kundenverrechenbar', 'Facturable au client'],
            'PORTFOLIO.TYPE_INTERNAL_R_AND_D' => ['R&S interna', 'Internal R&D', 'Interne F&E', 'R&D interne'],
            'PORTFOLIO.TYPE_CAPEX' => ['Investimento (CAPEX)', 'Capital investment (CAPEX)', 'Investition (CAPEX)', 'Investissement (CAPEX)'],
            'PORTFOLIO.BILLING_TIME_AND_MATERIALS' => ['A tempo e materiali', 'Time and materials', 'Aufwand und Material', 'En régie'],
            'PORTFOLIO.BILLING_FIXED_PRICE' => ['A corpo', 'Fixed price', 'Festpreis', 'Prix fixe'],
            'PORTFOLIO.BILLING_SAL_PROGRESS' => ['A SAL (stato avanzamento lavori)', 'Progress billing (SAL)', 'Abrechnung nach Baufortschritt (SAL)', 'Facturation à l’avancement (SAL)'],
            'PORTFOLIO.BILLING_RETAINER' => ['Canone ricorrente', 'Retainer', 'Pauschale (Retainer)', 'Forfait récurrent'],
            'PORTFOLIO.STATUS_PLANNING' => ['In pianificazione', 'Planning', 'In Planung', 'En planification'],
            'PORTFOLIO.STATUS_ACTIVE' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'PORTFOLIO.STATUS_ON_HOLD' => ['In pausa', 'On hold', 'Pausiert', 'En pause'],
            'PORTFOLIO.STATUS_COMPLETED' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'PORTFOLIO.STATUS_CANCELLED' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],

            // Baselines
            'PORTFOLIO.BASELINES' => ['Baseline', 'Baselines', 'Baselines', 'Baselines'],
            'PORTFOLIO.BASELINE_NAME' => ['Nome della baseline', 'Baseline name', 'Name der Baseline', 'Nom de la baseline'],
            'PORTFOLIO.CAPTURE_BASELINE' => ['Cattura baseline', 'Capture baseline', 'Baseline erfassen', 'Capturer la baseline'],
            'PORTFOLIO.BASELINE_CAPTURED' => ['Baseline catturata', 'Baseline captured', 'Baseline erfasst', 'Baseline capturée'],
            'PORTFOLIO.NO_BASELINES' => ['Nessuna baseline catturata', 'No baseline captured yet', 'Noch keine Baseline erfasst', 'Aucune baseline capturée'],
            'PORTFOLIO.CAPTURED_AT' => ['Catturata il', 'Captured on', 'Erfasst am', 'Capturée le'],
            'PORTFOLIO.VARIANCE' => ['Scostamento', 'Variance', 'Abweichung', 'Écart'],
            'PORTFOLIO.PROJECT_END_VARIANCE' => ['Scostamento sulla fine progetto', 'Project end variance', 'Abweichung zum Projektende', 'Écart sur la fin du projet'],
            'PORTFOLIO.VAR_START' => ['Inizio (gg)', 'Start (days)', 'Beginn (Tage)', 'Début (jours)'],
            'PORTFOLIO.VAR_END' => ['Fine (gg)', 'End (days)', 'Ende (Tage)', 'Fin (jours)'],
            'PORTFOLIO.VAR_HOURS' => ['Ore pianificate', 'Planned hours', 'Geplante Stunden', 'Heures planifiées'],
            'PORTFOLIO.VAR_COST' => ['Costo pianificato', 'Planned cost', 'Geplante Kosten', 'Coût planifié'],
            'PORTFOLIO.SAVE_AS_TEMPLATE' => ['Salva come modello', 'Save as template', 'Als Vorlage speichern', 'Enregistrer comme modèle'],
            'PORTFOLIO.F_TEMPLATE_CODE' => ['Codice modello', 'Template code', 'Vorlagencode', 'Code du modèle'],
            'PORTFOLIO.TEMPLATE_SAVED' => ['Modello di progetto salvato', 'Project template saved', 'Projektvorlage gespeichert', 'Modèle de projet enregistré'],
            'PORTFOLIO.COMPARE_BASELINE' => ['Confronta con baseline', 'Compare with baseline', 'Mit Baseline vergleichen', 'Comparer avec la baseline'],

            // WBS tree and workspace
            'PORTFOLIO.WBS' => ['Struttura WBS', 'WBS structure', 'WBS-Struktur', 'Structure WBS'],
            'PORTFOLIO.GANTT' => ['Diagramma di Gantt', 'Gantt chart', 'Gantt-Diagramm', 'Diagramme de Gantt'],
            'PORTFOLIO.NO_NODES' => ['Nessun elemento WBS: aggiungi la prima fase', 'No WBS items: add the first phase', 'Keine WBS-Elemente: erste Phase hinzufügen', 'Aucun élément WBS : ajoutez la première phase'],
            'PORTFOLIO.EXPAND_ALL' => ['Espandi tutto', 'Expand all', 'Alle aufklappen', 'Tout développer'],
            'PORTFOLIO.COLLAPSE_ALL' => ['Comprimi tutto', 'Collapse all', 'Alle zuklappen', 'Tout réduire'],
            'PORTFOLIO.TOGGLE' => ['Espandi o comprimi', 'Expand or collapse', 'Auf- oder zuklappen', 'Développer ou réduire'],
            'PORTFOLIO.ADD_NODE' => ['Aggiungi elemento', 'Add item', 'Element hinzufügen', 'Ajouter un élément'],
            'PORTFOLIO.EDIT_NODE' => ['Modifica elemento WBS', 'Edit WBS item', 'WBS-Element bearbeiten', 'Modifier l’élément WBS'],
            'PORTFOLIO.ADD_CHILD' => ['Aggiungi sotto-elemento', 'Add child item', 'Unterelement hinzufügen', 'Ajouter un sous-élément'],
            'PORTFOLIO.MOVE_UP' => ['Sposta su', 'Move up', 'Nach oben', 'Monter'],
            'PORTFOLIO.MOVE_DOWN' => ['Sposta giù', 'Move down', 'Nach unten', 'Descendre'],
            'PORTFOLIO.INDENT' => ['Rientra (diventa figlio)', 'Indent (make child)', 'Einrücken (Unterelement)', 'Indenter (devenir enfant)'],
            'PORTFOLIO.OUTDENT' => ['Sporgi (sale di livello)', 'Outdent (move up a level)', 'Ausrücken (eine Ebene höher)', 'Désindenter (remonter d’un niveau)'],
            'PORTFOLIO.CONFIRM_DELETE_NODE' => ['Eliminare l’elemento e i suoi sotto-elementi?', 'Delete the item and its children?', 'Element und Unterelemente löschen?', 'Supprimer l’élément et ses sous-éléments ?'],
            'PORTFOLIO.NODE_DELETED' => ['Elemento eliminato', 'Item deleted', 'Element gelöscht', 'Élément supprimé'],
            'PORTFOLIO.RECALCULATE' => ['Ricalcola percorso critico', 'Recalculate critical path', 'Kritischen Pfad neu berechnen', 'Recalculer le chemin critique'],
            'PORTFOLIO.RECALCULATED' => ['Percorso critico ricalcolato', 'Critical path recalculated', 'Kritischer Pfad neu berechnet', 'Chemin critique recalculé'],
            'PORTFOLIO.CRITICAL' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'PORTFOLIO.CRITICAL_NODES' => ['{count} attività critiche', '{count} critical tasks', '{count} kritische Aufgaben', '{count} tâches critiques'],
            'PORTFOLIO.PREVIEW' => ['Anteprima: da confermare', 'Preview: not saved yet', 'Vorschau: noch nicht gespeichert', 'Aperçu : non enregistré'],
            'PORTFOLIO.RESIZE_PANES' => ['Ridimensiona albero e Gantt', 'Resize tree and Gantt panes', 'Baum und Gantt in der Grösse ändern', 'Redimensionner l’arbre et le Gantt'],
            'PORTFOLIO.ZOOM_OUT' => ['Scala ridotta', 'Zoom out', 'Verkleinert', 'Zoom arrière'],
            'PORTFOLIO.ZOOM_NORMAL' => ['Scala normale', 'Normal zoom', 'Normale Ansicht', 'Zoom normal'],
            'PORTFOLIO.ZOOM_IN' => ['Scala ingrandita', 'Zoom in', 'Vergrössert', 'Zoom avant'],
            'PORTFOLIO.COL_HOURS' => ['Ore', 'Hours', 'Stunden', 'Heures'],
            'PORTFOLIO.COL_COST' => ['Costo', 'Cost', 'Kosten', 'Coût'],

            // WBS node form and types
            'PORTFOLIO.F_TITLE' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'PORTFOLIO.F_NODE_TYPE' => ['Tipo di elemento', 'Item type', 'Elementtyp', 'Type d’élément'],
            'PORTFOLIO.F_PARENT' => ['Elemento padre', 'Parent item', 'Übergeordnetes Element', 'Élément parent'],
            'PORTFOLIO.NO_PARENT' => ['Nessuno (primo livello)', 'None (top level)', 'Keines (oberste Ebene)', 'Aucun (premier niveau)'],
            'PORTFOLIO.F_PLANNED_HOURS' => ['Ore pianificate', 'Planned hours', 'Geplante Stunden', 'Heures planifiées'],
            'PORTFOLIO.F_PLANNED_COST' => ['Costo pianificato', 'Planned cost', 'Geplante Kosten', 'Coût planifié'],
            'PORTFOLIO.F_PLANNED_REVENUE' => ['Ricavo pianificato', 'Planned revenue', 'Geplanter Ertrag', 'Revenu planifié'],
            'PORTFOLIO.F_PERCENT' => ['Avanzamento (%)', 'Progress (%)', 'Fortschritt (%)', 'Avancement (%)'],
            'PORTFOLIO.NODE_PHASE' => ['Fase', 'Phase', 'Phase', 'Phase'],
            'PORTFOLIO.NODE_DELIVERABLE' => ['Deliverable', 'Deliverable', 'Liefergegenstand', 'Livrable'],
            'PORTFOLIO.NODE_WORK_PACKAGE' => ['Work package', 'Work package', 'Arbeitspaket', 'Lot de travaux'],
            'PORTFOLIO.NODE_MILESTONE' => ['Milestone', 'Milestone', 'Meilenstein', 'Jalon'],

            // Dependencies
            'PORTFOLIO.DEPENDENCIES' => ['Dipendenze', 'Dependencies', 'Abhängigkeiten', 'Dépendances'],
            'PORTFOLIO.PREDECESSOR' => ['Predecessore', 'Predecessor', 'Vorgänger', 'Prédécesseur'],
            'PORTFOLIO.SUCCESSOR' => ['Successore', 'Successor', 'Nachfolger', 'Successeur'],
            'PORTFOLIO.DEP_TYPE' => ['Tipo', 'Type', 'Typ', 'Type'],
            'PORTFOLIO.DEP_FS' => ['Fine-Inizio (FS)', 'Finish-to-Start (FS)', 'Ende-Anfang (FS)', 'Fin-Début (FS)'],
            'PORTFOLIO.DEP_SS' => ['Inizio-Inizio (SS)', 'Start-to-Start (SS)', 'Anfang-Anfang (SS)', 'Début-Début (SS)'],
            'PORTFOLIO.DEP_FF' => ['Fine-Fine (FF)', 'Finish-to-Finish (FF)', 'Ende-Ende (FF)', 'Fin-Fin (FF)'],
            'PORTFOLIO.DEP_SF' => ['Inizio-Fine (SF)', 'Start-to-Finish (SF)', 'Anfang-Ende (SF)', 'Début-Fin (SF)'],
            'PORTFOLIO.LAG_DAYS' => ['Ritardo/anticipo (gg)', 'Lag/lead (days)', 'Verzögerung/Vorlauf (Tage)', 'Décalage/avance (jours)'],
            'PORTFOLIO.LAG_HINT' => ['Un valore positivo è un ritardo, uno negativo un anticipo. Le dipendenze collegano le attività foglia.', 'A positive value is a lag, a negative one a lead. Dependencies link leaf tasks.', 'Ein positiver Wert ist eine Verzögerung, ein negativer ein Vorlauf. Abhängigkeiten verbinden Blattaufgaben.', 'Une valeur positive est un décalage, une valeur négative une avance. Les dépendances relient les tâches feuilles.'],
            'PORTFOLIO.ADD_DEPENDENCY' => ['Aggiungi dipendenza', 'Add dependency', 'Abhängigkeit hinzufügen', 'Ajouter une dépendance'],
            'PORTFOLIO.NO_DEPENDENCIES' => ['Nessuna dipendenza', 'No dependencies', 'Keine Abhängigkeiten', 'Aucune dépendance'],
            'PORTFOLIO.DEPENDENCY_SAVED' => ['Dipendenza salvata', 'Dependency saved', 'Abhängigkeit gespeichert', 'Dépendance enregistrée'],
            'PORTFOLIO.DEPENDENCY_DELETED' => ['Dipendenza eliminata', 'Dependency deleted', 'Abhängigkeit gelöscht', 'Dépendance supprimée'],
            'PORTFOLIO.DEP_CYCLE' => ['Il collegamento crea un ciclo tra le attività', 'The link would create a loop between tasks', 'Die Verknüpfung würde eine Schleife zwischen Aufgaben erzeugen', 'Le lien créerait une boucle entre les tâches'],

            // Kanban
            'PORTFOLIO.KANBAN_TODO' => ['Da fare', 'To do', 'Zu erledigen', 'À faire'],
            'PORTFOLIO.KANBAN_IN_PROGRESS' => ['In corso', 'In progress', 'In Arbeit', 'En cours'],
            'PORTFOLIO.KANBAN_DONE' => ['Completate', 'Done', 'Erledigt', 'Terminées'],
            'PORTFOLIO.KANBAN_EMPTY' => ['Nessuna attività', 'No tasks', 'Keine Aufgaben', 'Aucune tâche'],
            'PORTFOLIO.SEARCH_TASKS' => ['Cerca attività per codice o titolo', 'Search tasks by code or title', 'Aufgaben nach Code oder Titel suchen', 'Rechercher des tâches par code ou titre'],
            'PORTFOLIO.FILTER_NODE_TYPE' => ['Tipo di elemento', 'Item type', 'Elementtyp', 'Type d’élément'],
            'PORTFOLIO.CRITICAL_ONLY' => ['Solo percorso critico', 'Critical path only', 'Nur kritischer Pfad', 'Chemin critique uniquement'],
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
