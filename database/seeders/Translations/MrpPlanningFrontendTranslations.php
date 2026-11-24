<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the MRP I and II workspace (Mod 063): the run bar and run modal, the KPI cards, the proposals workbench with
 * the Mass Firm and Release confirmation, the pegging explorer and the finite-capacity Gantt with its load histogram. Each entry
 * is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders
 * are written {name}.
 */
final class MrpPlanningFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'MRP.NAV' => ['MRP I e II', 'MRP I & II', 'MRP I und II', 'MRP I et II'],
            'MRP.TITLE' => ['Pianificazione dei fabbisogni di materiali e capacità (MRP I e II)', 'Material and capacity requirements planning (MRP I & II)', 'Material- und Kapazitätsbedarfsplanung (MRP I und II)', 'Planification des besoins en matières et en capacité (MRP I et II)'],
            'MRP.SUBTITLE' => ['Esegui il calcolo, valuta le proposte, risali ai fabbisogni e livella la capacità finita', 'Run the plan, review the proposals, trace the demand and level the finite capacity', 'Planlauf starten, Vorschläge prüfen, Bedarfe zurückverfolgen und endliche Kapazität glätten', 'Lancer le calcul, examiner les propositions, remonter aux besoins et lisser la capacité finie'],
            'MRP.TAB_PROPOSALS' => ['Proposte', 'Proposals', 'Vorschläge', 'Propositions'],
            'MRP.TAB_CAPACITY' => ['Capacità e Gantt', 'Capacity and Gantt', 'Kapazität und Gantt', 'Capacité et Gantt'],
            'MRP.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],

            'MRP.RUN_SELECT' => ['Seleziona un calcolo', 'Select a run', 'Planlauf auswählen', 'Sélectionner un calcul'],
            'MRP.NEW_RUN' => ['Nuovo calcolo MRP', 'New MRP run', 'Neuer MRP-Lauf', 'Nouveau calcul MRP'],
            'MRP.RUN_MODAL_TITLE' => ['Avvia un calcolo MRP', 'Start an MRP run', 'MRP-Lauf starten', 'Lancer un calcul MRP'],
            'MRP.RUN_START' => ['Avvia calcolo', 'Start run', 'Lauf starten', 'Lancer le calcul'],
            'MRP.RUN_DURATION' => ['Durata: {ms} ms', 'Duration: {ms} ms', 'Dauer: {ms} ms', 'Durée : {ms} ms'],
            'MRP.RUN_STATUS_PENDING' => ['In attesa', 'Pending', 'Wartend', 'En attente'],
            'MRP.RUN_STATUS_RUNNING' => ['In corso', 'Running', 'Läuft', 'En cours'],
            'MRP.RUN_STATUS_COMPLETED' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'MRP.RUN_STATUS_FAILED' => ['Fallito', 'Failed', 'Fehlgeschlagen', 'Échoué'],
            'MRP.RUN_FAILED_TITLE' => ['Calcolo MRP fallito', 'The MRP run failed', 'Der MRP-Lauf ist fehlgeschlagen', 'Le calcul MRP a échoué'],
            'MRP.RUN_DONE_TITLE' => ['Calcolo MRP completato', 'MRP run completed', 'MRP-Lauf abgeschlossen', 'Calcul MRP terminé'],
            'MRP.RUN_DONE_TEXT' => ['Calcolo {code}: {proposals} proposte generate.', 'Run {code}: {proposals} proposals generated.', 'Lauf {code}: {proposals} Vorschläge erzeugt.', 'Calcul {code} : {proposals} propositions générées.'],
            'MRP.FIELD_HORIZON' => ['Orizzonte di pianificazione (giorni)', 'Planning horizon (days)', 'Planungshorizont (Tage)', 'Horizon de planification (jours)'],
            'MRP.ERR_HORIZON' => ['Inserisci un numero intero di giorni tra 1 e 730', 'Enter a whole number of days between 1 and 730', 'Geben Sie eine ganze Zahl von Tagen zwischen 1 und 730 ein', 'Saisissez un nombre entier de jours entre 1 et 730'],
            'MRP.FIELD_RUN_MODE' => ['Modalità di calcolo', 'Run mode', 'Planlaufmodus', 'Mode de calcul'],
            'MRP.MODE_REGENERATIVE' => ['Rigenerativo completo', 'Full regenerative', 'Vollständig regenerativ', 'Régénératif complet'],
            'MRP.MODE_NET_CHANGE' => ['Net change (solo variazioni)', 'Net change (changes only)', 'Net Change (nur Änderungen)', 'Net change (modifications uniquement)'],
            'MRP.FIELD_CAPACITY_MODE' => ['Modalità di capacità', 'Capacity mode', 'Kapazitätsmodus', 'Mode de capacité'],
            'MRP.CAPACITY_INFINITE' => ['Capacità infinita', 'Infinite capacity', 'Unendliche Kapazität', 'Capacité infinie'],
            'MRP.CAPACITY_FINITE' => ['Capacità finita (vincolata)', 'Finite capacity (constrained)', 'Endliche Kapazität (begrenzt)', 'Capacité finie (contrainte)'],
            'MRP.FIELD_DIRECTION' => ['Direzione di schedulazione', 'Scheduling direction', 'Terminierungsrichtung', 'Sens de l’ordonnancement'],
            'MRP.DIRECTION_BACKWARD' => ['All’indietro dalla data di consegna', 'Backward from the due date', 'Rückwärts vom Fälligkeitsdatum', 'En arrière depuis la date d’échéance'],
            'MRP.DIRECTION_FORWARD' => ['In avanti da oggi', 'Forward from today', 'Vorwärts ab heute', 'En avant à partir d’aujourd’hui'],
            'MRP.FIELD_CUSTOMS' => ['Includi il lead time doganale transfrontaliero', 'Include cross-border customs lead time', 'Grenzüberschreitende Zoll-Vorlaufzeit einbeziehen', 'Inclure le délai douanier transfrontalier'],
            'MRP.HINT_CUSTOMS' => ['Aggiunge i giorni di sdoganamento agli acquisti IT/CH.', 'Adds the customs clearance days to IT/CH purchases.', 'Fügt den IT/CH-Einkäufen die Zollabfertigungstage hinzu.', 'Ajoute les jours de dédouanement aux achats IT/CH.'],
            'MRP.FIELD_SHUTDOWN' => ['Applica i calendari di chiusura dei fornitori', 'Apply supplier shutdown calendars', 'Betriebsferien der Lieferanten berücksichtigen', 'Appliquer les calendriers de fermeture des fournisseurs'],
            'MRP.HINT_SHUTDOWN' => ['Sposta le date di consegna fuori dai periodi di chiusura.', 'Moves the delivery dates out of the shutdown periods.', 'Verschiebt die Liefertermine aus den Betriebsferien heraus.', 'Décale les dates de livraison hors des périodes de fermeture.'],

            'MRP.KPI_PROPOSALS' => ['Proposte', 'Proposals', 'Vorschläge', 'Propositions'],
            'MRP.KPI_EXCEPTIONS' => ['Eccezioni', 'Exceptions', 'Ausnahmen', 'Exceptions'],
            'MRP.KPI_OVERLOADED_BUCKETS' => ['Intervalli sovraccarichi', 'Overloaded buckets', 'Überlastete Zeitabschnitte', 'Tranches surchargées'],
            'MRP.KPI_OVERLOADED_CENTERS' => ['{centers} centri di lavoro su {buckets} intervalli', '{centers} work centers across {buckets} buckets', '{centers} Arbeitsplätze in {buckets} Zeitabschnitten', '{centers} centres de travail sur {buckets} tranches'],
            'MRP.KPI_MAX_LOAD' => ['Carico massimo', 'Maximum load', 'Maximale Auslastung', 'Charge maximale'],

            'MRP.TYPE_PURCHASE_REQUISITION' => ['Richiesta d’acquisto pianificata', 'Planned purchase requisition', 'Geplante Bestellanforderung', 'Demande d’achat planifiée'],
            'MRP.TYPE_PRODUCTION_ORDER' => ['Ordine di produzione pianificato', 'Planned production order', 'Geplanter Fertigungsauftrag', 'Ordre de fabrication planifié'],
            'MRP.TYPE_SUBCONTRACT_ORDER' => ['Ordine di lavorazione esterna pianificato', 'Planned subcontract order', 'Geplanter Lohnfertigungsauftrag', 'Ordre de sous-traitance planifié'],
            'MRP.TYPE_RESCHEDULE_IN' => ['Anticipa (sollecito)', 'Reschedule in (expedite)', 'Vorziehen (beschleunigen)', 'Avancer (accélérer)'],
            'MRP.TYPE_RESCHEDULE_OUT' => ['Posticipa (differimento)', 'Reschedule out (defer)', 'Verschieben (zurückstellen)', 'Reporter (différer)'],
            'MRP.TYPE_CANCEL_EXCESS' => ['Annulla ordine in eccesso', 'Cancel excess order', 'Überschüssigen Auftrag stornieren', 'Annuler la commande excédentaire'],
            'MRP.STATUS_PROPOSED' => ['Proposta', 'Proposed', 'Vorgeschlagen', 'Proposée'],
            'MRP.STATUS_FIRM' => ['Confermata', 'Firm', 'Fixiert', 'Ferme'],
            'MRP.STATUS_RELEASED' => ['Rilasciata', 'Released', 'Freigegeben', 'Libérée'],
            'MRP.STATUS_DISMISSED' => ['Scartata', 'Dismissed', 'Verworfen', 'Rejetée'],

            'MRP.EXC_EXPEDITE' => ['Sollecito', 'Expedite', 'Beschleunigen', 'Accélérer'],
            'MRP.EXC_DEFER' => ['Differimento', 'Defer', 'Zurückstellen', 'Différer'],
            'MRP.EXC_PAST_DUE_RELEASE' => ['Rilascio in ritardo', 'Past-due release', 'Überfällige Freigabe', 'Libération en retard'],
            'MRP.EXC_BELOW_SAFETY_STOCK' => ['Sotto scorta di sicurezza', 'Below safety stock', 'Unter Sicherheitsbestand', 'Sous le stock de sécurité'],
            'MRP.EXC_CAPACITY_OVERLOAD' => ['Sovraccarico di capacità', 'Capacity overload', 'Kapazitätsüberlast', 'Surcharge de capacité'],
            'MRP.EXC_HOLIDAY_SHIFT' => ['Spostato per festività', 'Shifted for holidays', 'Wegen Feiertagen verschoben', 'Décalé pour jours fériés'],
            'MRP.EXC_CUSTOMS_LEAD_TIME' => ['Lead time doganale applicato', 'Customs lead time applied', 'Zoll-Vorlaufzeit angewendet', 'Délai douanier appliqué'],
            'MRP.EXC_LATE_DELIVERY' => ['Consegna in ritardo', 'Late delivery', 'Verspätete Lieferung', 'Livraison tardive'],
            'MRP.EXC_EXCESS_SUPPLY' => ['Offerta in eccesso', 'Excess supply', 'Überangebot', 'Offre excédentaire'],
            'MRP.HINT_EXPEDITE' => ['Un ordine esistente va anticipato per coprire il fabbisogno.', 'An existing order must be pulled in to cover the demand.', 'Ein bestehender Auftrag muss vorgezogen werden, um den Bedarf zu decken.', 'Une commande existante doit être avancée pour couvrir le besoin.'],
            'MRP.HINT_DEFER' => ['Un ordine esistente può essere posticipato: il fabbisogno è più avanti.', 'An existing order can be pushed out: the demand comes later.', 'Ein bestehender Auftrag kann verschoben werden: der Bedarf liegt später.', 'Une commande existante peut être reportée : le besoin est plus tardif.'],
            'MRP.HINT_PAST_DUE_RELEASE' => ['La data di rilascio è già passata: l’ordine nascerebbe in ritardo.', 'The release date is already in the past: the order would start late.', 'Das Freigabedatum liegt bereits in der Vergangenheit: der Auftrag würde zu spät starten.', 'La date de libération est déjà dépassée : la commande démarrerait en retard.'],
            'MRP.HINT_BELOW_SAFETY_STOCK' => ['La giacenza proiettata scende sotto la scorta di sicurezza.', 'The projected stock falls below the safety stock.', 'Der Planbestand fällt unter den Sicherheitsbestand.', 'Le stock projeté passe sous le stock de sécurité.'],
            'MRP.HINT_CAPACITY_OVERLOAD' => ['Il centro di lavoro supera il 100 % della capacità disponibile.', 'The work center exceeds 100 % of its available capacity.', 'Der Arbeitsplatz überschreitet 100 % der verfügbaren Kapazität.', 'Le centre de travail dépasse 100 % de sa capacité disponible.'],
            'MRP.HINT_HOLIDAY_SHIFT' => ['Le date sono state spostate per rispettare festività o turni.', 'The dates were shifted to respect holidays or shifts.', 'Die Termine wurden wegen Feiertagen oder Schichten verschoben.', 'Les dates ont été décalées pour respecter jours fériés ou équipes.'],
            'MRP.HINT_CUSTOMS_LEAD_TIME' => ['Il lead time comprende lo sdoganamento transfrontaliero.', 'The lead time includes cross-border customs clearance.', 'Die Vorlaufzeit enthält die grenzüberschreitende Zollabfertigung.', 'Le délai inclut le dédouanement transfrontalier.'],
            'MRP.HINT_LATE_DELIVERY' => ['La consegna arriva dopo la data di fabbisogno.', 'The delivery arrives after the need-by date.', 'Die Lieferung trifft nach dem Bedarfstermin ein.', 'La livraison arrive après la date de besoin.'],
            'MRP.HINT_EXCESS_SUPPLY' => ['L’offerta supera il fabbisogno: valuta di annullare o ridurre.', 'Supply exceeds demand: consider cancelling or reducing.', 'Das Angebot übersteigt den Bedarf: Stornierung oder Reduzierung prüfen.', 'L’offre dépasse le besoin : envisagez d’annuler ou de réduire.'],

            'MRP.FILTER_TYPE' => ['Tipo di proposta', 'Proposal type', 'Vorschlagstyp', 'Type de proposition'],
            'MRP.FILTER_STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'MRP.FILTER_EXCEPTION' => ['Eccezione', 'Exception', 'Ausnahme', 'Exception'],
            'MRP.FILTER_ITEM' => ['Articolo', 'Item', 'Artikel', 'Article'],
            'MRP.FILTER_WORK_CENTER' => ['Centro di lavoro', 'Work center', 'Arbeitsplatz', 'Centre de travail'],
            'MRP.FILTER_NEED_BY' => ['Finestra di data fabbisogno', 'Need-by window', 'Bedarfsterminfenster', 'Fenêtre de date de besoin'],
            'MRP.FILTER_NEED_BY_FROM' => ['Fabbisogno dal', 'Needed from', 'Bedarf ab', 'Besoin du'],
            'MRP.FILTER_NEED_BY_TO' => ['Fabbisogno al', 'Needed until', 'Bedarf bis', 'Besoin au'],
            'MRP.FILTER_RESET' => ['Azzera filtri', 'Reset filters', 'Filter zurücksetzen', 'Réinitialiser les filtres'],

            'MRP.COL_ITEM' => ['Articolo', 'Item', 'Artikel', 'Article'],
            'MRP.COL_TYPE' => ['Tipo', 'Type', 'Typ', 'Type'],
            'MRP.COL_STATUS' => ['Stato', 'Status', 'Status', 'Statut'],
            'MRP.COL_EXCEPTIONS' => ['Eccezioni', 'Exceptions', 'Ausnahmen', 'Exceptions'],
            'MRP.COL_GROSS' => ['Fabbisogno lordo', 'Gross requirement', 'Bruttobedarf', 'Besoin brut'],
            'MRP.COL_PROJECTED' => ['Giacenza proiettata', 'Projected on hand', 'Geplanter Bestand', 'Stock projeté'],
            'MRP.COL_NET' => ['Fabbisogno netto', 'Net requirement', 'Nettobedarf', 'Besoin net'],
            'MRP.COL_SUGGESTED' => ['Quantità suggerita', 'Suggested quantity', 'Vorschlagsmenge', 'Quantité suggérée'],
            'MRP.TIP_SUGGESTED' => ['Dopo la regola di lotto (MOQ, multiplo, EOQ) e lo scarto', 'After the lot-sizing rule (MOQ, multiple, EOQ) and scrap', 'Nach Losgrössenregel (MOQ, Vielfaches, EOQ) und Ausschuss', 'Après la règle de lot (MOQ, multiple, EOQ) et le rebut'],
            'MRP.COL_NEED_BY' => ['Data fabbisogno', 'Need-by date', 'Bedarfstermin', 'Date de besoin'],
            'MRP.COL_RELEASE_BY' => ['Rilascio entro', 'Release by', 'Freigabe bis', 'Libération avant'],
            'MRP.COL_ACTIONS' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'MRP.LLC' => ['LLC', 'LLC', 'DSK', 'NBP'],
            'MRP.ACTION_FIRM' => ['Conferma', 'Firm', 'Fixieren', 'Fermer'],
            'MRP.ACTION_RELEASE' => ['Rilascia', 'Release', 'Freigeben', 'Libérer'],
            'MRP.ACTION_DISMISS' => ['Scarta', 'Dismiss', 'Verwerfen', 'Rejeter'],
            'MRP.ACTION_PEGGING' => ['Pegging', 'Pegging', 'Pegging', 'Pegging'],
            'MRP.SELECTED_COUNT' => ['{count} proposte selezionate', '{count} proposals selected', '{count} Vorschläge ausgewählt', '{count} propositions sélectionnées'],
            'MRP.MASS_FIRM_RELEASE' => ['Conferma e rilascia in blocco', 'Mass Firm & Release', 'Sammel-Fixieren und -Freigeben', 'Fermer et libérer en masse'],
            'MRP.DISMISS_SELECTED' => ['Scarta selezionate', 'Dismiss selected', 'Ausgewählte verwerfen', 'Rejeter la sélection'],
            'MRP.CLEAR_SELECTION' => ['Deseleziona', 'Clear selection', 'Auswahl aufheben', 'Effacer la sélection'],
            'MRP.RELEASE_TITLE' => ['Conferma e rilascia in blocco', 'Mass Firm & Release', 'Sammel-Fixieren und -Freigeben', 'Fermer et libérer en masse'],
            'MRP.RELEASE_CONFIRM' => ['Conferma e rilascia', 'Firm and release', 'Fixieren und freigeben', 'Fermer et libérer'],
            'MRP.RELEASE_INTRO' => ['Hai selezionato {count} proposte. Con il rilascio verranno generati:', 'You selected {count} proposals. Releasing them will generate:', 'Sie haben {count} Vorschläge ausgewählt. Die Freigabe erzeugt:', 'Vous avez sélectionné {count} propositions. La libération générera :'],
            'MRP.RELEASE_REQUISITIONS' => ['richieste d’acquisto in bozza (una per fornitore, Mod 044/046)', 'draft purchase requisitions (one per supplier, Mod 044/046)', 'Entwurfs-Bestellanforderungen (eine je Lieferant, Mod 044/046)', 'demandes d’achat en brouillon (une par fournisseur, Mod 044/046)'],
            'MRP.RELEASE_ORDERS' => ['ordini di produzione pianificati, anche per lavorazione esterna (Mod 064)', 'planned production orders, subcontract ones included (Mod 064)', 'geplante Fertigungsaufträge, einschliesslich Lohnfertigung (Mod 064)', 'ordres de fabrication planifiés, sous-traitance comprise (Mod 064)'],
            'MRP.RELEASE_INELIGIBLE' => ['proposte non rilasciabili', 'proposals that cannot be released', 'nicht freigebbare Vorschläge', 'propositions non libérables'],
            'MRP.RELEASE_INELIGIBLE_HINT' => ['L’operazione è tutto o niente: deseleziona le proposte non rilasciabili (avvisi di riprogrammazione o già rilasciate) per procedere.', 'The operation is all or nothing: deselect the proposals that cannot be released (reschedule advice or already released) to continue.', 'Der Vorgang gilt ganz oder gar nicht: Heben Sie die Auswahl der nicht freigebbaren Vorschläge (Terminhinweise oder bereits freigegeben) auf, um fortzufahren.', 'L’opération est tout ou rien : désélectionnez les propositions non libérables (avis de report ou déjà libérées) pour continuer.'],
            'MRP.RELEASE_KEEP_ELIGIBLE' => ['Mantieni solo le rilasciabili', 'Keep only the releasable ones', 'Nur freigebbare behalten', 'Ne garder que les libérables'],
            'MRP.ACTION_DONE_TITLE' => ['Operazione completata', 'Action completed', 'Aktion abgeschlossen', 'Action terminée'],
            'MRP.ACTION_DONE_TEXT' => ['{processed} proposte elaborate: {requisitions} richieste d’acquisto e {orders} ordini di produzione creati.', '{processed} proposals processed: {requisitions} purchase requisitions and {orders} production orders created.', '{processed} Vorschläge verarbeitet: {requisitions} Bestellanforderungen und {orders} Fertigungsaufträge erstellt.', '{processed} propositions traitées : {requisitions} demandes d’achat et {orders} ordres de fabrication créés.'],

            'MRP.PEGGING_TITLE' => ['Pegging: origine del fabbisogno', 'Pegging: where the demand comes from', 'Pegging: Herkunft des Bedarfs', 'Pegging : origine du besoin'],
            'MRP.PEGGING_UPSTREAM' => ['A monte: fabbisogno servito', 'Upstream: demand served', 'Upstream: gedeckter Bedarf', 'En amont : besoin couvert'],
            'MRP.PEGGING_DOWNSTREAM' => ['A valle: componenti generati', 'Downstream: components generated', 'Downstream: erzeugte Komponenten', 'En aval : composants générés'],
            'MRP.PEGGING_ROOT_DEMANDS' => ['Fabbisogni all’origine', 'Root demands', 'Ursprungsbedarfe', 'Besoins d’origine'],
            'MRP.PEGGING_NO_ROOTS' => ['Nessun fabbisogno all’origine', 'No root demand', 'Kein Ursprungsbedarf', 'Aucun besoin d’origine'],
            'MRP.PEGGING_EMPTY' => ['Nessun dato di pegging', 'No pegging data', 'Keine Pegging-Daten', 'Aucune donnée de pegging'],
            'MRP.INSPECTOR_TITLE' => ['Dettaglio del nodo', 'Node detail', 'Knotendetails', 'Détail du nœud'],
            'MRP.INSPECTOR_QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'MRP.INSPECTOR_SOURCE' => ['Origine del fabbisogno', 'Demand source', 'Bedarfsquelle', 'Source du besoin'],
            'MRP.INSPECTOR_SOURCE_ID' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'MRP.SOURCE_SALES_ORDER' => ['Ordine cliente (Mod 037)', 'Sales order (Mod 037)', 'Kundenauftrag (Mod 037)', 'Commande client (Mod 037)'],
            'MRP.SOURCE_MPS' => ['Piano principale di produzione (Mod 062)', 'Master production schedule (Mod 062)', 'Hauptproduktionsplan (Mod 062)', 'Plan directeur de production (Mod 062)'],
            'MRP.SOURCE_SAFETY_STOCK' => ['Scorta di sicurezza (Mod 051)', 'Safety stock (Mod 051)', 'Sicherheitsbestand (Mod 051)', 'Stock de sécurité (Mod 051)'],
            'MRP.SOURCE_ALLOCATED_STOCK' => ['Giacenza allocata', 'Allocated stock', 'Reservierter Bestand', 'Stock alloué'],
            'MRP.SOURCE_PRODUCTION_ORDER' => ['Ordine di produzione', 'Production order', 'Fertigungsauftrag', 'Ordre de fabrication'],
            'MRP.SOURCE_PARENT_PROPOSAL' => ['Proposta padre', 'Parent proposal', 'Übergeordneter Vorschlag', 'Proposition parente'],

            'MRP.GANTT_WINDOW' => ['Finestra del Gantt', 'Gantt window', 'Gantt-Zeitfenster', 'Fenêtre du Gantt'],
            'MRP.GANTT_ZOOM_DAY' => ['Giorni', 'Days', 'Tage', 'Jours'],
            'MRP.GANTT_ZOOM_WEEK' => ['Settimane', 'Weeks', 'Wochen', 'Semaines'],
            'MRP.GANTT_RELOAD' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'MRP.GANTT_AUTO_LEVEL' => ['Livellamento automatico a capacità finita', 'Auto-Level Finite Capacity', 'Automatische Glättung bei endlicher Kapazität', 'Lissage automatique à capacité finie'],
            'MRP.GANTT_OVERLOAD_TOTAL' => ['{count} intervalli sovraccarichi', '{count} overloaded buckets', '{count} überlastete Zeitabschnitte', '{count} tranches surchargées'],
            'MRP.GANTT_EMPTY' => ['Nessun carico nella finestra scelta', 'No load in the chosen window', 'Keine Last im gewählten Zeitfenster', 'Aucune charge dans la fenêtre choisie'],
            'MRP.GANTT_LEGEND_OK' => ['Carico sotto l’80 %', 'Load under 80 %', 'Auslastung unter 80 %', 'Charge inférieure à 80 %'],
            'MRP.GANTT_LEGEND_HIGH' => ['Carico tra 80 % e 100 %', 'Load between 80 % and 100 %', 'Auslastung zwischen 80 % und 100 %', 'Charge entre 80 % et 100 %'],
            'MRP.GANTT_LEGEND_OVERLOAD' => ['Sovraccarico oltre il 100 %', 'Overload above 100 %', 'Überlast über 100 %', 'Surcharge au-dessus de 100 %'],
            'MRP.GANTT_LEGEND_MANUAL' => ['Blocco spostato a mano', 'Block moved manually', 'Manuell verschobener Block', 'Bloc déplacé manuellement'],
            'MRP.GANTT_LEGEND_MOVED' => ['Blocco su un centro alternativo', 'Block on an alternative work center', 'Block auf alternativem Arbeitsplatz', 'Bloc sur un centre de travail alternatif'],
            'MRP.GANTT_WC_SUMMARY' => ['{loaded}/{available} min · carico massimo {max} %', '{loaded}/{available} min · max load {max} %', '{loaded}/{available} Min. · max. Auslastung {max} %', '{loaded}/{available} min · charge max. {max} %'],
            'MRP.GANTT_WC_OVERLOADED_TIP' => ['{count} intervalli sovraccarichi, picco al {max} %', '{count} overloaded buckets, peak at {max} %', '{count} überlastete Zeitabschnitte, Spitze bei {max} %', '{count} tranches surchargées, pic à {max} %'],
            'MRP.GANTT_BLOCK_TIP' => ['{item}: dal {start} al {end}, {minutes} min', '{item}: {start} to {end}, {minutes} min', '{item}: {start} bis {end}, {minutes} Min.', '{item} : du {start} au {end}, {minutes} min'],
            'MRP.GANTT_BUCKET_TIP' => ['{date} · turno {shift}: {loaded}/{available} min ({percentage} %)', '{date} · shift {shift}: {loaded}/{available} min ({percentage} %)', '{date} · Schicht {shift}: {loaded}/{available} Min. ({percentage} %)', '{date} · équipe {shift} : {loaded}/{available} min ({percentage} %)'],
            'MRP.GANTT_BUCKET_OVERLOAD_TIP' => ['Sovraccarico, {date} · turno {shift}: {loaded}/{available} min ({percentage} %)', 'Overload, {date} · shift {shift}: {loaded}/{available} min ({percentage} %)', 'Überlast, {date} · Schicht {shift}: {loaded}/{available} Min. ({percentage} %)', 'Surcharge, {date} · équipe {shift} : {loaded}/{available} min ({percentage} %)'],
            'MRP.BLOCK_TITLE' => ['Blocco di schedulazione', 'Schedule block', 'Terminierungsblock', 'Bloc d’ordonnancement'],
            'MRP.BLOCK_DATES' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'MRP.BLOCK_MINUTES' => ['Minuti di carico', 'Load minutes', 'Lastminuten', 'Minutes de charge'],
            'MRP.BLOCK_ORIGINAL_CENTER' => ['Centro di lavoro originale: {center}', 'Original work center: {center}', 'Ursprünglicher Arbeitsplatz: {center}', 'Centre de travail d’origine : {center}'],
            'MRP.BLOCK_MOVE_TO_CENTER' => ['Sposta su un centro di lavoro alternativo', 'Move to an alternative work center', 'Auf alternativen Arbeitsplatz verschieben', 'Déplacer vers un centre de travail alternatif'],
            'MRP.BLOCK_NO_ALTERNATIVE' => ['Nessun centro alternativo disponibile', 'No alternative work center available', 'Kein alternativer Arbeitsplatz verfügbar', 'Aucun centre de travail alternatif disponible'],
            'MRP.BLOCK_START_DATE' => ['Nuova data di inizio', 'New start date', 'Neues Startdatum', 'Nouvelle date de début'],
            'MRP.BLOCK_APPLY' => ['Applica spostamento', 'Apply the move', 'Verschiebung anwenden', 'Appliquer le déplacement'],
            'MRP.LEVEL_DONE_TITLE' => ['Livellamento completato', 'Leveling completed', 'Glättung abgeschlossen', 'Lissage terminé'],
            'MRP.LEVEL_DONE_TEXT' => ['Intervalli sovraccarichi: {before} prima, {after} dopo. Proposte livellate: {proposals}.', 'Overloaded buckets: {before} before, {after} after. Proposals leveled: {proposals}.', 'Überlastete Zeitabschnitte: {before} vorher, {after} nachher. Geglättete Vorschläge: {proposals}.', 'Tranches surchargées : {before} avant, {after} après. Propositions lissées : {proposals}.'],
            'MRP.RESCHEDULE_DONE_TITLE' => ['Blocco riprogrammato', 'Block rescheduled', 'Block neu terminiert', 'Bloc reprogrammé'],
            'MRP.RESCHEDULE_DONE_TEXT' => ['Intervalli sovraccarichi dopo lo spostamento: {overloaded}.', 'Overloaded buckets after the move: {overloaded}.', 'Überlastete Zeitabschnitte nach der Verschiebung: {overloaded}.', 'Tranches surchargées après le déplacement : {overloaded}.'],

            'MRP.ERR_FORBIDDEN' => ['Non hai i permessi necessari per questa operazione.', 'You do not have the permission this operation needs.', 'Ihnen fehlt die Berechtigung für diesen Vorgang.', 'Vous n’avez pas l’autorisation requise pour cette opération.'],
            'MRP.ERR_LOAD_RUNS' => ['Impossibile caricare i calcoli MRP', 'The MRP runs could not be loaded', 'Die MRP-Läufe konnten nicht geladen werden', 'Les calculs MRP n’ont pas pu être chargés'],
            'MRP.ERR_LOAD_RUN' => ['Impossibile caricare il calcolo selezionato', 'The selected run could not be loaded', 'Der gewählte Lauf konnte nicht geladen werden', 'Le calcul sélectionné n’a pas pu être chargé'],
            'MRP.ERR_START_RUN' => ['Impossibile avviare il calcolo MRP', 'The MRP run could not be started', 'Der MRP-Lauf konnte nicht gestartet werden', 'Le calcul MRP n’a pas pu être lancé'],
            'MRP.ERR_LOAD_PROPOSALS' => ['Impossibile caricare le proposte', 'The proposals could not be loaded', 'Die Vorschläge konnten nicht geladen werden', 'Les propositions n’ont pas pu être chargées'],
            'MRP.ERR_ACTION' => ['Operazione sulle proposte non riuscita', 'The action on the proposals failed', 'Die Aktion auf den Vorschlägen ist fehlgeschlagen', 'L’action sur les propositions a échoué'],
            'MRP.ERR_LOAD_PEGGING' => ['Impossibile caricare il pegging', 'The pegging could not be loaded', 'Das Pegging konnte nicht geladen werden', 'Le pegging n’a pas pu être chargé'],
            'MRP.ERR_LOAD_GANTT' => ['Impossibile caricare il Gantt', 'The Gantt could not be loaded', 'Das Gantt konnte nicht geladen werden', 'Le Gantt n’a pas pu être chargé'],
            'MRP.ERR_LEVEL' => ['Livellamento non riuscito', 'The leveling failed', 'Die Glättung ist fehlgeschlagen', 'Le lissage a échoué'],
            'MRP.ERR_RESCHEDULE' => ['Spostamento non riuscito', 'The move failed', 'Die Verschiebung ist fehlgeschlagen', 'Le déplacement a échoué'],
            'MRP.ERR_NOT_ALTERNATIVE_TITLE' => ['Centro di lavoro non consentito', 'Work center not allowed', 'Arbeitsplatz nicht zulässig', 'Centre de travail non autorisé'],
            'MRP.ERR_NOT_ALTERNATIVE_TEXT' => ['{center} non è un centro alternativo di quello di origine.', '{center} is not an alternative of the original work center.', '{center} ist kein Alternativarbeitsplatz des ursprünglichen Arbeitsplatzes.', '{center} n’est pas un centre alternatif du centre d’origine.'],
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
