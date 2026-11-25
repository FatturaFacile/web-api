<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the purchasing and supplier relationship (SRM) area: the overview dashboard, the supplier scorecards and
 * the procure-to-pay document flow (srm.*), plus their menu and page titles.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 */
final class SrmFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.PROCUREMENT_SRM' => ['Acquisti e SRM', 'Purchasing & SRM', 'Einkauf und SRM', 'Achats et SRM'],
            'NAV.PROCUREMENT_SRM_SCORECARDS' => ['Scorecard fornitori', 'Supplier scorecards', 'Lieferanten-Scorecards', 'Scorecards fournisseurs'],
            'PAGE_TITLE.PROCUREMENT_SRM' => ['Cruscotto acquisti e relazioni fornitori', 'Purchasing and supplier relationship dashboard', 'Dashboard Einkauf und Lieferantenbeziehungen', 'Tableau de bord achats et relations fournisseurs'],
            'PAGE_TITLE.PROCUREMENT_SRM_SCORECARDS' => ['Scorecard e valutazione fornitori', 'Supplier scorecards and rating', 'Lieferanten-Scorecards und Bewertung', 'Scorecards et notation des fournisseurs'],

            'srm.dashboard.title' => ['Cruscotto acquisti e SRM', 'Purchasing and SRM dashboard', 'Einkaufs- und SRM-Dashboard', 'Tableau de bord achats et SRM'],
            'srm.dashboard.subtitle' => ['Cosa attende una decisione, cosa è in ritardo o bloccato e come performano i fornitori', 'What waits for a decision, what is late or blocked and how suppliers perform', 'Was auf eine Entscheidung wartet, was verspätet oder gesperrt ist und wie die Lieferanten abschneiden', 'Ce qui attend une décision, ce qui est en retard ou bloqué et la performance des fournisseurs'],
            'srm.dashboard.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'srm.dashboard.allScorecards' => ['Tutte le scorecard', 'All scorecards', 'Alle Scorecards', 'Toutes les scorecards'],
            'srm.dashboard.loadError' => ['Impossibile caricare il cruscotto', 'The dashboard could not be loaded', 'Das Dashboard konnte nicht geladen werden', 'Impossible de charger le tableau de bord'],
            'srm.dashboard.overdueOrders' => ['Ordini in ritardo', 'Overdue orders', 'Überfällige Bestellungen', 'Commandes en retard'],
            'srm.dashboard.noOverdue' => ['Nessun ordine in ritardo', 'No overdue orders', 'Keine überfälligen Bestellungen', 'Aucune commande en retard'],
            'srm.dashboard.blockedInvoices' => ['Fatture bloccate per scostamento', 'Invoices blocked by variance', 'Wegen Abweichung gesperrte Rechnungen', 'Factures bloquées pour écart'],
            'srm.dashboard.noBlocked' => ['Nessuna fattura bloccata', 'No blocked invoices', 'Keine gesperrten Rechnungen', 'Aucune facture bloquée'],
            'srm.dashboard.daysLate' => ['giorni di ritardo', 'days late', 'Tage verspätet', 'jours de retard'],
            'srm.dashboard.seeAll' => ['Vedi tutti', 'See all', 'Alle anzeigen', 'Tout voir'],
            'srm.dashboard.spendBySupplier' => ['Spesa per fornitore', 'Spend by supplier', 'Ausgaben nach Lieferant', 'Dépenses par fournisseur'],
            'srm.dashboard.spendByCategory' => ['Spesa per categoria', 'Spend by category', 'Ausgaben nach Kategorie', 'Dépenses par catégorie'],
            'srm.dashboard.noSpend' => ['Nessuna spesa nel periodo', 'No spend in the period', 'Keine Ausgaben im Zeitraum', 'Aucune dépense sur la période'],
            'srm.dashboard.unassigned' => ['Non assegnato', 'Unassigned', 'Nicht zugeordnet', 'Non attribué'],
            'srm.dashboard.topSuppliers' => ['Migliori fornitori', 'Top suppliers', 'Beste Lieferanten', 'Meilleurs fournisseurs'],
            'srm.dashboard.bottomSuppliers' => ['Fornitori da migliorare', 'Suppliers to improve', 'Zu verbessernde Lieferanten', 'Fournisseurs à améliorer'],
            'srm.dashboard.supplierBase' => ['Albo fornitori e conformità', 'Supplier register and compliance', 'Lieferantenstamm und Compliance', 'Registre fournisseurs et conformité'],
            'srm.dashboard.expiringDocuments' => ['Documenti in scadenza (30 giorni)', 'Documents expiring (30 days)', 'Ablaufende Dokumente (30 Tage)', 'Documents expirant (30 jours)'],
            'srm.dashboard.expiredDocuments' => ['Documenti scaduti', 'Expired documents', 'Abgelaufene Dokumente', 'Documents expirés'],
            'srm.dashboard.col.order' => ['Ordine', 'Order', 'Bestellung', 'Commande'],
            'srm.dashboard.col.supplier' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'srm.dashboard.col.expected' => ['Consegna prevista', 'Expected delivery', 'Erwartete Lieferung', 'Livraison prévue'],
            'srm.dashboard.col.openValue' => ['Valore residuo', 'Open value', 'Offener Wert', 'Valeur restante'],
            'srm.dashboard.col.invoice' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'srm.dashboard.col.variance' => ['Scostamento', 'Variance', 'Abweichung', 'Écart'],

            'srm.kpi.pendingRequisitions' => ['RdA da approvare', 'Requisitions to approve', 'Zu genehmigende Bedarfsanforderungen', 'Demandes d’achat à approuver'],
            'srm.kpi.openRfqs' => ['Gare aperte', 'Open tenders', 'Offene Ausschreibungen', 'Appels d’offres ouverts'],
            'srm.kpi.openOrders' => ['Ordini aperti', 'Open orders', 'Offene Bestellungen', 'Commandes ouvertes'],
            'srm.kpi.overdueOrders' => ['Ordini in ritardo', 'Overdue orders', 'Überfällige Bestellungen', 'Commandes en retard'],
            'srm.kpi.receiptsToCheck' => ['Ricevimenti da controllare o fatturare', 'Receipts to inspect or invoice', 'Wareneingänge zu prüfen oder zu fakturieren', 'Réceptions à contrôler ou facturer'],
            'srm.kpi.blockedInvoices' => ['Fatture bloccate', 'Blocked invoices', 'Gesperrte Rechnungen', 'Factures bloquées'],

            'srm.qualification.prospect' => ['Potenziale', 'Prospect', 'Interessent', 'Prospect'],
            'srm.qualification.in_qualification' => ['In qualifica', 'In qualification', 'In Qualifizierung', 'En qualification'],
            'srm.qualification.qualified' => ['Qualificato', 'Qualified', 'Qualifiziert', 'Qualifié'],
            'srm.qualification.conditional' => ['Qualificato con riserva', 'Conditionally qualified', 'Bedingt qualifiziert', 'Qualifié sous condition'],
            'srm.qualification.preferred' => ['Preferenziale', 'Preferred', 'Bevorzugt', 'Préférentiel'],
            'srm.qualification.expired' => ['Qualifica scaduta', 'Qualification expired', 'Qualifikation abgelaufen', 'Qualification expirée'],
            'srm.qualification.suspended' => ['Sospeso', 'Suspended', 'Gesperrt', 'Suspendu'],
            'srm.qualification.disqualified' => ['Non qualificato', 'Disqualified', 'Disqualifiziert', 'Disqualifié'],
            'srm.qualification.blacklisted' => ['In lista nera', 'Blacklisted', 'Auf der schwarzen Liste', 'Sur liste noire'],

            'srm.tier.A' => ['A – Eccellente', 'A – Excellent', 'A – Ausgezeichnet', 'A – Excellent'],
            'srm.tier.B' => ['B – Buono', 'B – Good', 'B – Gut', 'B – Bon'],
            'srm.tier.C' => ['C – Da monitorare', 'C – Watch', 'C – Beobachten', 'C – À surveiller'],
            'srm.tier.D' => ['D – Critico', 'D – Critical', 'D – Kritisch', 'D – Critique'],

            'srm.component.on_time_delivery_rate' => ['Puntualità consegne (OTD)', 'On-time delivery (OTD)', 'Termintreue (OTD)', 'Livraison à temps (OTD)'],
            'srm.component.quality_acceptance_rate' => ['Qualità e conformità', 'Quality and conformity', 'Qualität und Konformität', 'Qualité et conformité'],
            'srm.component.price_competitiveness_score' => ['Accuratezza prezzi', 'Price accuracy', 'Preisgenauigkeit', 'Exactitude des prix'],
            'srm.component.invoice_accuracy_rate' => ['Accuratezza fatture (3-way match)', 'Invoice accuracy (3-way match)', 'Rechnungsgenauigkeit (3-Wege-Abgleich)', 'Exactitude des factures (3-way match)'],
            'srm.component.rfq_responsiveness_rate' => ['Reattività alle gare', 'RFQ responsiveness', 'Reaktionsfähigkeit bei Ausschreibungen', 'Réactivité aux appels d’offres'],

            'srm.scorecards.title' => ['Scorecard fornitori', 'Supplier scorecards', 'Lieferanten-Scorecards', 'Scorecards fournisseurs'],
            'srm.scorecards.subtitle' => ['Valutazione composita 0-100 e classe A-D calcolata sui documenti del ciclo passivo', 'Composite 0-100 rating and A-D tier computed from the purchase-to-pay documents', 'Gesamtbewertung 0-100 und Klasse A-D, berechnet aus den Belegen des Einkaufsprozesses', 'Note composite 0-100 et classe A-D calculées sur les documents du cycle d’achat'],
            'srm.scorecards.search' => ['Cerca fornitore', 'Search supplier', 'Lieferant suchen', 'Rechercher un fournisseur'],
            'srm.scorecards.allTiers' => ['Tutte le classi', 'All tiers', 'Alle Klassen', 'Toutes les classes'],
            'srm.scorecards.empty' => ['Nessun fornitore valutabile nel periodo', 'No supplier to rate in the period', 'Kein bewertbarer Lieferant im Zeitraum', 'Aucun fournisseur évaluable sur la période'],
            'srm.scorecards.noData' => ['Dati insufficienti nel periodo', 'Not enough data in the period', 'Nicht genügend Daten im Zeitraum', 'Données insuffisantes sur la période'],
            'srm.scorecards.legend' => ['Pesi: consegne 35%, qualità 30%, prezzi 15%, fatture 10%, gare 10%. Le componenti senza dati sono escluse e i pesi ricalcolati. Classi: A da 90, B da 75, C da 60, D sotto 60.', 'Weights: delivery 35%, quality 30%, price 15%, invoices 10%, tenders 10%. Components without data are left out and the weights renormalised. Tiers: A from 90, B from 75, C from 60, D below 60.', 'Gewichte: Lieferung 35 %, Qualität 30 %, Preis 15 %, Rechnungen 10 %, Ausschreibungen 10 %. Komponenten ohne Daten entfallen, die Gewichte werden neu normiert. Klassen: A ab 90, B ab 75, C ab 60, D darunter.', 'Pondérations : livraison 35 %, qualité 30 %, prix 15 %, factures 10 %, appels d’offres 10 %. Les composantes sans données sont exclues et les pondérations recalculées. Classes : A dès 90, B dès 75, C dès 60, D en dessous.'],
            'srm.scorecards.col.supplier' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'srm.scorecards.col.qualification' => ['Qualifica', 'Qualification', 'Qualifikation', 'Qualification'],
            'srm.scorecards.col.score' => ['Punteggio', 'Score', 'Punktzahl', 'Score'],
            'srm.scorecards.col.tier' => ['Classe', 'Tier', 'Klasse', 'Classe'],

            'srm.flow.title' => ['Flusso documentale', 'Document flow', 'Belegfluss', 'Flux documentaire'],
            'srm.flow.subtitle' => ['Documenti collegati a monte e a valle lungo il ciclo passivo', 'Linked upstream and downstream documents along the procure-to-pay chain', 'Verknüpfte vor- und nachgelagerte Belege entlang des Einkaufsprozesses', 'Documents liés en amont et en aval le long du cycle d’achat'],
            'srm.flow.open' => ['Flusso documentale', 'Document flow', 'Belegfluss', 'Flux documentaire'],
            'srm.flow.loadError' => ['Impossibile caricare il flusso documentale', 'The document flow could not be loaded', 'Der Belegfluss konnte nicht geladen werden', 'Impossible de charger le flux documentaire'],
            'srm.flow.empty' => ['Nessun documento collegato', 'No linked documents', 'Keine verknüpften Belege', 'Aucun document lié'],
            'srm.flow.current' => ['Documento corrente', 'Current document', 'Aktueller Beleg', 'Document courant'],
            'srm.flow.quantities' => ['Ordinato, ricevuto, fatturato e residuo', 'Ordered, received, invoiced and remaining', 'Bestellt, erhalten, fakturiert und offen', 'Commandé, reçu, facturé et restant'],
            'srm.flow.noQuantities' => ['Nessun ordine collegato', 'No linked order', 'Keine verknüpfte Bestellung', 'Aucune commande liée'],
            'srm.flow.total' => ['Totale', 'Total', 'Total', 'Total'],
            'srm.flow.unsupportedType' => ['Tipo di documento non supportato', 'Unsupported document type', 'Nicht unterstützter Belegtyp', 'Type de document non pris en charge'],
            'srm.flow.qty.ordered' => ['Ordinato', 'Ordered', 'Bestellt', 'Commandé'],
            'srm.flow.qty.received' => ['Ricevuto', 'Received', 'Erhalten', 'Reçu'],
            'srm.flow.qty.invoiced' => ['Fatturato', 'Invoiced', 'Fakturiert', 'Facturé'],
            'srm.flow.qty.returned' => ['Reso', 'Returned', 'Retourniert', 'Retourné'],
            'srm.flow.qty.remaining' => ['Residuo', 'Remaining', 'Offen', 'Restant'],
            'srm.flow.qty.progress' => ['Avanzamento ricevuto / fatturato', 'Received / invoiced progress', 'Fortschritt erhalten / fakturiert', 'Avancement reçu / facturé'],

            'srm.flow.type.requisition' => ['Richiesta d’acquisto', 'Purchase requisition', 'Bedarfsanforderung', 'Demande d’achat'],
            'srm.flow.type.rfq' => ['Gara d’acquisto (RFQ)', 'Request for quotation (RFQ)', 'Ausschreibung (RFQ)', 'Appel d’offres (RFQ)'],
            'srm.flow.type.framework_agreement' => ['Accordo quadro', 'Framework agreement', 'Rahmenvertrag', 'Accord-cadre'],
            'srm.flow.type.purchase_order' => ['Ordine fornitore', 'Purchase order', 'Bestellung', 'Commande fournisseur'],
            'srm.flow.type.goods_receipt' => ['Ricevimento merci', 'Goods receipt', 'Wareneingang', 'Réception de marchandises'],
            'srm.flow.type.supplier_invoice' => ['Fattura fornitore', 'Supplier invoice', 'Lieferantenrechnung', 'Facture fournisseur'],
            'srm.flow.type.purchase_return' => ['Reso a fornitore', 'Purchase return', 'Lieferantenretoure', 'Retour fournisseur'],

            'srm.flow.relation.sourced_by' => ['Gara generata dalla richiesta', 'Tender created from the requisition', 'Aus der Anforderung erstellte Ausschreibung', 'Appel d’offres issu de la demande'],
            'srm.flow.relation.awarded_to' => ['Ordine generato dall’aggiudicazione', 'Order created by the award', 'Durch den Zuschlag erzeugte Bestellung', 'Commande issue de l’attribution'],
            'srm.flow.relation.converted_to' => ['Richiesta convertita in ordine', 'Requisition converted to an order', 'Anforderung in Bestellung umgewandelt', 'Demande convertie en commande'],
            'srm.flow.relation.called_off_by' => ['Richiamo dell’accordo quadro', 'Call-off of the framework agreement', 'Abruf des Rahmenvertrags', 'Appel de l’accord-cadre'],
            'srm.flow.relation.received_by' => ['Merce ricevuta', 'Goods received', 'Ware erhalten', 'Marchandise reçue'],
            'srm.flow.relation.matched_by' => ['Controllo incrociato a 3 vie', '3-way matched', '3-Wege-abgeglichen', 'Rapprochement à 3 voies'],
            'srm.flow.relation.invoiced_by' => ['Fatturato', 'Invoiced', 'Fakturiert', 'Facturé'],
            'srm.flow.relation.returned_by' => ['Reso al fornitore', 'Returned to the supplier', 'An den Lieferanten retourniert', 'Retourné au fournisseur'],
            'srm.flow.relation.credited_by' => ['Nota di credito ricevuta', 'Credit note received', 'Gutschrift erhalten', 'Avoir reçu'],

            'srm.flow.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'srm.flow.status.pending_approval' => ['In approvazione', 'Pending approval', 'Zur Genehmigung', 'En approbation'],
            'srm.flow.status.approved' => ['Approvato', 'Approved', 'Genehmigt', 'Approuvé'],
            'srm.flow.status.rejected' => ['Respinto', 'Rejected', 'Abgelehnt', 'Rejeté'],
            'srm.flow.status.sourcing' => ['In sourcing', 'Sourcing', 'In Beschaffung', 'En sourcing'],
            'srm.flow.status.converted_to_po' => ['Convertita in ordine', 'Converted to order', 'In Bestellung umgewandelt', 'Convertie en commande'],
            'srm.flow.status.cancelled' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],
            'srm.flow.status.issued' => ['Emessa', 'Issued', 'Ausgegeben', 'Émis'],
            'srm.flow.status.published' => ['Pubblicata', 'Published', 'Veröffentlicht', 'Publié'],
            'srm.flow.status.bidding_open' => ['Offerte aperte', 'Bidding open', 'Angebotsphase offen', 'Offres ouvertes'],
            'srm.flow.status.under_evaluation' => ['In valutazione', 'Under evaluation', 'In Bewertung', 'En évaluation'],
            'srm.flow.status.awarded' => ['Aggiudicata', 'Awarded', 'Zugeschlagen', 'Attribué'],
            'srm.flow.status.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'srm.flow.status.expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],
            'srm.flow.status.closed' => ['Chiuso', 'Closed', 'Geschlossen', 'Clôturé'],
            'srm.flow.status.sent' => ['Inviato', 'Sent', 'Gesendet', 'Envoyé'],
            'srm.flow.status.confirmed' => ['Confermato', 'Confirmed', 'Bestätigt', 'Confirmé'],
            'srm.flow.status.partially_received' => ['Ricevuto in parte', 'Partially received', 'Teilweise erhalten', 'Partiellement reçu'],
            'srm.flow.status.received' => ['Ricevuto', 'Received', 'Erhalten', 'Reçu'],
            'srm.flow.status.pending' => ['In attesa di controllo qualità', 'Pending inspection', 'Prüfung ausstehend', 'Contrôle en attente'],
            'srm.flow.status.passed' => ['Controllo superato', 'Inspection passed', 'Prüfung bestanden', 'Contrôle réussi'],
            'srm.flow.status.failed' => ['Controllo non superato', 'Inspection failed', 'Prüfung nicht bestanden', 'Contrôle échoué'],
            'srm.flow.status.partial' => ['Accettato in parte', 'Partially accepted', 'Teilweise akzeptiert', 'Partiellement accepté'],
            'srm.flow.status.not_required' => ['Controllo non richiesto', 'No inspection required', 'Keine Prüfung erforderlich', 'Aucun contrôle requis'],
            'srm.flow.status.accepted' => ['Accettato', 'Accepted', 'Akzeptiert', 'Accepté'],
            'srm.flow.status.released' => ['Rilasciato', 'Released', 'Freigegeben', 'Libéré'],
            'srm.flow.status.completed' => ['Completato', 'Completed', 'Abgeschlossen', 'Terminé'],
            'srm.flow.status.matched' => ['Corrispondente', 'Matched', 'Abgeglichen', 'Rapprochée'],
            'srm.flow.status.within_tolerance' => ['Entro tolleranza', 'Within tolerance', 'Innerhalb der Toleranz', 'Dans la tolérance'],
            'srm.flow.status.blocked_discrepancy' => ['Bloccata per discrepanza', 'Blocked for discrepancy', 'Wegen Abweichung gesperrt', 'Bloquée pour écart'],
            'srm.flow.status.pending_credit_note' => ['In attesa di nota di credito', 'Pending credit note', 'Gutschrift ausstehend', 'Avoir en attente'],
            'srm.flow.status.override_approved' => ['Deroga approvata', 'Override approved', 'Ausnahme genehmigt', 'Dérogation approuvée'],
            'srm.flow.status.resolved' => ['Risolta', 'Resolved', 'Gelöst', 'Résolue'],
            'srm.flow.status.escalated_to_workflow' => ['Inoltrata al flusso approvativo', 'Escalated to approval workflow', 'An den Genehmigungsworkflow eskaliert', 'Escaladée vers le workflow d’approbation'],
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
            default => 1,
        };

        return array_map(static fn (array $texts): string => $texts[$index], self::definitions());
    }
}
