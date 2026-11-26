<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the configurable approval engine (Mod 006): the approval inbox with its decision dialogs and request timeline, the
 * workflow designer (canvas and wizard), the approval matrix editor with its simulation, the business calendars, the delegations of
 * the profile, the public confirmation page of an emailed approval link, the approval status banner and the pending counter of the
 * header. The keys are lower-case `workflows.*`; the legacy `WORKFLOWS.*` keys of the older workflow screen live in the
 * Italian/English/German/French frontend dictionaries and never overlap.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, SLA, OTP) stay untranslated.
 */
final class WorkflowApprovalFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::inbox(),
            self::decision(),
            self::status(),
            self::subjectTypes(),
            self::designer(),
            self::matrix(),
            self::calendar(),
            self::delegations(),
            self::token(),
            self::banner(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function inbox(): array
    {
        return [
            'workflows.header.pending' => ['Approvazioni in attesa', 'Pending approvals', 'Ausstehende Genehmigungen', 'Approbations en attente'],
            'workflows.header.pendingCount' => ['{count} approvazioni in attesa', '{count} pending approvals', '{count} ausstehende Genehmigungen', '{count} approbations en attente'],
            'workflows.inbox.title' => ['Le mie approvazioni', 'My approvals', 'Meine Genehmigungen', 'Mes approbations'],
            'workflows.inbox.filter.type' => ['Tipo di documento', 'Document type', 'Dokumenttyp', 'Type de document'],
            'workflows.inbox.filter.due' => ['Scadenza', 'Due state', 'Fälligkeit', 'Échéance'],
            'workflows.inbox.filter.due.all' => ['Tutte', 'All', 'Alle', 'Toutes'],
            'workflows.inbox.filter.due.overdue' => ['In ritardo', 'Overdue', 'Überfällig', 'En retard'],
            'workflows.inbox.filter.due.escalated' => ['Escalate', 'Escalated', 'Eskaliert', 'Escaladées'],
            'workflows.inbox.filter.due.delegated' => ['Delegate a me', 'Delegated to me', 'An mich delegiert', 'Déléguées à moi'],
            'workflows.inbox.column.select' => ['Seleziona', 'Select', 'Auswählen', 'Sélectionner'],
            'workflows.inbox.column.document' => ['Documento', 'Document', 'Dokument', 'Document'],
            'workflows.inbox.column.type' => ['Tipo', 'Type', 'Typ', 'Type'],
            'workflows.inbox.column.step' => ['Fase', 'Step', 'Schritt', 'Étape'],
            'workflows.inbox.column.requester' => ['Richiedente', 'Requester', 'Antragsteller', 'Demandeur'],
            'workflows.inbox.column.due' => ['Scade il', 'Due', 'Fällig am', 'Échéance'],
            'workflows.inbox.column.sla' => ['SLA', 'SLA', 'SLA', 'SLA'],
            'workflows.inbox.column.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'workflows.inbox.delegated' => ['Delega', 'Delegated', 'Delegiert', 'Délégué'],
            'workflows.inbox.escalated' => ['Escalata', 'Escalated', 'Eskaliert', 'Escaladée'],
            'workflows.inbox.sla.none' => ['Nessuna scadenza', 'No due date', 'Keine Frist', 'Pas d’échéance'],
            'workflows.inbox.sla.on_track' => ['Nei tempi', 'On track', 'Im Plan', 'Dans les temps'],
            'workflows.inbox.sla.due_soon' => ['In scadenza', 'Due soon', 'Bald fällig', 'Bientôt échue'],
            'workflows.inbox.sla.overdue' => ['In ritardo', 'Overdue', 'Überfällig', 'En retard'],
            'workflows.inbox.action.bulkApprove' => ['Approva selezionate ({count})', 'Approve selected ({count})', 'Ausgewählte genehmigen ({count})', 'Approuver la sélection ({count})'],
            'workflows.inbox.action.timeline' => ['Cronologia', 'Timeline', 'Verlauf', 'Chronologie'],
            'workflows.inbox.loadError' => ['Impossibile caricare le approvazioni.', 'The approvals could not be loaded.', 'Die Genehmigungen konnten nicht geladen werden.', 'Impossible de charger les approbations.'],
            'workflows.inbox.message.approved' => ['Richiesta approvata.', 'Request approved.', 'Antrag genehmigt.', 'Demande approuvée.'],
            'workflows.inbox.message.rejected' => ['Richiesta rifiutata.', 'Request rejected.', 'Antrag abgelehnt.', 'Demande rejetée.'],
            'workflows.inbox.message.request_changes' => ['Modifiche richieste al richiedente.', 'Changes requested from the requester.', 'Änderungen beim Antragsteller angefordert.', 'Modifications demandées au demandeur.'],
            'workflows.inbox.message.reassign' => ['Richiesta riassegnata.', 'Request reassigned.', 'Antrag neu zugewiesen.', 'Demande réassignée.'],
            'workflows.inbox.message.bulkApproved' => ['Richieste selezionate approvate.', 'Selected requests approved.', 'Ausgewählte Anträge genehmigt.', 'Demandes sélectionnées approuvées.'],
            'workflows.inbox.message.bulkPartial' => ['Alcune richieste non sono state approvate (già decise o non consentite).', 'Some requests were not approved (already decided or not allowed).', 'Einige Anträge wurden nicht genehmigt (bereits entschieden oder nicht erlaubt).', 'Certaines demandes n’ont pas été approuvées (déjà décidées ou non autorisées).'],
            'workflows.inbox.timeline.title' => ['Cronologia della richiesta', 'Request timeline', 'Verlauf des Antrags', 'Chronologie de la demande'],
            'workflows.inbox.timeline.empty' => ['Nessun evento registrato.', 'No events recorded.', 'Keine Ereignisse erfasst.', 'Aucun événement enregistré.'],
            'workflows.inbox.timeline.loadError' => ['Impossibile caricare la cronologia.', 'The timeline could not be loaded.', 'Der Verlauf konnte nicht geladen werden.', 'Impossible de charger la chronologie.'],
            'workflows.inbox.timeline.step' => ['Fase', 'Step', 'Schritt', 'Étape'],
            'workflows.inbox.timeline.actor' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'workflows.inbox.timeline.decidedAt' => ['Deciso il', 'Decided on', 'Entschieden am', 'Décidé le'],
            'workflows.inbox.timeline.dueAt' => ['Scade il', 'Due on', 'Fällig am', 'Échéance le'],
            'workflows.inbox.timeline.type.start' => ['Avvio', 'Start', 'Start', 'Démarrage'],
            'workflows.inbox.timeline.type.approval' => ['Approvazione', 'Approval', 'Genehmigung', 'Approbation'],
            'workflows.inbox.timeline.type.condition' => ['Condizione', 'Condition', 'Bedingung', 'Condition'],
            'workflows.inbox.timeline.type.notification' => ['Notifica', 'Notification', 'Benachrichtigung', 'Notification'],
            'workflows.inbox.timeline.type.escalation' => ['Escalation', 'Escalation', 'Eskalation', 'Escalade'],
            'workflows.inbox.timeline.type.reminder' => ['Promemoria', 'Reminder', 'Erinnerung', 'Rappel'],
            'workflows.inbox.timeline.type.delegation' => ['Delega', 'Delegation', 'Delegation', 'Délégation'],
            'workflows.inbox.timeline.type.end' => ['Fine', 'End', 'Ende', 'Fin'],
            'workflows.inbox.timeline.channel.web' => ['Applicazione web', 'Web application', 'Web-Anwendung', 'Application web'],
            'workflows.inbox.timeline.channel.email' => ['Link e-mail', 'Email link', 'E-Mail-Link', 'Lien e-mail'],
            'workflows.inbox.timeline.channel.mobile' => ['App mobile', 'Mobile app', 'Mobile App', 'Application mobile'],
            'workflows.inbox.timeline.channel.api' => ['API', 'API', 'API', 'API'],
            'workflows.inbox.timeline.channel.sms' => ['SMS', 'SMS', 'SMS', 'SMS'],
            'workflows.inbox.timeline.channel.whatsapp' => ['WhatsApp', 'WhatsApp', 'WhatsApp', 'WhatsApp'],
            'workflows.inbox.timeline.channel.token' => ['Link di approvazione', 'Approval link', 'Genehmigungslink', 'Lien d’approbation'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function decision(): array
    {
        return [
            'workflows.decision.approve' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'workflows.decision.reject' => ['Rifiuta', 'Reject', 'Ablehnen', 'Rejeter'],
            'workflows.decision.requestChanges' => ['Chiedi modifiche', 'Request changes', 'Änderungen anfordern', 'Demander des modifications'],
            'workflows.decision.reassign' => ['Riassegna', 'Reassign', 'Neu zuweisen', 'Réassigner'],
            'workflows.decision.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'workflows.decision.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'workflows.decision.comment' => ['Motivazione', 'Reason', 'Begründung', 'Motif'],
            'workflows.decision.reassignTo' => ['Riassegna a', 'Reassign to', 'Neu zuweisen an', 'Réassigner à'],
            'workflows.decision.reassignPlaceholder' => ['Cerca un utente', 'Search for a user', 'Benutzer suchen', 'Rechercher un utilisateur'],
            'workflows.decision.dialogTitle.approved' => ['Approva la richiesta', 'Approve the request', 'Antrag genehmigen', 'Approuver la demande'],
            'workflows.decision.dialogTitle.rejected' => ['Rifiuta la richiesta', 'Reject the request', 'Antrag ablehnen', 'Rejeter la demande'],
            'workflows.decision.dialogTitle.request_changes' => ['Chiedi modifiche al richiedente', 'Request changes from the requester', 'Änderungen beim Antragsteller anfordern', 'Demander des modifications au demandeur'],
            'workflows.decision.dialogTitle.reassign' => ['Riassegna la richiesta', 'Reassign the request', 'Antrag neu zuweisen', 'Réassigner la demande'],
            'workflows.decision.alreadyDecided' => ['La richiesta è già stata decisa da un altro utente.', 'The request has already been decided by someone else.', 'Der Antrag wurde bereits von jemand anderem entschieden.', 'La demande a déjà été décidée par quelqu’un d’autre.'],
            'workflows.decision.error.commentRequired' => ['La motivazione è obbligatoria.', 'A reason is required.', 'Eine Begründung ist erforderlich.', 'Un motif est obligatoire.'],
            'workflows.decision.error.reassignRequired' => ['Scegli l’utente a cui riassegnare.', 'Choose the user to reassign to.', 'Wählen Sie den Benutzer für die Neuzuweisung.', 'Choisissez l’utilisateur à qui réassigner.'],
            'workflows.decision.error.forbidden' => ['Non puoi decidere questa richiesta (separazione dei compiti o limite di firma).', 'You cannot decide this request (separation of duties or signing limit).', 'Sie dürfen diesen Antrag nicht entscheiden (Funktionstrennung oder Zeichnungslimit).', 'Vous ne pouvez pas décider cette demande (séparation des tâches ou limite de signature).'],
            'workflows.decision.error.generic' => ['Impossibile registrare la decisione.', 'The decision could not be recorded.', 'Die Entscheidung konnte nicht erfasst werden.', 'Impossible d’enregistrer la décision.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function status(): array
    {
        return [
            'workflows.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'workflows.status.published' => ['Pubblicato', 'Published', 'Veröffentlicht', 'Publié'],
            'workflows.status.pending' => ['In attesa', 'Pending', 'Ausstehend', 'En attente'],
            'workflows.status.in_progress' => ['In corso', 'In progress', 'In Bearbeitung', 'En cours'],
            'workflows.status.approved' => ['Approvato', 'Approved', 'Genehmigt', 'Approuvé'],
            'workflows.status.rejected' => ['Rifiutato', 'Rejected', 'Abgelehnt', 'Rejeté'],
            'workflows.status.completed' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'workflows.status.cancelled' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],
            'workflows.status.withdrawn' => ['Ritirato', 'Withdrawn', 'Zurückgezogen', 'Retiré'],
            'workflows.status.request_changes' => ['Modifiche richieste', 'Changes requested', 'Änderungen angefordert', 'Modifications demandées'],
            'workflows.status.changes_requested' => ['Modifiche richieste', 'Changes requested', 'Änderungen angefordert', 'Modifications demandées'],
            'workflows.status.reassigned' => ['Riassegnato', 'Reassigned', 'Neu zugewiesen', 'Réassigné'],
            'workflows.status.escalated' => ['Escalato', 'Escalated', 'Eskaliert', 'Escaladé'],
            'workflows.status.skipped' => ['Saltato', 'Skipped', 'Übersprungen', 'Ignoré'],
            'workflows.status.expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function subjectTypes(): array
    {
        return [
            'workflows.subject_types.sales_quote' => ['Preventivo di vendita', 'Sales quote', 'Verkaufsofferte', 'Devis de vente'],
            'workflows.subject_types.opportunity' => ['Opportunità', 'Opportunity', 'Verkaufschance', 'Opportunité'],
            'workflows.subject_types.purchase_order' => ['Ordine d’acquisto', 'Purchase order', 'Bestellung', 'Commande d’achat'],
            'workflows.subject_types.purchase_requisition' => ['Richiesta d’acquisto', 'Purchase requisition', 'Bestellanforderung', 'Demande d’achat'],
            'workflows.subject_types.passive_invoice' => ['Fattura passiva', 'Supplier invoice', 'Lieferantenrechnung', 'Facture fournisseur'],
            'workflows.subject_types.inventory_adjustment' => ['Rettifica di inventario', 'Inventory adjustment', 'Bestandskorrektur', 'Ajustement d’inventaire'],
            'workflows.subject_types.project' => ['Progetto', 'Project', 'Projekt', 'Projet'],
            'workflows.subject_types.project_budget' => ['Budget di progetto', 'Project budget', 'Projektbudget', 'Budget de projet'],
            'workflows.subject_types.project_change_order' => ['Variante di progetto', 'Project change order', 'Projektänderungsauftrag', 'Avenant de projet'],
            'workflows.subject_types.project_cost_entry' => ['Costo di progetto', 'Project cost entry', 'Projektkostenbuchung', 'Écriture de coût de projet'],
            'workflows.subject_types.leave_request' => ['Richiesta di ferie o permesso', 'Leave request', 'Urlaubs- oder Absenzantrag', 'Demande de congé ou d’absence'],
            'workflows.subject_types.expense_report' => ['Nota spese', 'Expense report', 'Spesenabrechnung', 'Note de frais'],
            'workflows.subject_types.dms_document' => ['Documento DMS', 'DMS document', 'DMS-Dokument', 'Document GED'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function designer(): array
    {
        return [
            'workflows.designer.title' => ['Designer dei flussi di approvazione', 'Approval workflow designer', 'Designer für Genehmigungsworkflows', 'Concepteur de flux d’approbation'],
            'workflows.designer.mode.canvas' => ['Editor grafico', 'Visual editor', 'Grafischer Editor', 'Éditeur visuel'],
            'workflows.designer.mode.wizard' => ['Procedura guidata', 'Guided wizard', 'Geführter Assistent', 'Assistant guidé'],
            'workflows.designer.selectDefinition' => ['Scegli un flusso', 'Choose a workflow', 'Workflow wählen', 'Choisir un flux'],
            'workflows.designer.publish' => ['Pubblica', 'Publish', 'Veröffentlichen', 'Publier'],
            'workflows.designer.published' => ['Flusso pubblicato.', 'Workflow published.', 'Workflow veröffentlicht.', 'Flux publié.'],
            'workflows.designer.publishError' => ['Impossibile pubblicare il flusso.', 'The workflow could not be published.', 'Der Workflow konnte nicht veröffentlicht werden.', 'Impossible de publier le flux.'],
            'workflows.designer.problems' => ['Il flusso non è ancora pubblicabile:', 'The workflow cannot be published yet:', 'Der Workflow kann noch nicht veröffentlicht werden:', 'Le flux ne peut pas encore être publié :'],
            'workflows.designer.problem.start' => ['Serve esattamente un nodo di avvio.', 'Exactly one start node is required.', 'Es wird genau ein Startknoten benötigt.', 'Un seul nœud de départ est requis.'],
            'workflows.designer.problem.end' => ['Serve almeno un nodo finale.', 'At least one end node is required.', 'Mindestens ein Endknoten wird benötigt.', 'Au moins un nœud de fin est requis.'],
            'workflows.designer.problem.approval' => ['Serve almeno un nodo di approvazione.', 'At least one approval node is required.', 'Mindestens ein Genehmigungsknoten wird benötigt.', 'Au moins un nœud d’approbation est requis.'],
            'workflows.designer.problem.disconnected' => ['Ci sono nodi non collegati.', 'Some nodes are not connected.', 'Einige Knoten sind nicht verbunden.', 'Certains nœuds ne sont pas reliés.'],
            'workflows.designer.palette' => ['Nodi', 'Nodes', 'Knoten', 'Nœuds'],
            'workflows.designer.canvas' => ['Area di disegno del flusso', 'Workflow canvas', 'Workflow-Zeichenfläche', 'Zone de dessin du flux'],
            'workflows.designer.nodeType.start' => ['Avvio', 'Start', 'Start', 'Départ'],
            'workflows.designer.nodeType.approval' => ['Approvazione', 'Approval', 'Genehmigung', 'Approbation'],
            'workflows.designer.nodeType.condition' => ['Condizione', 'Condition', 'Bedingung', 'Condition'],
            'workflows.designer.nodeType.parallel_split' => ['Divisione parallela', 'Parallel split', 'Parallele Verzweigung', 'Séparation parallèle'],
            'workflows.designer.nodeType.parallel_join' => ['Unione parallela', 'Parallel join', 'Parallele Zusammenführung', 'Jonction parallèle'],
            'workflows.designer.nodeType.notification' => ['Notifica', 'Notification', 'Benachrichtigung', 'Notification'],
            'workflows.designer.nodeType.end' => ['Fine', 'End', 'Ende', 'Fin'],
            'workflows.designer.connect' => ['Collega due nodi', 'Connect two nodes', 'Zwei Knoten verbinden', 'Relier deux nœuds'],
            'workflows.designer.from' => ['Da', 'From', 'Von', 'De'],
            'workflows.designer.to' => ['A', 'To', 'Nach', 'Vers'],
            'workflows.designer.addTransition' => ['Aggiungi transizione', 'Add transition', 'Übergang hinzufügen', 'Ajouter une transition'],
            'workflows.designer.transitions' => ['Transizioni', 'Transitions', 'Übergänge', 'Transitions'],
            'workflows.designer.deleteTransition' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'workflows.designer.inspector' => ['Proprietà del nodo', 'Node properties', 'Knoteneigenschaften', 'Propriétés du nœud'],
            'workflows.designer.selectNode' => ['Seleziona un nodo per modificarlo.', 'Select a node to edit it.', 'Wählen Sie einen Knoten zum Bearbeiten.', 'Sélectionnez un nœud pour le modifier.'],
            'workflows.designer.nodeName' => ['Nome', 'Name', 'Name', 'Nom'],
            'workflows.designer.positionX' => ['Posizione orizzontale', 'Horizontal position', 'Horizontale Position', 'Position horizontale'],
            'workflows.designer.positionY' => ['Posizione verticale', 'Vertical position', 'Vertikale Position', 'Position verticale'],
            'workflows.designer.config' => ['Configurazione (JSON)', 'Configuration (JSON)', 'Konfiguration (JSON)', 'Configuration (JSON)'],
            'workflows.designer.configInvalid' => ['La configurazione deve essere un oggetto JSON valido.', 'The configuration must be a valid JSON object.', 'Die Konfiguration muss ein gültiges JSON-Objekt sein.', 'La configuration doit être un objet JSON valide.'],
            'workflows.designer.saveNode' => ['Salva nodo', 'Save node', 'Knoten speichern', 'Enregistrer le nœud'],
            'workflows.designer.deleteNode' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'workflows.designer.saved' => ['Modifiche salvate.', 'Changes saved.', 'Änderungen gespeichert.', 'Modifications enregistrées.'],
            'workflows.designer.saveError' => ['Impossibile salvare le modifiche.', 'The changes could not be saved.', 'Die Änderungen konnten nicht gespeichert werden.', 'Impossible d’enregistrer les modifications.'],
            'workflows.designer.loadError' => ['Impossibile caricare i flussi.', 'The workflows could not be loaded.', 'Die Workflows konnten nicht geladen werden.', 'Impossible de charger les flux.'],
            'workflows.designer.wizard.basics' => ['Dati generali', 'Basics', 'Grunddaten', 'Informations générales'],
            'workflows.designer.wizard.steps' => ['Fasi di approvazione', 'Approval steps', 'Genehmigungsschritte', 'Étapes d’approbation'],
            'workflows.designer.wizard.review' => ['Riepilogo', 'Review', 'Zusammenfassung', 'Récapitulatif'],
            'workflows.designer.wizard.code' => ['Codice (maiuscolo, es. ORDER_APPROVAL)', 'Code (upper case, e.g. ORDER_APPROVAL)', 'Code (Grossbuchstaben, z. B. ORDER_APPROVAL)', 'Code (majuscules, p. ex. ORDER_APPROVAL)'],
            'workflows.designer.wizard.name' => ['Nome del flusso', 'Workflow name', 'Name des Workflows', 'Nom du flux'],
            'workflows.designer.wizard.subjectType' => ['Tipo di documento da approvare', 'Document type to approve', 'Zu genehmigender Dokumenttyp', 'Type de document à approuver'],
            'workflows.designer.wizard.stepsHint' => ['Elenca le fasi nell’ordine in cui devono approvare.', 'List the steps in the order in which they approve.', 'Listen Sie die Schritte in der Reihenfolge der Genehmigung auf.', 'Listez les étapes dans l’ordre d’approbation.'],
            'workflows.designer.wizard.stepName' => ['Nome della fase', 'Step name', 'Name des Schritts', 'Nom de l’étape'],
            'workflows.designer.wizard.addStep' => ['Aggiungi fase', 'Add step', 'Schritt hinzufügen', 'Ajouter une étape'],
            'workflows.designer.wizard.up' => ['Su', 'Up', 'Nach oben', 'Monter'],
            'workflows.designer.wizard.down' => ['Giù', 'Down', 'Nach unten', 'Descendre'],
            'workflows.designer.wizard.reviewHint' => ['Verranno creati un nodo di avvio, queste fasi di approvazione in sequenza e un nodo finale.', 'A start node, these approval steps in sequence and an end node will be created.', 'Es werden ein Startknoten, diese Genehmigungsschritte nacheinander und ein Endknoten erstellt.', 'Un nœud de départ, ces étapes d’approbation en séquence et un nœud de fin seront créés.'],
            'workflows.designer.wizard.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'workflows.designer.wizard.next' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'workflows.designer.wizard.finish' => ['Crea il flusso', 'Create the workflow', 'Workflow erstellen', 'Créer le flux'],
            'workflows.designer.wizard.created' => ['Flusso creato: controllalo e pubblicalo.', 'Workflow created: review and publish it.', 'Workflow erstellt: prüfen und veröffentlichen Sie ihn.', 'Flux créé : vérifiez-le et publiez-le.'],
            'workflows.designer.wizard.error' => ['Impossibile creare il flusso.', 'The workflow could not be created.', 'Der Workflow konnte nicht erstellt werden.', 'Impossible de créer le flux.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function matrix(): array
    {
        return array_merge(self::matrixLabels(), self::matrixEnumerations(), self::matrixMessages());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function matrixLabels(): array
    {
        return [
            'workflows.matrix.title' => ['Matrici di approvazione', 'Approval matrices', 'Genehmigungsmatrizen', 'Matrices d’approbation'],
            'workflows.matrix.list' => ['Matrici definite', 'Defined matrices', 'Definierte Matrizen', 'Matrices définies'],
            'workflows.matrix.editor' => ['Modifica matrice', 'Edit matrix', 'Matrix bearbeiten', 'Modifier la matrice'],
            'workflows.matrix.new' => ['Nuova matrice', 'New matrix', 'Neue Matrix', 'Nouvelle matrice'],
            'workflows.matrix.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'workflows.matrix.priority' => ['Priorità', 'Priority', 'Priorität', 'Priorité'],
            'workflows.matrix.code' => ['Codice', 'Code', 'Code', 'Code'],
            'workflows.matrix.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'workflows.matrix.subjectType' => ['Tipo di documento', 'Document type', 'Dokumenttyp', 'Type de document'],
            'workflows.matrix.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'workflows.matrix.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'workflows.matrix.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'workflows.matrix.inactive' => ['Non attiva', 'Inactive', 'Inaktiv', 'Inactive'],
            'workflows.matrix.default' => ['Predefinita', 'Default', 'Standard', 'Par défaut'],
            'workflows.matrix.autoApproveBelow' => ['Approva automaticamente sotto', 'Auto-approve below', 'Automatisch genehmigen unter', 'Approuver automatiquement en dessous de'],
            'workflows.matrix.conditions' => ['Condizioni', 'Conditions', 'Bedingungen', 'Conditions'],
            'workflows.matrix.conditionsHint' => ['La matrice si applica solo se tutte le condizioni sono vere.', 'The matrix applies only when all conditions hold.', 'Die Matrix gilt nur, wenn alle Bedingungen erfüllt sind.', 'La matrice ne s’applique que si toutes les conditions sont remplies.'],
            'workflows.matrix.field' => ['Campo', 'Field', 'Feld', 'Champ'],
            'workflows.matrix.operator' => ['Operatore', 'Operator', 'Operator', 'Opérateur'],
            'workflows.matrix.value' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'workflows.matrix.valueHint' => ['Elenco separato da virgole per in / non in; min, max per tra', 'Comma-separated list for in / not in; min, max for between', 'Kommagetrennte Liste für in / nicht in; min, max für zwischen', 'Liste séparée par des virgules pour dans / hors de ; min, max pour entre'],
            'workflows.matrix.addCondition' => ['Aggiungi condizione', 'Add condition', 'Bedingung hinzufügen', 'Ajouter une condition'],
            'workflows.matrix.tiers' => ['Livelli di approvazione', 'Approval tiers', 'Genehmigungsstufen', 'Niveaux d’approbation'],
            'workflows.matrix.tiersHint' => ['I livelli si eseguono in sequenza; i livelli con lo stesso numero approvano in parallelo. Le soglie sono incluse; vuoto significa illimitato.', 'Levels run in sequence; tiers with the same level approve in parallel. Bounds are inclusive; empty means open.', 'Die Stufen laufen nacheinander; Stufen mit derselben Nummer genehmigen parallel. Grenzen sind inklusive; leer bedeutet offen.', 'Les niveaux s’exécutent en séquence ; les niveaux identiques approuvent en parallèle. Les bornes sont incluses ; vide signifie illimité.'],
            'workflows.matrix.tierLevel' => ['Livello', 'Level', 'Stufe', 'Niveau'],
            'workflows.matrix.thresholdMin' => ['Importo da', 'Amount from', 'Betrag von', 'Montant de'],
            'workflows.matrix.thresholdMax' => ['Importo fino a', 'Amount up to', 'Betrag bis', 'Montant jusqu’à'],
            'workflows.matrix.approverType' => ['Tipo di approvatore', 'Approver type', 'Genehmigertyp', 'Type d’approbateur'],
            'workflows.matrix.approverValue' => ['Ruolo o utente', 'Role or user', 'Rolle oder Benutzer', 'Rôle ou utilisateur'],
            'workflows.matrix.approvalType' => ['Modalità di approvazione', 'Approval mode', 'Genehmigungsmodus', 'Mode d’approbation'],
            'workflows.matrix.fallback' => ['Approvatore di riserva', 'Fallback approver', 'Ersatzgenehmiger', 'Approbateur de secours'],
            'workflows.matrix.noFallback' => ['Nessun approvatore di riserva', 'No fallback approver', 'Kein Ersatzgenehmiger', 'Aucun approbateur de secours'],
            'workflows.matrix.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'workflows.matrix.addTier' => ['Aggiungi livello', 'Add tier', 'Stufe hinzufügen', 'Ajouter un niveau'],
            'workflows.matrix.save' => ['Salva matrice', 'Save matrix', 'Matrix speichern', 'Enregistrer la matrice'],
            'workflows.matrix.fixErrors' => ['Correggi questi punti prima di salvare:', 'Fix these points before saving:', 'Beheben Sie diese Punkte vor dem Speichern:', 'Corrigez ces points avant d’enregistrer :'],
            'workflows.matrix.simulate.title' => ['Simulazione', 'Simulation', 'Simulation', 'Simulation'],
            'workflows.matrix.simulate.run' => ['Simula', 'Simulate', 'Simulieren', 'Simuler'],
            'workflows.matrix.simulate.matched' => ['Matrice applicata: {name}', 'Matrix applied: {name}', 'Angewandte Matrix: {name}', 'Matrice appliquée : {name}'],
            'workflows.matrix.simulate.autoApproved' => ['Approvazione automatica', 'Auto-approved', 'Automatisch genehmigt', 'Approuvé automatiquement'],
            'workflows.matrix.simulate.usedDefault' => ['Matrice predefinita', 'Default matrix used', 'Standardmatrix verwendet', 'Matrice par défaut utilisée'],
            'workflows.matrix.simulate.usedFallback' => ['Approvatore di riserva', 'Fallback approver used', 'Ersatzgenehmiger verwendet', 'Approbateur de secours utilisé'],
            'workflows.matrix.simulate.blocked' => ['Bloccata: nessun approvatore risolvibile', 'Blocked: no approver can be resolved', 'Blockiert: kein Genehmiger auflösbar', 'Bloquée : aucun approbateur résolu'],
            'workflows.matrix.simulate.noMatch' => ['Nessuna matrice applicabile', 'No matrix applies', 'Keine Matrix anwendbar', 'Aucune matrice applicable'],
            'workflows.matrix.simulate.tier' => ['Livello {level}', 'Level {level}', 'Stufe {level}', 'Niveau {level}'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function matrixEnumerations(): array
    {
        return [
            'workflows.matrix.field.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'workflows.matrix.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'workflows.matrix.field.cost_center_id' => ['Centro di costo', 'Cost center', 'Kostenstelle', 'Centre de coûts'],
            'workflows.matrix.field.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'workflows.matrix.field.new_supplier' => ['Nuovo fornitore', 'New supplier', 'Neuer Lieferant', 'Nouveau fournisseur'],
            'workflows.matrix.field.discount_percent' => ['Sconto %', 'Discount %', 'Rabatt %', 'Remise %'],
            'workflows.matrix.field.company_id' => ['Società', 'Company', 'Gesellschaft', 'Société'],
            'workflows.matrix.operator.eq' => ['uguale a', 'equals', 'gleich', 'égal à'],
            'workflows.matrix.operator.neq' => ['diverso da', 'does not equal', 'ungleich', 'différent de'],
            'workflows.matrix.operator.gt' => ['maggiore di', 'greater than', 'grösser als', 'supérieur à'],
            'workflows.matrix.operator.gte' => ['maggiore o uguale a', 'greater than or equal to', 'grösser oder gleich', 'supérieur ou égal à'],
            'workflows.matrix.operator.lt' => ['minore di', 'less than', 'kleiner als', 'inférieur à'],
            'workflows.matrix.operator.lte' => ['minore o uguale a', 'less than or equal to', 'kleiner oder gleich', 'inférieur ou égal à'],
            'workflows.matrix.operator.in' => ['in elenco', 'in list', 'in Liste', 'dans la liste'],
            'workflows.matrix.operator.not_in' => ['non in elenco', 'not in list', 'nicht in Liste', 'hors de la liste'],
            'workflows.matrix.operator.between' => ['tra', 'between', 'zwischen', 'entre'],
            'workflows.matrix.approver.role' => ['Ruolo', 'Role', 'Rolle', 'Rôle'],
            'workflows.matrix.approver.user' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'workflows.matrix.approver.reporting_manager' => ['Responsabile diretto', 'Reporting manager', 'Vorgesetzte Person', 'Responsable hiérarchique'],
            'workflows.matrix.approval.single' => ['Un approvatore', 'Single approver', 'Ein Genehmiger', 'Un approbateur'],
            'workflows.matrix.approval.and_parallel' => ['Tutti in parallelo', 'All in parallel', 'Alle parallel', 'Tous en parallèle'],
            'workflows.matrix.approval.or_parallel' => ['Uno qualsiasi in parallelo', 'Any one in parallel', 'Einer von mehreren parallel', 'N’importe lequel en parallèle'],
            'workflows.matrix.approval.majority' => ['Maggioranza', 'Majority', 'Mehrheit', 'Majorité'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function matrixMessages(): array
    {
        return [
            'workflows.matrix.saved' => ['Matrice salvata.', 'Matrix saved.', 'Matrix gespeichert.', 'Matrice enregistrée.'],
            'workflows.matrix.saveError' => ['Impossibile salvare la matrice.', 'The matrix could not be saved.', 'Die Matrix konnte nicht gespeichert werden.', 'Impossible d’enregistrer la matrice.'],
            'workflows.matrix.simulate.error' => ['Simulazione non riuscita.', 'The simulation failed.', 'Die Simulation ist fehlgeschlagen.', 'La simulation a échoué.'],
            'workflows.matrix.error.nameRequired' => ['Codice, nome e tipo di documento sono obbligatori.', 'Code, name and document type are required.', 'Code, Name und Dokumenttyp sind erforderlich.', 'Le code, le nom et le type de document sont obligatoires.'],
            'workflows.matrix.error.currency' => ['La valuta deve avere tre lettere (es. CHF).', 'The currency must have three letters (e.g. CHF).', 'Die Währung muss drei Buchstaben haben (z. B. CHF).', 'La devise doit comporter trois lettres (p. ex. CHF).'],
            'workflows.matrix.error.priority' => ['La priorità deve essere almeno 1.', 'The priority must be at least 1.', 'Die Priorität muss mindestens 1 sein.', 'La priorité doit être au moins 1.'],
            'workflows.matrix.error.conditionValue' => ['Ogni condizione richiede un valore.', 'Every condition needs a value.', 'Jede Bedingung braucht einen Wert.', 'Chaque condition nécessite une valeur.'],
            'workflows.matrix.error.between' => ['La condizione «tra» richiede due valori: minimo e massimo.', 'The “between” condition needs two values: minimum and maximum.', 'Die Bedingung «zwischen» braucht zwei Werte: Minimum und Maximum.', 'La condition « entre » nécessite deux valeurs : minimum et maximum.'],
            'workflows.matrix.error.tiersRequired' => ['Serve almeno un livello di approvazione.', 'At least one approval tier is required.', 'Mindestens eine Genehmigungsstufe ist erforderlich.', 'Au moins un niveau d’approbation est requis.'],
            'workflows.matrix.error.tierLevel' => ['Il livello deve essere almeno 1.', 'The level must be at least 1.', 'Die Stufe muss mindestens 1 sein.', 'Le niveau doit être au moins 1.'],
            'workflows.matrix.error.approverRequired' => ['Indica il ruolo o l’utente di ogni livello.', 'Enter the role or user of every tier.', 'Geben Sie Rolle oder Benutzer jeder Stufe an.', 'Indiquez le rôle ou l’utilisateur de chaque niveau.'],
            'workflows.matrix.error.thresholdRange' => ['L’importo iniziale non può superare quello finale.', 'The lower amount cannot exceed the upper amount.', 'Der untere Betrag darf den oberen nicht übersteigen.', 'Le montant minimal ne peut pas dépasser le montant maximal.'],
            'workflows.matrix.error.fallbackRequired' => ['Indica il ruolo o l’utente di riserva.', 'Enter the fallback role or user.', 'Geben Sie die Ersatzrolle oder den Ersatzbenutzer an.', 'Indiquez le rôle ou l’utilisateur de secours.'],
            'workflows.matrix.loadError' => ['Impossibile caricare le matrici.', 'The matrices could not be loaded.', 'Die Matrizen konnten nicht geladen werden.', 'Impossible de charger les matrices.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function calendar(): array
    {
        return [
            'workflows.calendar.title' => ['Calendari lavorativi', 'Business calendars', 'Geschäftskalender', 'Calendriers ouvrables'],
            'workflows.calendar.select' => ['Scegli un calendario', 'Choose a calendar', 'Kalender wählen', 'Choisir un calendrier'],
            'workflows.calendar.new' => ['Nuovo calendario', 'New calendar', 'Neuer Kalender', 'Nouveau calendrier'],
            'workflows.calendar.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'workflows.calendar.timezone' => ['Fuso orario', 'Time zone', 'Zeitzone', 'Fuseau horaire'],
            'workflows.calendar.hoursStart' => ['Inizio giornata', 'Start of day', 'Arbeitsbeginn', 'Début de journée'],
            'workflows.calendar.hoursEnd' => ['Fine giornata', 'End of day', 'Arbeitsende', 'Fin de journée'],
            'workflows.calendar.default' => ['Calendario predefinito', 'Default calendar', 'Standardkalender', 'Calendrier par défaut'],
            'workflows.calendar.day.monday' => ['Lunedì', 'Monday', 'Montag', 'Lundi'],
            'workflows.calendar.day.tuesday' => ['Martedì', 'Tuesday', 'Dienstag', 'Mardi'],
            'workflows.calendar.day.wednesday' => ['Mercoledì', 'Wednesday', 'Mittwoch', 'Mercredi'],
            'workflows.calendar.day.thursday' => ['Giovedì', 'Thursday', 'Donnerstag', 'Jeudi'],
            'workflows.calendar.day.friday' => ['Venerdì', 'Friday', 'Freitag', 'Vendredi'],
            'workflows.calendar.day.saturday' => ['Sabato', 'Saturday', 'Samstag', 'Samedi'],
            'workflows.calendar.day.sunday' => ['Domenica', 'Sunday', 'Sonntag', 'Dimanche'],
            'workflows.calendar.save' => ['Salva calendario', 'Save calendar', 'Kalender speichern', 'Enregistrer le calendrier'],
            'workflows.calendar.saved' => ['Calendario salvato.', 'Calendar saved.', 'Kalender gespeichert.', 'Calendrier enregistré.'],
            'workflows.calendar.saveError' => ['Impossibile salvare il calendario.', 'The calendar could not be saved.', 'Der Kalender konnte nicht gespeichert werden.', 'Impossible d’enregistrer le calendrier.'],
            'workflows.calendar.loadError' => ['Impossibile caricare i calendari.', 'The calendars could not be loaded.', 'Die Kalender konnten nicht geladen werden.', 'Impossible de charger les calendriers.'],
            'workflows.calendar.holidays' => ['Giorni festivi', 'Holidays', 'Feiertage', 'Jours fériés'],
            'workflows.calendar.addHoliday' => ['Aggiungi giorno festivo', 'Add a holiday', 'Feiertag hinzufügen', 'Ajouter un jour férié'],
            'workflows.calendar.date' => ['Data', 'Date', 'Datum', 'Date'],
            'workflows.calendar.holidayName' => ['Descrizione', 'Description', 'Bezeichnung', 'Description'],
            'workflows.calendar.holidayType' => ['Tipo', 'Type', 'Typ', 'Type'],
            'workflows.calendar.type.national' => ['Nazionale', 'National', 'National', 'National'],
            'workflows.calendar.type.cantonal' => ['Cantonale', 'Cantonal', 'Kantonal', 'Cantonal'],
            'workflows.calendar.type.patron_saint' => ['Santo patrono', 'Patron saint', 'Schutzpatron', 'Saint patron'],
            'workflows.calendar.type.company' => ['Aziendale', 'Company', 'Betrieblich', 'D’entreprise'],
            'workflows.calendar.add' => ['Aggiungi', 'Add', 'Hinzufügen', 'Ajouter'],
            'workflows.calendar.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'workflows.calendar.holidayError' => ['Operazione sui giorni festivi non riuscita.', 'The holiday operation failed.', 'Der Vorgang für den Feiertag ist fehlgeschlagen.', 'L’opération sur le jour férié a échoué.'],
            'workflows.calendar.seed.title' => ['Importa i festivi ufficiali', 'Seed the official holidays', 'Offizielle Feiertage importieren', 'Importer les jours fériés officiels'],
            'workflows.calendar.seed.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'workflows.calendar.seed.canton' => ['Cantone', 'Canton', 'Kanton', 'Canton'],
            'workflows.calendar.seed.patronSaint' => ['Santo patrono (città)', 'Patron saint (city)', 'Schutzpatron (Stadt)', 'Saint patron (ville)'],
            'workflows.calendar.seed.year' => ['Anno', 'Year', 'Jahr', 'Année'],
            'workflows.calendar.seed.run' => ['Importa', 'Seed holidays', 'Importieren', 'Importer'],
            'workflows.calendar.seed.done' => ['Giorni festivi importati.', 'Holidays imported.', 'Feiertage importiert.', 'Jours fériés importés.'],
            'workflows.calendar.seed.error' => ['Impossibile importare i giorni festivi.', 'The holidays could not be imported.', 'Die Feiertage konnten nicht importiert werden.', 'Impossible d’importer les jours fériés.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function delegations(): array
    {
        return [
            'workflows.delegations.title' => ['Le mie deleghe di approvazione', 'My approval delegations', 'Meine Genehmigungsdelegationen', 'Mes délégations d’approbation'],
            'workflows.delegations.new' => ['Nuova delega', 'New delegation', 'Neue Delegation', 'Nouvelle délégation'],
            'workflows.delegations.list' => ['Deleghe attive e passate', 'Active and past delegations', 'Aktive und vergangene Delegationen', 'Délégations actives et passées'],
            'workflows.delegations.delegate' => ['Delegato', 'Delegate', 'Stellvertretung', 'Délégué'],
            'workflows.delegations.delegatePlaceholder' => ['Cerca un collega', 'Search for a colleague', 'Kollegen suchen', 'Rechercher un collègue'],
            'workflows.delegations.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'workflows.delegations.scope' => ['Ambito', 'Scope', 'Bereich', 'Périmètre'],
            'workflows.delegations.scope.all' => ['Tutte le approvazioni', 'All approvals', 'Alle Genehmigungen', 'Toutes les approbations'],
            'workflows.delegations.scope.procurement' => ['Acquisti', 'Procurement', 'Einkauf', 'Achats'],
            'workflows.delegations.scope.expenses' => ['Note spese', 'Expenses', 'Spesen', 'Notes de frais'],
            'workflows.delegations.scope.invoicing' => ['Fatturazione', 'Invoicing', 'Fakturierung', 'Facturation'],
            'workflows.delegations.scope.hr' => ['Risorse umane', 'Human resources', 'Personal', 'Ressources humaines'],
            'workflows.delegations.scope.custom' => ['Personalizzato', 'Custom', 'Benutzerdefiniert', 'Personnalisé'],
            'workflows.delegations.documentTypes' => ['Tipi di documento (facoltativo)', 'Document types (optional)', 'Dokumenttypen (optional)', 'Types de document (facultatif)'],
            'workflows.delegations.allDocumentTypes' => ['Tutti i tipi', 'All types', 'Alle Typen', 'Tous les types'],
            'workflows.delegations.amountCap' => ['Importo massimo (facoltativo)', 'Amount cap (optional)', 'Maximalbetrag (optional)', 'Montant maximal (facultatif)'],
            'workflows.delegations.reason' => ['Motivo (facoltativo)', 'Reason (optional)', 'Grund (optional)', 'Motif (facultatif)'],
            'workflows.delegations.create' => ['Crea delega', 'Create delegation', 'Delegation erstellen', 'Créer la délégation'],
            'workflows.delegations.created' => ['Delega creata.', 'Delegation created.', 'Delegation erstellt.', 'Délégation créée.'],
            'workflows.delegations.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'workflows.delegations.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'workflows.delegations.inactive' => ['Non attiva', 'Inactive', 'Inaktiv', 'Inactive'],
            'workflows.delegations.remove' => ['Revoca', 'Revoke', 'Widerrufen', 'Révoquer'],
            'workflows.delegations.removeConfirm' => ['Revocare questa delega?', 'Revoke this delegation?', 'Diese Delegation widerrufen?', 'Révoquer cette délégation ?'],
            'workflows.delegations.loadError' => ['Impossibile caricare le deleghe.', 'The delegations could not be loaded.', 'Die Delegationen konnten nicht geladen werden.', 'Impossible de charger les délégations.'],
            'workflows.delegations.error.delegateRequired' => ['Scegli il collega a cui delegare.', 'Choose the colleague to delegate to.', 'Wählen Sie die Kollegin oder den Kollegen für die Delegation.', 'Choisissez le collègue à qui déléguer.'],
            'workflows.delegations.error.self' => ['Non puoi delegare a te stesso.', 'You cannot delegate to yourself.', 'Sie können nicht an sich selbst delegieren.', 'Vous ne pouvez pas vous déléguer à vous-même.'],
            'workflows.delegations.error.periodRequired' => ['Indica il periodo della delega.', 'Enter the period of the delegation.', 'Geben Sie den Zeitraum der Delegation an.', 'Indiquez la période de la délégation.'],
            'workflows.delegations.error.periodOrder' => ['La data finale non può precedere quella iniziale.', 'The end date cannot be before the start date.', 'Das Enddatum darf nicht vor dem Startdatum liegen.', 'La date de fin ne peut pas précéder la date de début.'],
            'workflows.delegations.error.cap' => ['L’importo massimo deve essere maggiore di zero.', 'The amount cap must be greater than zero.', 'Der Maximalbetrag muss grösser als null sein.', 'Le montant maximal doit être supérieur à zéro.'],
            'workflows.delegations.error.generic' => ['Operazione sulla delega non riuscita.', 'The delegation operation failed.', 'Der Vorgang für die Delegation ist fehlgeschlagen.', 'L’opération sur la délégation a échoué.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function token(): array
    {
        return [
            'workflows.token.title' => ['Conferma di approvazione', 'Approval confirmation', 'Genehmigungsbestätigung', 'Confirmation d’approbation'],
            'workflows.token.language' => ['Lingua', 'Language', 'Sprache', 'Langue'],
            'workflows.token.leadApprove' => ['Stai per approvare questa richiesta. Controlla i dati e conferma.', 'You are about to approve this request. Check the details and confirm.', 'Sie sind dabei, diesen Antrag zu genehmigen. Prüfen Sie die Angaben und bestätigen Sie.', 'Vous êtes sur le point d’approuver cette demande. Vérifiez les informations et confirmez.'],
            'workflows.token.leadReject' => ['Stai per rifiutare questa richiesta. Indica il motivo e conferma.', 'You are about to reject this request. Enter the reason and confirm.', 'Sie sind dabei, diesen Antrag abzulehnen. Geben Sie die Begründung an und bestätigen Sie.', 'Vous êtes sur le point de rejeter cette demande. Indiquez le motif et confirmez.'],
            'workflows.token.document' => ['Documento', 'Document', 'Dokument', 'Document'],
            'workflows.token.type' => ['Tipo', 'Type', 'Typ', 'Type'],
            'workflows.token.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'workflows.token.requester' => ['Richiedente', 'Requester', 'Antragsteller', 'Demandeur'],
            'workflows.token.expiresAt' => ['Il link scade il', 'The link expires on', 'Der Link läuft ab am', 'Le lien expire le'],
            'workflows.token.comment' => ['Commento', 'Comment', 'Kommentar', 'Commentaire'],
            'workflows.token.reauthHint' => ['Per questo importo serve una nuova verifica dell’identità: inserisci la password oppure un codice OTP.', 'This amount needs a fresh identity check: enter your password or a one-time code (OTP).', 'Für diesen Betrag ist eine erneute Identitätsprüfung nötig: Geben Sie Ihr Passwort oder einen Einmalcode (OTP) ein.', 'Ce montant exige une nouvelle vérification d’identité : saisissez votre mot de passe ou un code à usage unique (OTP).'],
            'workflows.token.password' => ['Password', 'Password', 'Passwort', 'Mot de passe'],
            'workflows.token.otp' => ['Codice OTP', 'One-time code (OTP)', 'Einmalcode (OTP)', 'Code à usage unique (OTP)'],
            'workflows.token.confirmApprove' => ['Conferma approvazione', 'Confirm approval', 'Genehmigung bestätigen', 'Confirmer l’approbation'],
            'workflows.token.confirmReject' => ['Conferma rifiuto', 'Confirm rejection', 'Ablehnung bestätigen', 'Confirmer le rejet'],
            'workflows.token.error.commentRequired' => ['Il commento è obbligatorio per rifiutare.', 'A comment is required to reject.', 'Zum Ablehnen ist ein Kommentar erforderlich.', 'Un commentaire est obligatoire pour rejeter.'],
            'workflows.token.error.proofRequired' => ['Inserisci la password o il codice OTP.', 'Enter your password or the one-time code.', 'Geben Sie Ihr Passwort oder den Einmalcode ein.', 'Saisissez votre mot de passe ou le code à usage unique.'],
            'workflows.token.error.reauthFailed' => ['Verifica dell’identità non riuscita: controlla i dati e riprova.', 'Identity check failed: check your details and try again.', 'Identitätsprüfung fehlgeschlagen: Prüfen Sie Ihre Angaben und versuchen Sie es erneut.', 'Vérification d’identité échouée : contrôlez vos informations et réessayez.'],
            'workflows.token.error.validation' => ['I dati inseriti non sono validi.', 'The details entered are not valid.', 'Die eingegebenen Angaben sind ungültig.', 'Les informations saisies ne sont pas valides.'],
            'workflows.token.loginRequired' => ['Accesso richiesto', 'Sign-in required', 'Anmeldung erforderlich', 'Connexion requise'],
            'workflows.token.loginRequiredHint' => ['Per questo importo devi prima accedere con il tuo account.', 'For this amount you must first sign in with your account.', 'Für diesen Betrag müssen Sie sich zuerst mit Ihrem Konto anmelden.', 'Pour ce montant, vous devez d’abord vous connecter avec votre compte.'],
            'workflows.token.login' => ['Accedi', 'Sign in', 'Anmelden', 'Se connecter'],
            'workflows.token.done.approve' => ['Richiesta approvata', 'Request approved', 'Antrag genehmigt', 'Demande approuvée'],
            'workflows.token.done.reject' => ['Richiesta rifiutata', 'Request rejected', 'Antrag abgelehnt', 'Demande rejetée'],
            'workflows.token.doneHint' => ['La tua decisione è stata registrata. Puoi chiudere questa pagina.', 'Your decision has been recorded. You can close this page.', 'Ihre Entscheidung wurde erfasst. Sie können diese Seite schliessen.', 'Votre décision a été enregistrée. Vous pouvez fermer cette page.'],
            'workflows.token.expired' => ['Link scaduto o già utilizzato', 'Link expired or already used', 'Link abgelaufen oder bereits verwendet', 'Lien expiré ou déjà utilisé'],
            'workflows.token.expiredHint' => ['Apri la richiesta nell’applicazione per decidere.', 'Open the request in the application to decide.', 'Öffnen Sie den Antrag in der Anwendung, um zu entscheiden.', 'Ouvrez la demande dans l’application pour décider.'],
            'workflows.token.forbidden' => ['Link non destinato a questo utente', 'This link is not meant for this user', 'Dieser Link ist nicht für diesen Benutzer bestimmt', 'Ce lien n’est pas destiné à cet utilisateur'],
            'workflows.token.forbiddenHint' => ['Accedi con l’account a cui è stata inviata la richiesta.', 'Sign in with the account the request was sent to.', 'Melden Sie sich mit dem Konto an, an das der Antrag gesendet wurde.', 'Connectez-vous avec le compte auquel la demande a été envoyée.'],
            'workflows.token.invalid' => ['Link non valido', 'Invalid link', 'Ungültiger Link', 'Lien invalide'],
            'workflows.token.invalidHint' => ['Il link è incompleto o non esiste. Usa il link ricevuto per e-mail.', 'The link is incomplete or does not exist. Use the link you received by email.', 'Der Link ist unvollständig oder existiert nicht. Verwenden Sie den per E-Mail erhaltenen Link.', 'Le lien est incomplet ou n’existe pas. Utilisez le lien reçu par e-mail.'],
            'workflows.token.failed' => ['Qualcosa è andato storto', 'Something went wrong', 'Etwas ist schiefgelaufen', 'Une erreur est survenue'],
            'workflows.token.failedHint' => ['Riprova tra qualche istante.', 'Please try again in a moment.', 'Bitte versuchen Sie es in einem Moment erneut.', 'Veuillez réessayer dans un instant.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function banner(): array
    {
        return [
            'workflows.banner.title' => ['Approvazione', 'Approval', 'Genehmigung', 'Approbation'],
            'workflows.banner.currentApprover' => ['Approvatore attuale', 'Current approver', 'Aktueller Genehmiger', 'Approbateur actuel'],
            'workflows.banner.unassigned' => ['non assegnato', 'unassigned', 'nicht zugewiesen', 'non assigné'],
            'workflows.banner.dueAt' => ['Scadenza', 'Due', 'Fällig', 'Échéance'],
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
