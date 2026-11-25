<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the franchise network governance module (Mod 244): the franchisor command view, stores, affiliation agreements
 * (L. 129/2004), royalty and marketing fund formulas, central pushes, monthly billing, store audits and the franchisee portal with
 * its anonymous benchmark, plus the codes the API reports (franchiseNetwork.error.<code>, franchiseNetwork.finding.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, SEPA SDD, LSV+, LCart, CO, POS) stay untranslated.
 * The wording of the checklists, the formula presets and the benchmark KPIs is catalogue data seeded by FranchiseNetworkReferenceSeeder.
 */
final class FranchiseNetworkFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::errors(),
            self::findings(),
            self::fields(),
            self::actions(),
            self::confirmations(),
            self::sections(),
            self::kpis(),
            self::hints(),
            self::empties(),
            self::notifications(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.FRANCHISE_NETWORK' => ['Rete in franchising', 'Franchise network', 'Franchisenetzwerk', 'Réseau de franchise'],
            'NAV.FRANCHISE_NETWORK_COMMAND' => ['Cruscotto franchisor', 'Franchisor command view', 'Franchisegeber-Cockpit', 'Tableau de bord franchiseur'],
            'NAV.FRANCHISE_NETWORK_STORES' => ['Punti vendita', 'Stores', 'Standorte', 'Points de vente'],
            'NAV.FRANCHISE_NETWORK_AGREEMENTS' => ['Contratti di affiliazione', 'Affiliation agreements', 'Franchiseverträge', 'Contrats de franchise'],
            'NAV.FRANCHISE_NETWORK_FORMULAS' => ['Formule royalty', 'Royalty formulas', 'Royalty-Formeln', 'Formules de redevance'],
            'NAV.FRANCHISE_NETWORK_PUSHES' => ['Distribuzioni centrali', 'Central pushes', 'Zentrale Verteilungen', 'Diffusions centrales'],
            'NAV.FRANCHISE_NETWORK_BILLING' => ['Fatturazione royalty', 'Royalty billing', 'Royalty-Fakturierung', 'Facturation des redevances'],
            'NAV.FRANCHISE_NETWORK_AUDITS' => ['Audit punti vendita', 'Store audits', 'Standort-Audits', 'Audits des points de vente'],
            'NAV.FRANCHISE_NETWORK_AUDIT_RUN' => ['Visita di audit', 'Audit visit', 'Audit-Besuch', 'Visite d’audit'],
            'NAV.FRANCHISE_NETWORK_PORTAL' => ['Portale affiliato', 'Franchisee portal', 'Franchisenehmer-Portal', 'Portail franchisé'],
            'PAGE_TITLE.FRANCHISE_NETWORK_COMMAND' => ['Cruscotto della rete in franchising', 'Franchise network command view', 'Cockpit des Franchisenetzwerks', 'Tableau de bord du réseau de franchise'],
            'PAGE_TITLE.FRANCHISE_NETWORK_STORES' => ['Punti vendita della rete', 'Network stores', 'Standorte des Netzwerks', 'Points de vente du réseau'],
            'PAGE_TITLE.FRANCHISE_NETWORK_AGREEMENTS' => ['Contratti di affiliazione e scadenze', 'Affiliation agreements and expiries', 'Franchiseverträge und Fristen', 'Contrats de franchise et échéances'],
            'PAGE_TITLE.FRANCHISE_NETWORK_FORMULAS' => ['Formule di royalty e fondo marketing', 'Royalty and marketing fund formulas', 'Royalty- und Marketingfonds-Formeln', 'Formules de redevance et de fonds marketing'],
            'PAGE_TITLE.FRANCHISE_NETWORK_PUSHES' => ['Distribuzione centrale di catalogo, listini e promozioni', 'Central distribution of catalogue, prices and promotions', 'Zentrale Verteilung von Katalog, Preisen und Aktionen', 'Diffusion centrale du catalogue, des tarifs et des promotions'],
            'PAGE_TITLE.FRANCHISE_NETWORK_BILLING' => ['Royalty e fondo marketing mensili', 'Monthly royalty and marketing fund', 'Monatliche Royalty und Marketingfonds', 'Redevance et fonds marketing mensuels'],
            'PAGE_TITLE.FRANCHISE_NETWORK_AUDITS' => ['Audit dei punti vendita', 'Store audits', 'Standort-Audits', 'Audits des points de vente'],
            'PAGE_TITLE.FRANCHISE_NETWORK_AUDIT_RUN' => ['Checklist di audit del punto vendita', 'Store audit checklist', 'Audit-Checkliste des Standorts', 'Liste de contrôle d’audit du point de vente'],
            'PAGE_TITLE.FRANCHISE_NETWORK_PORTAL' => ['Portale dell’affiliato', 'Franchisee portal', 'Portal des Franchisenehmers', 'Portail du franchisé'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'franchiseNetwork.storeType.franchisee' => ['In franchising', 'Franchisee', 'Franchise', 'Franchisé'],
            'franchiseNetwork.storeType.dos' => ['Diretto (DOS)', 'Directly operated (DOS)', 'Eigenbetrieb (DOS)', 'Exploité en direct (DOS)'],
            'franchiseNetwork.storeStatus.pipeline' => ['In apertura', 'Opening soon', 'In Eröffnung', 'En ouverture'],
            'franchiseNetwork.storeStatus.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'franchiseNetwork.storeStatus.suspended' => ['Sospeso', 'Suspended', 'Gesperrt', 'Suspendu'],
            'franchiseNetwork.storeStatus.closed' => ['Chiuso', 'Closed', 'Geschlossen', 'Fermé'],
            'franchiseNetwork.sizeCluster.small' => ['Piccolo', 'Small', 'Klein', 'Petit'],
            'franchiseNetwork.sizeCluster.medium' => ['Medio', 'Medium', 'Mittel', 'Moyen'],
            'franchiseNetwork.sizeCluster.large' => ['Grande', 'Large', 'Gross', 'Grand'],
            'franchiseNetwork.agreementStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'franchiseNetwork.agreementStatus.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'franchiseNetwork.agreementStatus.renewed' => ['Rinnovato', 'Renewed', 'Verlängert', 'Renouvelé'],
            'franchiseNetwork.agreementStatus.terminated' => ['Risolto', 'Terminated', 'Beendet', 'Résilié'],
            'franchiseNetwork.agreementStatus.expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Expiré'],
            'franchiseNetwork.paymentMethod.sepa_sdd' => ['Addebito diretto SEPA SDD', 'SEPA SDD direct debit', 'SEPA-Lastschrift SDD', 'Prélèvement SEPA SDD'],
            'franchiseNetwork.paymentMethod.lsv_plus' => ['Addebito diretto LSV+', 'LSV+ direct debit', 'Lastschrift LSV+', 'Prélèvement LSV+'],
            'franchiseNetwork.paymentMethod.bank_transfer' => ['Bonifico bancario', 'Bank transfer', 'Banküberweisung', 'Virement bancaire'],
            'franchiseNetwork.guaranteeType.none' => ['Nessuna garanzia', 'No guarantee', 'Keine Garantie', 'Aucune garantie'],
            'franchiseNetwork.guaranteeType.bank_guarantee' => ['Fideiussione bancaria', 'Bank guarantee', 'Bankgarantie', 'Garantie bancaire'],
            'franchiseNetwork.guaranteeType.insurance' => ['Fideiussione assicurativa', 'Insurance guarantee', 'Versicherungsgarantie', 'Caution d’assurance'],
            'franchiseNetwork.guaranteeType.deposit' => ['Deposito cauzionale', 'Security deposit', 'Kaution', 'Dépôt de garantie'],
            'franchiseNetwork.startupMode.none' => ['Nessuna agevolazione', 'No relief', 'Keine Vergünstigung', 'Aucun allègement'],
            'franchiseNetwork.startupMode.full_exemption' => ['Esenzione totale', 'Full exemption', 'Vollständige Befreiung', 'Exonération totale'],
            'franchiseNetwork.startupMode.reduced_rate' => ['Aliquota ridotta', 'Reduced rate', 'Reduzierter Satz', 'Taux réduit'],
            'franchiseNetwork.startupMode.ramp_up' => ['Avvio graduale', 'Gradual ramp-up', 'Gestaffelter Anlauf', 'Montée en charge progressive'],
            'franchiseNetwork.pushType.catalog' => ['Catalogo', 'Catalogue', 'Katalog', 'Catalogue'],
            'franchiseNetwork.pushType.price_list' => ['Listino prezzi', 'Price list', 'Preisliste', 'Tarif'],
            'franchiseNetwork.pushType.promotion' => ['Promozione', 'Promotion', 'Aktion', 'Promotion'],
            'franchiseNetwork.pushType.loyalty_campaign' => ['Campagna fedeltà', 'Loyalty campaign', 'Treuekampagne', 'Campagne de fidélité'],
            'franchiseNetwork.pushStatus.draft' => ['Da inviare', 'To send', 'Zu senden', 'À envoyer'],
            'franchiseNetwork.pushStatus.completed' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminée'],
            'franchiseNetwork.pushStatus.partial' => ['Parziale', 'Partial', 'Teilweise', 'Partielle'],
            'franchiseNetwork.pushStatus.failed' => ['Fallita', 'Failed', 'Fehlgeschlagen', 'Échouée'],
            'franchiseNetwork.targetStatus.pending' => ['In attesa', 'Pending', 'Ausstehend', 'En attente'],
            'franchiseNetwork.targetStatus.synced' => ['Consegnata', 'Synced', 'Zugestellt', 'Livrée'],
            'franchiseNetwork.targetStatus.acknowledged' => ['Confermata', 'Acknowledged', 'Bestätigt', 'Confirmée'],
            'franchiseNetwork.targetStatus.failed' => ['In errore', 'Failed', 'Fehler', 'En erreur'],
            'franchiseNetwork.targetMode.all' => ['Tutti i punti vendita', 'All stores', 'Alle Standorte', 'Tous les points de vente'],
            'franchiseNetwork.targetMode.selected' => ['Punti vendita selezionati', 'Selected stores', 'Ausgewählte Standorte', 'Points de vente sélectionnés'],
            'franchiseNetwork.priceType.recommended' => ['Consigliato (non vincolante)', 'Recommended (non-binding)', 'Empfohlen (unverbindlich)', 'Recommandé (non contraignant)'],
            'franchiseNetwork.priceType.maximum' => ['Massimo', 'Maximum', 'Höchstpreis', 'Maximum'],
            'franchiseNetwork.runStatus.calculated' => ['Calcolato', 'Calculated', 'Berechnet', 'Calculé'],
            'franchiseNetwork.runStatus.invoiced' => ['Fatturato', 'Invoiced', 'Fakturiert', 'Facturé'],
            'franchiseNetwork.statementStatus.calculated' => ['Calcolato', 'Calculated', 'Berechnet', 'Calculé'],
            'franchiseNetwork.statementStatus.invoiced' => ['Fatturato', 'Invoiced', 'Fakturiert', 'Facturé'],
            'franchiseNetwork.statementStatus.skipped' => ['Non fatturabile', 'Not billable', 'Nicht fakturierbar', 'Non facturable'],
            'franchiseNetwork.scheme.sepa_sdd' => ['Distinta SEPA SDD', 'SEPA SDD batch', 'SEPA-SDD-Sammler', 'Lot SEPA SDD'],
            'franchiseNetwork.scheme.lsv_plus' => ['Distinta LSV+', 'LSV+ batch', 'LSV+-Sammler', 'Lot LSV+'],
            'franchiseNetwork.batchStatus.ready' => ['Pronta per la banca', 'Ready for the bank', 'Bereit für die Bank', 'Prêt pour la banque'],
            'franchiseNetwork.batchStatus.empty' => ['Nessun addebito possibile', 'Nothing to collect', 'Nichts einzuziehen', 'Rien à encaisser'],
            'franchiseNetwork.sellOutSource.pos' => ['POS', 'POS', 'POS', 'TPE'],
            'franchiseNetwork.sellOutSource.fiscal_printer' => ['Registratore telematico', 'Fiscal printer', 'Fiskaldrucker', 'Imprimante fiscale'],
            'franchiseNetwork.sellOutSource.manual' => ['Inserimento manuale', 'Manual entry', 'Manuelle Erfassung', 'Saisie manuelle'],
            'franchiseNetwork.auditStatus.draft' => ['In corso', 'In progress', 'In Arbeit', 'En cours'],
            'franchiseNetwork.auditStatus.submitted' => ['Inviato', 'Submitted', 'Übermittelt', 'Soumis'],
            'franchiseNetwork.actionStatus.open' => ['Aperta', 'Open', 'Offen', 'Ouverte'],
            'franchiseNetwork.actionStatus.done' => ['Chiusa', 'Done', 'Erledigt', 'Clôturée'],
            'franchiseNetwork.category.signage' => ['Insegna', 'Signage', 'Beschilderung', 'Enseigne'],
            'franchiseNetwork.category.cleanliness' => ['Pulizia', 'Cleanliness', 'Sauberkeit', 'Propreté'],
            'franchiseNetwork.category.visual_merchandising' => ['Visual merchandising', 'Visual merchandising', 'Visual Merchandising', 'Merchandising visuel'],
            'franchiseNetwork.category.layout' => ['Layout', 'Layout', 'Layout', 'Agencement'],
            'franchiseNetwork.category.stock_pricing' => ['Scorte e prezzi', 'Stock and pricing', 'Bestand und Preise', 'Stocks et prix'],
            'franchiseNetwork.position.top_quartile' => ['Tra i migliori 25%', 'Top 25%', 'Unter den besten 25 %', 'Parmi les 25 % meilleurs'],
            'franchiseNetwork.position.above_median' => ['Sopra la mediana', 'Above the median', 'Über dem Median', 'Au-dessus de la médiane'],
            'franchiseNetwork.position.below_median' => ['Sotto la mediana', 'Below the median', 'Unter dem Median', 'Sous la médiane'],
            'franchiseNetwork.purchaseSource.central_warehouse' => ['Magazzino centrale', 'Central warehouse', 'Zentrallager', 'Entrepôt central'],
            'franchiseNetwork.purchaseSource.approved_suppliers' => ['Fornitori approvati', 'Approved suppliers', 'Freigegebene Lieferanten', 'Fournisseurs agréés'],
            'franchiseNetwork.skip.directly_operated' => ['Punto vendita diretto: nessuna royalty', 'Directly operated store: no royalty', 'Eigenbetrieb: keine Royalty', 'Point de vente exploité en direct : pas de redevance'],
            'franchiseNetwork.skip.no_active_agreement' => ['Nessun contratto in vigore nel mese', 'No agreement in force in the month', 'Kein Vertrag im Monat in Kraft', 'Aucun contrat en vigueur dans le mois'],
            'franchiseNetwork.skip.no_formula' => ['Il contratto non ha una formula', 'The agreement has no formula', 'Der Vertrag hat keine Formel', 'Le contrat n’a pas de formule'],
            'franchiseNetwork.skip.no_sell_out' => ['Sell-out del mese non comunicato', 'Sell-out of the month not reported', 'Sell-out des Monats nicht gemeldet', 'Sell-out du mois non communiqué'],
            'franchiseNetwork.skip.zero_amount' => ['Importo a zero', 'Zero amount', 'Betrag null', 'Montant nul'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'franchiseNetwork.error.generic' => ['Operazione non riuscita.', 'The operation failed.', 'Der Vorgang ist fehlgeschlagen.', 'L’opération a échoué.'],
            'franchiseNetwork.error.tenant_missing' => ['Azienda attiva non determinata.', 'The active company could not be determined.', 'Das aktive Unternehmen konnte nicht bestimmt werden.', 'L’entreprise active n’a pas pu être déterminée.'],
            'franchiseNetwork.error.tiers_required' => ['Serve almeno uno scaglione di aliquota.', 'At least one rate bracket is required.', 'Mindestens eine Staffel ist erforderlich.', 'Au moins une tranche de taux est requise.'],
            'franchiseNetwork.error.tiers_not_contiguous' => ['Gli scaglioni devono partire da zero ed essere contigui.', 'Brackets must start at zero and be contiguous.', 'Die Staffeln müssen bei null beginnen und lückenlos sein.', 'Les tranches doivent commencer à zéro et être contiguës.'],
            'franchiseNetwork.error.tier_rate_out_of_range' => ['L’aliquota di uno scaglione deve essere tra 0 e 100.', 'A bracket rate must be between 0 and 100.', 'Der Satz einer Staffel muss zwischen 0 und 100 liegen.', 'Le taux d’une tranche doit être compris entre 0 et 100.'],
            'franchiseNetwork.error.tiers_top_must_be_open' => ['L’ultimo scaglione deve essere aperto, senza limite superiore.', 'The last bracket must be open-ended.', 'Die letzte Staffel muss nach oben offen sein.', 'La dernière tranche doit être ouverte, sans limite supérieure.'],
            'franchiseNetwork.error.tiers_not_ascending' => ['I limiti degli scaglioni devono essere crescenti.', 'Bracket limits must be ascending.', 'Die Staffelgrenzen müssen aufsteigend sein.', 'Les limites des tranches doivent être croissantes.'],
            'franchiseNetwork.error.formula_preset_read_only' => ['Le formule predefinite non si modificano: duplicale e modifica la copia.', 'Preset formulas cannot be edited: copy one and edit the copy.', 'Vordefinierte Formeln sind schreibgeschützt: Kopie erstellen und diese bearbeiten.', 'Les formules prédéfinies ne se modifient pas : dupliquez-en une et modifiez la copie.'],
            'franchiseNetwork.error.formula_in_use' => ['La formula è usata da almeno un contratto.', 'The formula is used by at least one agreement.', 'Die Formel wird von mindestens einem Vertrag verwendet.', 'La formule est utilisée par au moins un contrat.'],
            'franchiseNetwork.error.startup_months_required' => ['Indica per quanti mesi vale l’agevolazione di avvio.', 'State for how many months the start-up relief applies.', 'Geben Sie an, wie viele Monate die Anlaufvergünstigung gilt.', 'Indiquez pendant combien de mois l’allègement de démarrage s’applique.'],
            'franchiseNetwork.error.agreement_closed' => ['Il contratto è chiuso e non si modifica.', 'The agreement is closed and cannot be edited.', 'Der Vertrag ist abgeschlossen und kann nicht bearbeitet werden.', 'Le contrat est clos et ne peut plus être modifié.'],
            'franchiseNetwork.error.agreement_not_draft' => ['Solo un contratto in bozza può essere attivato o eliminato.', 'Only a draft agreement can be activated or deleted.', 'Nur ein Vertragsentwurf kann aktiviert oder gelöscht werden.', 'Seul un contrat en brouillon peut être activé ou supprimé.'],
            'franchiseNetwork.error.agreement_not_active' => ['Il contratto non è attivo.', 'The agreement is not active.', 'Der Vertrag ist nicht aktiv.', 'Le contrat n’est pas actif.'],
            'franchiseNetwork.error.store_already_has_active_agreement' => ['Il punto vendita ha già un contratto attivo.', 'The store already has an active agreement.', 'Der Standort hat bereits einen aktiven Vertrag.', 'Le point de vente a déjà un contrat actif.'],
            'franchiseNetwork.error.agreement_has_blocking_findings' => ['Il contratto non può essere attivato: {finding}.', 'The agreement cannot be activated: {finding}.', 'Der Vertrag kann nicht aktiviert werden: {finding}.', 'Le contrat ne peut pas être activé : {finding}.'],
            'franchiseNetwork.error.exclusivity_zone_overlap' => ['La zona di esclusiva si sovrappone a quella del punto vendita {store}.', 'The exclusivity zone overlaps the one of store {store}.', 'Die Exklusivzone überschneidet sich mit der des Standorts {store}.', 'La zone d’exclusivité chevauche celle du point de vente {store}.'],
            'franchiseNetwork.error.end_before_start' => ['La scadenza deve essere successiva alla decorrenza.', 'The end date must be after the start date.', 'Das Ende muss nach dem Beginn liegen.', 'La fin doit être postérieure au début.'],
            'franchiseNetwork.error.entry_fee_deferral_exceeds_term' => ['Il risconto del diritto d’ingresso non può superare la durata del contratto.', 'The entry fee deferral cannot be longer than the agreement term.', 'Die Abgrenzung der Eintrittsgebühr darf die Vertragslaufzeit nicht überschreiten.', 'Le report du droit d’entrée ne peut pas dépasser la durée du contrat.'],
            'franchiseNetwork.error.store_has_active_agreement' => ['Il punto vendita ha un contratto attivo.', 'The store has an active agreement.', 'Der Standort hat einen aktiven Vertrag.', 'Le point de vente a un contrat actif.'],
            'franchiseNetwork.error.store_not_franchisee' => ['Solo un punto vendita in franchising si collega a un’azienda affiliata.', 'Only a franchisee store links to an affiliated company.', 'Nur ein Franchise-Standort wird mit einem Partnerunternehmen verknüpft.', 'Seul un point de vente franchisé se lie à une entreprise affiliée.'],
            'franchiseNetwork.error.link_code_invalid' => ['Codice di collegamento non valido o già usato.', 'Link code invalid or already used.', 'Verknüpfungscode ungültig oder bereits verwendet.', 'Code de liaison invalide ou déjà utilisé.'],
            'franchiseNetwork.error.link_code_same_company' => ['Il codice va riscattato dall’azienda affiliata, non dal franchisor.', 'The code must be redeemed by the franchisee company, not by the franchisor.', 'Der Code muss vom Franchisenehmer eingelöst werden, nicht vom Franchisegeber.', 'Le code doit être utilisé par l’entreprise franchisée, pas par le franchiseur.'],
            'franchiseNetwork.error.portal_not_linked' => ['L’azienda non è collegata ad alcun punto vendita della rete.', 'The company is not linked to any store of the network.', 'Das Unternehmen ist mit keinem Standort des Netzwerks verknüpft.', 'L’entreprise n’est liée à aucun point de vente du réseau.'],
            'franchiseNetwork.error.portal_store_required' => ['Indica il punto vendita.', 'Choose the store.', 'Standort angeben.', 'Indiquez le point de vente.'],
            'franchiseNetwork.error.period_invalid' => ['Periodo non valido (formato AAAA-MM).', 'Invalid period (format YYYY-MM).', 'Ungültiger Zeitraum (Format JJJJ-MM).', 'Période invalide (format AAAA-MM).'],
            'franchiseNetwork.error.sell_out_locked' => ['Il mese è già fatturato: il sell-out non si modifica.', 'The month is already invoiced: the sell-out cannot be changed.', 'Der Monat ist bereits fakturiert: das Sell-out kann nicht geändert werden.', 'Le mois est déjà facturé : le sell-out ne peut plus être modifié.'],
            'franchiseNetwork.error.sell_out_negative' => ['Il netto venduto non può essere negativo.', 'Net sales cannot be negative.', 'Der Nettoumsatz darf nicht negativ sein.', 'Le chiffre d’affaires net ne peut pas être négatif.'],
            'franchiseNetwork.error.loyalty_points_invalid' => ['Il numero di punti non è valido.', 'The number of points is not valid.', 'Die Punktzahl ist ungültig.', 'Le nombre de points n’est pas valide.'],
            'franchiseNetwork.error.loyalty_balance_insufficient' => ['Il cliente non ha punti sufficienti nella rete.', 'The customer does not have enough points in the network.', 'Der Kunde hat im Netzwerk nicht genügend Punkte.', 'Le client n’a pas assez de points dans le réseau.'],
            'franchiseNetwork.error.run_already_invoiced' => ['Il mese è già stato fatturato.', 'The month has already been invoiced.', 'Der Monat wurde bereits fakturiert.', 'Le mois a déjà été facturé.'],
            'franchiseNetwork.error.store_without_customer' => ['Punti vendita senza anagrafica cliente per la fatturazione: {stores}.', 'Stores without a customer record for invoicing: {stores}.', 'Standorte ohne Kundenstamm für die Fakturierung: {stores}.', 'Points de vente sans fiche client pour la facturation : {stores}.'],
            'franchiseNetwork.error.store_not_active' => ['Il punto vendita non è attivo.', 'The store is not active.', 'Der Standort ist nicht aktiv.', 'Le point de vente n’est pas actif.'],
            'franchiseNetwork.error.store_not_linked' => ['L’azienda affiliata non ha ancora collegato il punto vendita.', 'The franchisee company has not linked the store yet.', 'Das Franchiseunternehmen hat den Standort noch nicht verknüpft.', 'L’entreprise franchisée n’a pas encore lié le point de vente.'],
            'franchiseNetwork.error.push_no_targets' => ['Nessun punto vendita destinatario.', 'No target store.', 'Kein Zielstandort.', 'Aucun point de vente destinataire.'],
            'franchiseNetwork.error.push_nothing_to_retry' => ['Nessuna consegna da ripetere.', 'No delivery to retry.', 'Keine Lieferung zu wiederholen.', 'Aucune livraison à relancer.'],
            'franchiseNetwork.error.push_not_synced' => ['La distribuzione non è ancora stata consegnata al punto vendita.', 'The distribution has not been delivered to the store yet.', 'Die Verteilung wurde dem Standort noch nicht zugestellt.', 'La diffusion n’a pas encore été livrée au point de vente.'],
            'franchiseNetwork.error.push_not_found' => ['Distribuzione non trovata.', 'Distribution not found.', 'Verteilung nicht gefunden.', 'Diffusion introuvable.'],
            'franchiseNetwork.error.audit_template_inactive' => ['La checklist non è attiva.', 'The checklist is not active.', 'Die Checkliste ist nicht aktiv.', 'La liste de contrôle n’est pas active.'],
            'franchiseNetwork.error.audit_criterion_unknown' => ['Criterio sconosciuto: {criterion}.', 'Unknown criterion: {criterion}.', 'Unbekanntes Kriterium: {criterion}.', 'Critère inconnu : {criterion}.'],
            'franchiseNetwork.error.audit_score_out_of_range' => ['Punteggio fuori scala per il criterio {criterion}.', 'Score out of range for criterion {criterion}.', 'Punktzahl ausserhalb des Bereichs für Kriterium {criterion}.', 'Note hors échelle pour le critère {criterion}.'],
            'franchiseNetwork.error.audit_too_many_photos' => ['Troppe foto allegate all’audit.', 'Too many photos attached to the audit.', 'Zu viele Fotos am Audit.', 'Trop de photos jointes à l’audit.'],
            'franchiseNetwork.error.audit_photo_not_found' => ['Foto non trovata.', 'Photo not found.', 'Foto nicht gefunden.', 'Photo introuvable.'],
            'franchiseNetwork.error.audit_locked' => ['L’audit è inviato e non si modifica.', 'The audit is submitted and cannot be changed.', 'Das Audit ist übermittelt und kann nicht geändert werden.', 'L’audit est soumis et ne peut plus être modifié.'],
            'franchiseNetwork.error.audit_incomplete' => ['Mancano {missing} criteri da valutare.', '{missing} criteria are still to be scored.', '{missing} Kriterien sind noch zu bewerten.', '{missing} critères restent à évaluer.'],
            'franchiseNetwork.error.audit_action_already_done' => ['L’azione correttiva è già chiusa.', 'The corrective action is already closed.', 'Die Korrekturmassnahme ist bereits abgeschlossen.', 'L’action corrective est déjà clôturée.'],
            'franchiseNetwork.error.benchmark_not_available' => ['Benchmark non disponibile per il periodo.', 'Benchmark not available for the period.', 'Benchmark für den Zeitraum nicht verfügbar.', 'Benchmark indisponible pour la période.'],
        ];
    }

    /**
     * Findings of the agreement rules (L. 129/2004, CO / LCart) and the alerts of the day.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function findings(): array
    {
        return [
            'franchiseNetwork.finding.term_below_statutory_minimum' => ['Durata di {months} mesi: la L. 129/2004 prevede almeno {minimum} mesi', 'Term of {months} months: L. 129/2004 requires at least {minimum} months', 'Laufzeit von {months} Monaten: das Gesetz 129/2004 verlangt mindestens {minimum} Monate', 'Durée de {months} mois : la loi 129/2004 exige au moins {minimum} mois'],
            'franchiseNetwork.finding.disclosure_period_too_short' => ['Informativa precontrattuale consegnata meno di {days} giorni prima della firma', 'Pre-contract disclosure delivered less than {days} days before signing', 'Vorvertragliche Information weniger als {days} Tage vor der Unterzeichnung übergeben', 'Information précontractuelle remise moins de {days} jours avant la signature'],
            'franchiseNetwork.finding.entry_fee_not_deferred' => ['Diritto d’ingresso senza risconto', 'Entry fee without deferral', 'Eintrittsgebühr ohne Abgrenzung', 'Droit d’entrée sans report'],
            'franchiseNetwork.finding.entry_fee_deferral_exceeds_term' => ['Risconto oltre la durata del contratto', 'Deferral longer than the agreement term', 'Abgrenzung über die Vertragslaufzeit hinaus', 'Report au-delà de la durée du contrat'],
            'franchiseNetwork.finding.mandate_missing' => ['Mandato di addebito mancante', 'Direct debit mandate missing', 'Lastschriftmandat fehlt', 'Mandat de prélèvement manquant'],
            'franchiseNetwork.finding.lsv_plus_requires_swiss_account' => ['LSV+ richiede un conto svizzero', 'LSV+ requires a Swiss account', 'LSV+ setzt ein Schweizer Konto voraus', 'LSV+ exige un compte suisse'],
            'franchiseNetwork.finding.guarantee_expiry_missing' => ['Scadenza della garanzia non indicata', 'Guarantee expiry not stated', 'Ablauf der Garantie nicht angegeben', 'Échéance de la garantie non indiquée'],
            'franchiseNetwork.finding.guarantee_expired' => ['Garanzia scaduta', 'Guarantee expired', 'Garantie abgelaufen', 'Garantie expirée'],
            'franchiseNetwork.finding.guarantee_expiring' => ['Garanzia in scadenza tra {days} giorni', 'Guarantee expires in {days} days', 'Garantie läuft in {days} Tagen ab', 'Garantie expirant dans {days} jours'],
            'franchiseNetwork.finding.guarantee_ends_before_agreement' => ['La garanzia scade prima del contratto', 'The guarantee ends before the agreement', 'Die Garantie endet vor dem Vertrag', 'La garantie expire avant le contrat'],
            'franchiseNetwork.finding.end_before_start' => ['Scadenza precedente alla decorrenza', 'End date before the start date', 'Ende vor dem Beginn', 'Fin antérieure au début'],
            'franchiseNetwork.finding.agreement_expired' => ['Contratto scaduto', 'Agreement expired', 'Vertrag abgelaufen', 'Contrat expiré'],
            'franchiseNetwork.finding.renewal_due' => ['Rinnovo da gestire: scade tra {days} giorni', 'Renewal due: ends in {days} days', 'Verlängerung fällig: endet in {days} Tagen', 'Renouvellement à traiter : échéance dans {days} jours'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'franchiseNetwork.field.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'franchiseNetwork.field.address' => ['Indirizzo', 'Address', 'Adresse', 'Adresse'],
            'franchiseNetwork.field.agreementNumber' => ['Numero contratto', 'Agreement number', 'Vertragsnummer', 'Numéro de contrat'],
            'franchiseNetwork.field.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'franchiseNetwork.field.attempts' => ['Tentativi', 'Attempts', 'Versuche', 'Tentatives'],
            'franchiseNetwork.field.auditedOn' => ['Data della visita', 'Visit date', 'Besuchsdatum', 'Date de la visite'],
            'franchiseNetwork.field.autoRenew' => ['Rinnovo automatico', 'Automatic renewal', 'Automatische Verlängerung', 'Renouvellement automatique'],
            'franchiseNetwork.field.averageInventory' => ['Valore medio delle scorte', 'Average stock value', 'Durchschnittlicher Lagerwert', 'Valeur moyenne des stocks'],
            'franchiseNetwork.field.belowMinimum' => ['Sotto il minimo', 'Below the minimum', 'Unter dem Minimum', 'Sous le minimum'],
            'franchiseNetwork.field.city' => ['Città', 'City', 'Ort', 'Ville'],
            'franchiseNetwork.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'franchiseNetwork.field.compliant' => ['Conforme', 'Compliant', 'Konform', 'Conforme'],
            'franchiseNetwork.field.compliantMonths' => ['Mesi conformi', 'Compliant months', 'Konforme Monate', 'Mois conformes'],
            'franchiseNetwork.field.costOfGoodsSold' => ['Costo del venduto', 'Cost of goods sold', 'Warenkosten', 'Coût des marchandises vendues'],
            'franchiseNetwork.field.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'franchiseNetwork.field.criterion' => ['Criterio', 'Criterion', 'Kriterium', 'Critère'],
            'franchiseNetwork.field.critical' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'franchiseNetwork.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'franchiseNetwork.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'franchiseNetwork.field.disclosureDeliveredOn' => ['Informativa precontrattuale consegnata il', 'Pre-contract disclosure delivered on', 'Vorvertragliche Information übergeben am', 'Information précontractuelle remise le'],
            'franchiseNetwork.field.discountPercent' => ['Sconto %', 'Discount %', 'Rabatt %', 'Remise %'],
            'franchiseNetwork.field.discounts' => ['Sconti', 'Discounts', 'Rabatte', 'Remises'],
            'franchiseNetwork.field.dispatchedAt' => ['Inviata il', 'Sent on', 'Gesendet am', 'Envoyée le'],
            'franchiseNetwork.field.dueDate' => ['Scadenza', 'Due date', 'Fälligkeit', 'Échéance'],
            'franchiseNetwork.field.effectiveFrom' => ['In vigore dal', 'Effective from', 'Gültig ab', 'En vigueur dès le'],
            'franchiseNetwork.field.endDate' => ['Scadenza contratto', 'End date', 'Vertragsende', 'Fin du contrat'],
            'franchiseNetwork.field.entryFee' => ['Diritto d’ingresso', 'Entry fee', 'Eintrittsgebühr', 'Droit d’entrée'],
            'franchiseNetwork.field.entryFeeDeferralMonths' => ['Risconto del diritto d’ingresso (mesi)', 'Entry fee deferral (months)', 'Abgrenzung der Eintrittsgebühr (Monate)', 'Report du droit d’entrée (mois)'],
            'franchiseNetwork.field.failed' => ['Non superato', 'Failed', 'Nicht bestanden', 'Non réussi'],
            'franchiseNetwork.field.fiscalId' => ['P/IVA o UID', 'VAT or UID', 'MwSt-Nr. oder UID', 'N° TVA ou IDE'],
            'franchiseNetwork.field.formula' => ['Formula royalty', 'Royalty formula', 'Royalty-Formel', 'Formule de redevance'],
            'franchiseNetwork.field.grossSales' => ['Venduto lordo', 'Gross sales', 'Bruttoumsatz', 'Chiffre d’affaires brut'],
            'franchiseNetwork.field.guarantee' => ['Garanzia', 'Guarantee', 'Garantie', 'Garantie'],
            'franchiseNetwork.field.guaranteeAmount' => ['Importo della garanzia', 'Guarantee amount', 'Garantiebetrag', 'Montant de la garantie'],
            'franchiseNetwork.field.guaranteeExpiresOn' => ['Scadenza della garanzia', 'Guarantee expiry', 'Ablauf der Garantie', 'Échéance de la garantie'],
            'franchiseNetwork.field.guaranteeReference' => ['Riferimento della garanzia', 'Guarantee reference', 'Garantiereferenz', 'Référence de la garantie'],
            'franchiseNetwork.field.guaranteeType' => ['Tipo di garanzia', 'Guarantee type', 'Garantieart', 'Type de garantie'],
            'franchiseNetwork.field.invoice' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'franchiseNetwork.field.jurisdiction' => ['Giurisdizione', 'Jurisdiction', 'Rechtsraum', 'Juridiction'],
            'franchiseNetwork.field.label' => ['Denominazione', 'Label', 'Bezeichnung', 'Libellé'],
            'franchiseNetwork.field.lastError' => ['Ultimo errore', 'Last error', 'Letzter Fehler', 'Dernière erreur'],
            'franchiseNetwork.field.linkCode' => ['Codice di collegamento', 'Link code', 'Verknüpfungscode', 'Code de liaison'],
            'franchiseNetwork.field.linked' => ['Collegato', 'Linked', 'Verknüpft', 'Lié'],
            'franchiseNetwork.field.loyaltySettlement' => ['Compensazione fedeltà', 'Loyalty settlement', 'Treueausgleich', 'Compensation fidélité'],
            'franchiseNetwork.field.mandateReference' => ['Riferimento del mandato', 'Mandate reference', 'Mandatsreferenz', 'Référence du mandat'],
            'franchiseNetwork.field.mandateSignedOn' => ['Mandato firmato il', 'Mandate signed on', 'Mandat unterzeichnet am', 'Mandat signé le'],
            'franchiseNetwork.field.marketingFund' => ['Fondo marketing', 'Marketing fund', 'Marketingfonds', 'Fonds marketing'],
            'franchiseNetwork.field.marketingTiers' => ['Scaglioni del fondo marketing (%)', 'Marketing fund brackets (%)', 'Staffeln des Marketingfonds (%)', 'Tranches du fonds marketing (%)'],
            'franchiseNetwork.field.minimumAdjustment' => ['Integrazione al minimo garantito', 'Minimum guarantee top-up', 'Aufstockung auf Mindestgarantie', 'Complément au minimum garanti'],
            'franchiseNetwork.field.minimumGuarantee' => ['Minimo garantito mensile', 'Monthly minimum guarantee', 'Monatliche Mindestgarantie', 'Minimum garanti mensuel'],
            'franchiseNetwork.field.minimumGuaranteeApplied' => ['Minimo garantito applicato', 'Minimum guarantee applied', 'Angewandte Mindestgarantie', 'Minimum garanti appliqué'],
            'franchiseNetwork.field.minPurchaseAmount' => ['Acquisti minimi mensili', 'Minimum monthly purchases', 'Monatliche Mindesteinkäufe', 'Achats minimaux mensuels'],
            'franchiseNetwork.field.minPurchaseSource' => ['Fonte degli acquisti minimi', 'Minimum purchase source', 'Quelle der Mindesteinkäufe', 'Source des achats minimaux'],
            'franchiseNetwork.field.months' => ['mesi', 'months', 'Monate', 'mois'],
            'franchiseNetwork.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'franchiseNetwork.field.net' => ['Saldo netto', 'Net position', 'Nettoposition', 'Solde net'],
            'franchiseNetwork.field.netSales' => ['Venduto netto', 'Net sales', 'Nettoumsatz', 'Chiffre d’affaires net'],
            'franchiseNetwork.field.newEndDate' => ['Nuova scadenza', 'New end date', 'Neues Vertragsende', 'Nouvelle échéance'],
            'franchiseNetwork.field.nextRetry' => ['Prossimo tentativo', 'Next retry', 'Nächster Versuch', 'Prochaine tentative'],
            'franchiseNetwork.field.no' => ['No', 'No', 'Nein', 'Non'],
            'franchiseNetwork.field.nonBindingPricing' => ['Prezzi solo consigliati, non vincolanti (CO / LCart)', 'Recommended, non-binding prices only (CO / LCart)', 'Nur unverbindliche Preisempfehlungen (OR / KG)', 'Prix recommandés non contraignants uniquement (CO / LCart)'],
            'franchiseNetwork.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'franchiseNetwork.field.notLinked' => ['Non collegato', 'Not linked', 'Nicht verknüpft', 'Non lié'],
            'franchiseNetwork.field.openedOn' => ['Data di apertura', 'Opening date', 'Eröffnungsdatum', 'Date d’ouverture'],
            'franchiseNetwork.field.overdue' => ['In ritardo', 'Overdue', 'Überfällig', 'En retard'],
            'franchiseNetwork.field.owed' => ['Dovuto alla rete', 'Owed to the network', 'Dem Netzwerk geschuldet', 'Dû au réseau'],
            'franchiseNetwork.field.passed' => ['Superato', 'Passed', 'Bestanden', 'Réussi'],
            'franchiseNetwork.field.paymentMethod' => ['Metodo di incasso', 'Collection method', 'Einzugsart', 'Mode d’encaissement'],
            'franchiseNetwork.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'franchiseNetwork.field.pointsMultiplier' => ['Moltiplicatore punti', 'Points multiplier', 'Punktemultiplikator', 'Multiplicateur de points'],
            'franchiseNetwork.field.posReference' => ['Riferimento POS / registratore', 'POS / register reference', 'POS-/Kassenreferenz', 'Référence TPE / caisse'],
            'franchiseNetwork.field.postalCode' => ['CAP', 'Postal code', 'Postleitzahl', 'Code postal'],
            'franchiseNetwork.field.preset' => ['Predefinita', 'Preset', 'Vordefiniert', 'Prédéfinie'],
            'franchiseNetwork.field.price' => ['Prezzo', 'Price', 'Preis', 'Prix'],
            'franchiseNetwork.field.priceType' => ['Tipo di prezzo', 'Price type', 'Preisart', 'Type de prix'],
            'franchiseNetwork.field.purchased' => ['Acquistato', 'Purchased', 'Eingekauft', 'Acheté'],
            'franchiseNetwork.field.pushType' => ['Tipo di distribuzione', 'Distribution type', 'Verteilungsart', 'Type de diffusion'],
            'franchiseNetwork.field.rampStartPercent' => ['Percentuale iniziale dell’avvio graduale', 'Ramp-up starting percentage', 'Startprozentsatz des Anlaufs', 'Pourcentage initial de la montée en charge'],
            'franchiseNetwork.field.receivable' => ['Da ricevere dalla rete', 'Receivable from the network', 'Vom Netzwerk zu erhalten', 'À recevoir du réseau'],
            'franchiseNetwork.field.recognized' => ['Riconosciuto', 'Recognised', 'Verbucht', 'Comptabilisé'],
            'franchiseNetwork.field.renewalNoticeDays' => ['Preavviso di rinnovo (giorni)', 'Renewal notice (days)', 'Verlängerungsfrist (Tage)', 'Préavis de renouvellement (jours)'],
            'franchiseNetwork.field.required' => ['Richiesto', 'Required', 'Erforderlich', 'Requis'],
            'franchiseNetwork.field.result' => ['Esito', 'Result', 'Ergebnis', 'Résultat'],
            'franchiseNetwork.field.returns' => ['Resi', 'Returns', 'Retouren', 'Retours'],
            'franchiseNetwork.field.royalty' => ['Royalty', 'Royalty', 'Royalty', 'Redevance'],
            'franchiseNetwork.field.royaltyAfterStartup' => ['Royalty dopo agevolazione', 'Royalty after relief', 'Royalty nach Vergünstigung', 'Redevance après allègement'],
            'franchiseNetwork.field.royaltyCharged' => ['Royalty addebitata', 'Royalty charged', 'Verrechnete Royalty', 'Redevance facturée'],
            'franchiseNetwork.field.royaltyGross' => ['Royalty da scaglioni', 'Royalty from brackets', 'Royalty aus Staffeln', 'Redevance par tranches'],
            'franchiseNetwork.field.royaltyTiers' => ['Scaglioni della royalty (%)', 'Royalty brackets (%)', 'Royalty-Staffeln (%)', 'Tranches de redevance (%)'],
            'franchiseNetwork.field.score' => ['Punteggio', 'Score', 'Punktzahl', 'Score'],
            'franchiseNetwork.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'franchiseNetwork.field.shortfall' => ['Mancato acquisto', 'Purchase shortfall', 'Einkaufsdefizit', 'Déficit d’achats'],
            'franchiseNetwork.field.signedOn' => ['Firmato il', 'Signed on', 'Unterzeichnet am', 'Signé le'],
            'franchiseNetwork.field.sizeCluster' => ['Cluster dimensionale', 'Size cluster', 'Grössen-Cluster', 'Cluster de taille'],
            'franchiseNetwork.field.sku' => ['Codice articolo', 'SKU', 'Artikelnummer', 'Référence article'],
            'franchiseNetwork.field.source' => ['Origine', 'Source', 'Quelle', 'Origine'],
            'franchiseNetwork.field.startDate' => ['Decorrenza', 'Start date', 'Vertragsbeginn', 'Début du contrat'],
            'franchiseNetwork.field.startup' => ['Agevolazione di avvio', 'Start-up relief', 'Anlaufvergünstigung', 'Allègement de démarrage'],
            'franchiseNetwork.field.startupAppliesToMarketing' => ['Vale anche per il fondo marketing', 'Also applies to the marketing fund', 'Gilt auch für den Marketingfonds', 'S’applique aussi au fonds marketing'],
            'franchiseNetwork.field.startupFactor' => ['Fattore di avvio', 'Start-up factor', 'Anlauffaktor', 'Facteur de démarrage'],
            'franchiseNetwork.field.startupMonths' => ['Mesi di agevolazione', 'Relief months', 'Monate der Vergünstigung', 'Mois d’allègement'],
            'franchiseNetwork.field.startupReductionPercent' => ['Riduzione dell’aliquota (%)', 'Rate reduction (%)', 'Satzreduktion (%)', 'Réduction du taux (%)'],
            'franchiseNetwork.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'franchiseNetwork.field.store' => ['Punto vendita', 'Store', 'Standort', 'Point de vente'],
            'franchiseNetwork.field.stores' => ['Punti vendita', 'Stores', 'Standorte', 'Points de vente'],
            'franchiseNetwork.field.storeType' => ['Tipo di punto vendita', 'Store type', 'Standortart', 'Type de point de vente'],
            'franchiseNetwork.field.targetMode' => ['Destinatari', 'Recipients', 'Empfänger', 'Destinataires'],
            'franchiseNetwork.field.template' => ['Checklist', 'Checklist', 'Checkliste', 'Liste de contrôle'],
            'franchiseNetwork.field.term' => ['Durata', 'Term', 'Laufzeit', 'Durée'],
            'franchiseNetwork.field.tickets' => ['Scontrini', 'Tickets', 'Bons', 'Tickets'],
            'franchiseNetwork.field.tierFrom' => ['Da (venduto mensile)', 'From (monthly sales)', 'Von (Monatsumsatz)', 'De (ventes mensuelles)'],
            'franchiseNetwork.field.tierRate' => ['Aliquota %', 'Rate %', 'Satz %', 'Taux %'],
            'franchiseNetwork.field.tierTo' => ['Fino a (vuoto = senza limite)', 'Up to (empty = no limit)', 'Bis (leer = unbegrenzt)', 'Jusqu’à (vide = sans limite)'],
            'franchiseNetwork.field.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'franchiseNetwork.field.totalNet' => ['Totale netto', 'Net total', 'Nettototal', 'Total net'],
            'franchiseNetwork.field.validFrom' => ['Valido dal', 'Valid from', 'Gültig ab', 'Valable dès le'],
            'franchiseNetwork.field.validTo' => ['Valido fino al', 'Valid until', 'Gültig bis', 'Valable jusqu’au'],
            'franchiseNetwork.field.visitors' => ['Visitatori', 'Visitors', 'Besucher', 'Visiteurs'],
            'franchiseNetwork.field.yes' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'franchiseNetwork.field.zoneLabel' => ['Zona di esclusiva', 'Exclusivity zone', 'Exklusivzone', 'Zone d’exclusivité'],
            'franchiseNetwork.field.zonePostalCodes' => ['CAP della zona (separati da virgola)', 'Zone postal codes (comma separated)', 'Postleitzahlen der Zone (durch Komma getrennt)', 'Codes postaux de la zone (séparés par des virgules)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'franchiseNetwork.action.acknowledge' => ['Conferma ricezione', 'Confirm receipt', 'Empfang bestätigen', 'Confirmer la réception'],
            'franchiseNetwork.action.activate' => ['Attiva', 'Activate', 'Aktivieren', 'Activer'],
            'franchiseNetwork.action.addPhoto' => ['Aggiungi foto', 'Add photo', 'Foto hinzufügen', 'Ajouter une photo'],
            'franchiseNetwork.action.addRow' => ['Aggiungi riga', 'Add row', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'franchiseNetwork.action.addTier' => ['Aggiungi scaglione', 'Add bracket', 'Staffel hinzufügen', 'Ajouter une tranche'],
            'franchiseNetwork.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'franchiseNetwork.action.calculateRun' => ['Calcola il mese', 'Calculate the month', 'Monat berechnen', 'Calculer le mois'],
            'franchiseNetwork.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'franchiseNetwork.action.completeAction' => ['Chiudi azione', 'Close action', 'Massnahme abschliessen', 'Clôturer l’action'],
            'franchiseNetwork.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'franchiseNetwork.action.continueAudit' => ['Prosegui', 'Continue', 'Fortsetzen', 'Poursuivre'],
            'franchiseNetwork.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'franchiseNetwork.action.dispatch' => ['Invia ai punti vendita', 'Send to the stores', 'An die Standorte senden', 'Envoyer aux points de vente'],
            'franchiseNetwork.action.duplicate' => ['Duplica', 'Duplicate', 'Duplizieren', 'Dupliquer'],
            'franchiseNetwork.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'franchiseNetwork.action.invoiceRun' => ['Emetti le fatture', 'Issue the invoices', 'Rechnungen ausstellen', 'Émettre les factures'],
            'franchiseNetwork.action.issueLinkCode' => ['Genera codice di collegamento', 'Generate link code', 'Verknüpfungscode erzeugen', 'Générer le code de liaison'],
            'franchiseNetwork.action.linkStore' => ['Collega il punto vendita', 'Link the store', 'Standort verknüpfen', 'Lier le point de vente'],
            'franchiseNetwork.action.monitor' => ['Monitor consegne', 'Delivery monitor', 'Lieferübersicht', 'Suivi des livraisons'],
            'franchiseNetwork.action.newAgreement' => ['Nuovo contratto', 'New agreement', 'Neuer Vertrag', 'Nouveau contrat'],
            'franchiseNetwork.action.newFormula' => ['Nuova formula', 'New formula', 'Neue Formel', 'Nouvelle formule'],
            'franchiseNetwork.action.newPush' => ['Nuova distribuzione', 'New distribution', 'Neue Verteilung', 'Nouvelle diffusion'],
            'franchiseNetwork.action.newStore' => ['Nuovo punto vendita', 'New store', 'Neuer Standort', 'Nouveau point de vente'],
            'franchiseNetwork.action.openAudit' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'franchiseNetwork.action.pushNow' => ['Invia ora a tutti', 'Push now', 'Jetzt senden', 'Diffuser maintenant'],
            'franchiseNetwork.action.recordSellOut' => ['Registra sell-out', 'Record sell-out', 'Sell-out erfassen', 'Saisir le sell-out'],
            'franchiseNetwork.action.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'franchiseNetwork.action.renew' => ['Rinnova', 'Renew', 'Verlängern', 'Renouveler'],
            'franchiseNetwork.action.retryFailed' => ['Riprova i punti vendita in errore', 'Retry the failed stores', 'Fehlgeschlagene Standorte erneut senden', 'Relancer les points de vente en erreur'],
            'franchiseNetwork.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'franchiseNetwork.action.saveDraft' => ['Salva bozza', 'Save draft', 'Entwurf speichern', 'Enregistrer le brouillon'],
            'franchiseNetwork.action.schedule' => ['Piano risconto', 'Deferral schedule', 'Abgrenzungsplan', 'Plan de report'],
            'franchiseNetwork.action.simulate' => ['Simula', 'Simulate', 'Simulieren', 'Simuler'],
            'franchiseNetwork.action.startAudit' => ['Avvia audit', 'Start audit', 'Audit starten', 'Démarrer l’audit'],
            'franchiseNetwork.action.submitAudit' => ['Invia audit', 'Submit audit', 'Audit übermitteln', 'Soumettre l’audit'],
            'franchiseNetwork.action.terminate' => ['Risolvi', 'Terminate', 'Beenden', 'Résilier'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function confirmations(): array
    {
        return [
            'franchiseNetwork.confirm.deleteAgreement' => ['Eliminare il contratto in bozza?', 'Delete the draft agreement?', 'Vertragsentwurf löschen?', 'Supprimer le contrat en brouillon ?'],
            'franchiseNetwork.confirm.deleteFormula' => ['Eliminare la formula?', 'Delete the formula?', 'Formel löschen?', 'Supprimer la formule ?'],
            'franchiseNetwork.confirm.deleteStore' => ['Eliminare il punto vendita?', 'Delete the store?', 'Standort löschen?', 'Supprimer le point de vente ?'],
            'franchiseNetwork.confirm.invoiceRun' => ['Emettere le fatture di royalty e fondo marketing del mese? Dopo l’emissione il mese non si ricalcola.', 'Issue the royalty and marketing fund invoices of the month? Once issued the month cannot be recalculated.', 'Royalty- und Marketingfonds-Rechnungen des Monats ausstellen? Danach kann der Monat nicht neu berechnet werden.', 'Émettre les factures de redevance et de fonds marketing du mois ? Une fois émises, le mois ne se recalcule plus.'],
            'franchiseNetwork.confirm.submitAudit' => ['Inviare l’audit? Dopo l’invio non si modifica più.', 'Submit the audit? It cannot be changed afterwards.', 'Audit übermitteln? Danach kann es nicht mehr geändert werden.', 'Soumettre l’audit ? Il ne pourra plus être modifié.'],
            'franchiseNetwork.confirm.terminateAgreement' => ['Risolvere il contratto di affiliazione?', 'Terminate the affiliation agreement?', 'Franchisevertrag beenden?', 'Résilier le contrat de franchise ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'franchiseNetwork.section.agreement' => ['Contratto di affiliazione', 'Affiliation agreement', 'Franchisevertrag', 'Contrat de franchise'],
            'franchiseNetwork.section.alerts' => ['Avvisi sui contratti', 'Agreement alerts', 'Vertragshinweise', 'Alertes sur les contrats'],
            'franchiseNetwork.section.auditHistory' => ['Storico degli audit', 'Audit history', 'Audit-Verlauf', 'Historique des audits'],
            'franchiseNetwork.section.content' => ['Contenuto', 'Content', 'Inhalt', 'Contenu'],
            'franchiseNetwork.section.deliveries' => ['Consegne ai punti vendita', 'Deliveries to the stores', 'Lieferungen an die Standorte', 'Livraisons aux points de vente'],
            'franchiseNetwork.section.entryFeeSchedule' => ['Risconto del diritto d’ingresso', 'Entry fee deferral', 'Abgrenzung der Eintrittsgebühr', 'Report du droit d’entrée'],
            'franchiseNetwork.section.findings' => ['Verifiche di conformità', 'Compliance checks', 'Konformitätsprüfungen', 'Contrôles de conformité'],
            'franchiseNetwork.section.formula' => ['Formula di royalty e fondo marketing', 'Royalty and marketing fund formula', 'Royalty- und Marketingfonds-Formel', 'Formule de redevance et de fonds marketing'],
            'franchiseNetwork.section.items' => ['Articoli', 'Items', 'Artikel', 'Articles'],
            'franchiseNetwork.section.linkStore' => ['Collega il tuo punto vendita', 'Link your store', 'Verknüpfen Sie Ihren Standort', 'Liez votre point de vente'],
            'franchiseNetwork.section.loyaltySettlement' => ['Compensazione fedeltà tra punti vendita', 'Cross-store loyalty settlement', 'Treueausgleich zwischen Standorten', 'Compensation fidélité entre points de vente'],
            'franchiseNetwork.section.newPush' => ['Nuova distribuzione centrale', 'New central distribution', 'Neue zentrale Verteilung', 'Nouvelle diffusion centrale'],
            'franchiseNetwork.section.obligations' => ['Obblighi di acquisto', 'Purchase obligations', 'Einkaufsverpflichtungen', 'Obligations d’achat'],
            'franchiseNetwork.section.openActions' => ['Azioni correttive', 'Corrective actions', 'Korrekturmassnahmen', 'Actions correctives'],
            'franchiseNetwork.section.prices' => ['Prezzi', 'Prices', 'Preise', 'Prix'],
            'franchiseNetwork.section.recentPushes' => ['Ultime distribuzioni', 'Latest distributions', 'Letzte Verteilungen', 'Dernières diffusions'],
            'franchiseNetwork.section.recordSellOut' => ['Sell-out del mese', 'Sell-out of the month', 'Sell-out des Monats', 'Sell-out du mois'],
            'franchiseNetwork.section.sellOut' => ['Sell-out dei punti vendita', 'Store sell-out', 'Sell-out der Standorte', 'Sell-out des points de vente'],
            'franchiseNetwork.section.simulator' => ['Simulatore di formula', 'Formula simulator', 'Formel-Simulator', 'Simulateur de formule'],
            'franchiseNetwork.section.statements' => ['Estratti conto royalty e fondo marketing', 'Royalty and marketing fund statements', 'Royalty- und Marketingfonds-Abrechnungen', 'Relevés de redevance et de fonds marketing'],
            'franchiseNetwork.section.store' => ['Punto vendita', 'Store', 'Standort', 'Point de vente'],
            'franchiseNetwork.tab.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'franchiseNetwork.tab.syncs' => ['Distribuzioni ricevute', 'Pushes received', 'Erhaltene Verteilungen', 'Diffusions reçues'],
            'franchiseNetwork.tab.statements' => ['Estratti conto', 'Statements', 'Abrechnungen', 'Relevés'],
            'franchiseNetwork.tab.benchmark' => ['Benchmark', 'Benchmark', 'Benchmark', 'Benchmark'],
            'franchiseNetwork.benchmark.own' => ['Il tuo punto vendita', 'Your store', 'Ihr Standort', 'Votre point de vente'],
            'franchiseNetwork.benchmark.clusterMedian' => ['Mediana del cluster', 'Cluster median', 'Median des Clusters', 'Médiane du cluster'],
            'franchiseNetwork.benchmark.networkAverage' => ['Media della rete', 'Network average', 'Netzwerk-Durchschnitt', 'Moyenne du réseau'],
            'franchiseNetwork.benchmark.topQuartile' => ['Soglia dei migliori 25%', 'Top 25% threshold', 'Schwelle der besten 25 %', 'Seuil des 25 % meilleurs'],
            'franchiseNetwork.benchmark.sample' => ['Campione: {count} punti vendita', 'Sample: {count} stores', 'Stichprobe: {count} Standorte', 'Échantillon : {count} points de vente'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function kpis(): array
    {
        return [
            'franchiseNetwork.kpi.stores' => ['Punti vendita attivi / totali', 'Active / total stores', 'Aktive / alle Standorte', 'Points de vente actifs / total'],
            'franchiseNetwork.kpi.reporting' => ['Con sell-out del mese', 'With sell-out for the month', 'Mit Sell-out im Monat', 'Avec sell-out du mois'],
            'franchiseNetwork.kpi.notLinked' => ['{count} non collegati', '{count} not linked', '{count} nicht verknüpft', '{count} non liés'],
            'franchiseNetwork.kpi.netSellOut' => ['Sell-out netto della rete', 'Network net sell-out', 'Netto-Sell-out des Netzwerks', 'Sell-out net du réseau'],
            'franchiseNetwork.kpi.momPercent' => ['Mese precedente', 'Previous month', 'Vormonat', 'Mois précédent'],
            'franchiseNetwork.kpi.yoyPercent' => ['Stesso mese anno scorso', 'Same month last year', 'Gleicher Monat Vorjahr', 'Même mois l’an dernier'],
            'franchiseNetwork.kpi.agreements' => ['Contratti attivi', 'Active agreements', 'Aktive Verträge', 'Contrats actifs'],
            'franchiseNetwork.kpi.attention' => ['{count} da gestire', '{count} need attention', '{count} zu bearbeiten', '{count} à traiter'],
            'franchiseNetwork.kpi.billing' => ['Royalty e fondo marketing {period}', 'Royalty and marketing fund {period}', 'Royalty und Marketingfonds {period}', 'Redevance et fonds marketing {period}'],
            'franchiseNetwork.kpi.pushHealth' => ['Consegne in errore', 'Failed deliveries', 'Fehlgeschlagene Lieferungen', 'Livraisons en erreur'],
            'franchiseNetwork.kpi.awaitingAck' => ['In attesa di conferma', 'Awaiting acknowledgement', 'Warten auf Bestätigung', 'En attente de confirmation'],
            'franchiseNetwork.kpi.auditScore' => ['Punteggio medio audit (90 giorni)', 'Average audit score (90 days)', 'Durchschnittliche Audit-Punktzahl (90 Tage)', 'Score moyen des audits (90 jours)'],
            'franchiseNetwork.kpi.failedAudits' => ['Audit non superati', 'Failed audits', 'Nicht bestandene Audits', 'Audits non réussis'],
            'franchiseNetwork.kpi.overdueActions' => ['Azioni in ritardo', 'Overdue actions', 'Überfällige Massnahmen', 'Actions en retard'],
            'franchiseNetwork.kpi.purchasesBelowMinimum' => ['Acquisti sotto il minimo', 'Purchases below the minimum', 'Einkäufe unter dem Minimum', 'Achats sous le minimum'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function hints(): array
    {
        return [
            'franchiseNetwork.hint.clusterHidden' => ['Nel tuo cluster dimensionale ci sono pochi punti vendita: per tutelare l’anonimato si mostra solo il confronto con la rete.', 'Your size cluster has too few stores: to protect anonymity only the comparison with the network is shown.', 'In Ihrem Grössen-Cluster gibt es zu wenige Standorte: zum Schutz der Anonymität wird nur der Vergleich mit dem Netzwerk gezeigt.', 'Votre cluster de taille compte trop peu de points de vente : pour préserver l’anonymat, seule la comparaison avec le réseau est affichée.'],
            'franchiseNetwork.hint.linkCode' => ['Codice di collegamento generato. Comunicalo all’affiliato: lo inserirà una sola volta nel suo portale.', 'Link code generated. Give it to the franchisee: they enter it once in their portal.', 'Verknüpfungscode erzeugt. Geben Sie ihn dem Franchisenehmer: er wird nur einmal in dessen Portal eingegeben.', 'Code de liaison généré. Transmettez-le au franchisé : il ne le saisit qu’une fois dans son portail.'],
            'franchiseNetwork.hint.linkStore' => ['Inserisci il codice che ti ha dato il franchisor per collegare questa azienda al tuo punto vendita. La contabilità resta tua: il franchisor vede solo i dati commerciali del contratto.', 'Enter the code the franchisor gave you to link this company to your store. Your books stay yours: the franchisor only sees the commercial data of the agreement.', 'Geben Sie den Code des Franchisegebers ein, um dieses Unternehmen mit Ihrem Standort zu verknüpfen. Ihre Buchhaltung bleibt Ihre: der Franchisegeber sieht nur die kommerziellen Vertragsdaten.', 'Saisissez le code remis par le franchiseur pour lier cette entreprise à votre point de vente. Votre comptabilité reste la vôtre : le franchiseur ne voit que les données commerciales du contrat.'],
            'franchiseNetwork.hint.notFranchisor' => ['Questa azienda non gestisce una rete in franchising: usa il portale dell’affiliato o gli audit.', 'This company does not run a franchise network: use the franchisee portal or the audits.', 'Dieses Unternehmen betreibt kein Franchisenetzwerk: nutzen Sie das Franchisenehmer-Portal oder die Audits.', 'Cette entreprise ne gère pas de réseau de franchise : utilisez le portail franchisé ou les audits.'],
            'franchiseNetwork.hint.swissPrices' => ['Per i punti vendita svizzeri un prezzo massimo viene inviato come prezzo consigliato non vincolante (CO / LCart).', 'For Swiss stores a maximum price is sent as a recommended, non-binding price (CO / LCart).', 'Für Schweizer Standorte wird ein Höchstpreis als unverbindliche Preisempfehlung gesendet (OR / KG).', 'Pour les points de vente suisses, un prix maximum est envoyé comme prix recommandé non contraignant (CO / LCart).'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function empties(): array
    {
        return [
            'franchiseNetwork.empty.actions' => ['Nessuna azione correttiva aperta.', 'No open corrective action.', 'Keine offene Korrekturmassnahme.', 'Aucune action corrective ouverte.'],
            'franchiseNetwork.empty.agreements' => ['Nessun contratto di affiliazione.', 'No affiliation agreement.', 'Kein Franchisevertrag.', 'Aucun contrat de franchise.'],
            'franchiseNetwork.empty.alerts' => ['Nessun contratto richiede attenzione.', 'No agreement needs attention.', 'Kein Vertrag erfordert Aufmerksamkeit.', 'Aucun contrat ne demande d’attention.'],
            'franchiseNetwork.empty.audits' => ['Nessun audit.', 'No audit.', 'Kein Audit.', 'Aucun audit.'],
            'franchiseNetwork.empty.benchmark' => ['Benchmark non ancora disponibile: servono il sell-out del mese e il calcolo della rete.', 'Benchmark not available yet: it needs the sell-out of the month and the network computation.', 'Benchmark noch nicht verfügbar: es braucht das Sell-out des Monats und die Netzwerkberechnung.', 'Benchmark pas encore disponible : il faut le sell-out du mois et le calcul du réseau.'],
            'franchiseNetwork.empty.formulas' => ['Nessuna formula.', 'No formula.', 'Keine Formel.', 'Aucune formule.'],
            'franchiseNetwork.empty.loyalty' => ['Nessuna compensazione fedeltà da regolare.', 'No loyalty settlement to clear.', 'Kein Treueausgleich zu verrechnen.', 'Aucune compensation fidélité à régler.'],
            'franchiseNetwork.empty.purchases' => ['Nessun acquisto registrato.', 'No purchases recorded.', 'Keine Einkäufe erfasst.', 'Aucun achat enregistré.'],
            'franchiseNetwork.empty.pushes' => ['Nessuna distribuzione.', 'No distribution.', 'Keine Verteilung.', 'Aucune diffusion.'],
            'franchiseNetwork.empty.runForPeriod' => ['Nessun calcolo per il mese: calcolalo per rivedere gli estratti conto.', 'No run for the month: calculate it to review the statements.', 'Keine Berechnung für den Monat: berechnen Sie ihn, um die Abrechnungen zu prüfen.', 'Aucun calcul pour le mois : calculez-le pour revoir les relevés.'],
            'franchiseNetwork.empty.runs' => ['Nessun calcolo eseguito.', 'No run yet.', 'Noch keine Berechnung.', 'Aucun calcul effectué.'],
            'franchiseNetwork.empty.schedule' => ['Nessun piano di risconto.', 'No deferral schedule.', 'Kein Abgrenzungsplan.', 'Aucun plan de report.'],
            'franchiseNetwork.empty.sellOut' => ['Nessun sell-out per il mese.', 'No sell-out for the month.', 'Kein Sell-out für den Monat.', 'Aucun sell-out pour le mois.'],
            'franchiseNetwork.empty.statements' => ['Nessun estratto conto.', 'No statement.', 'Keine Abrechnung.', 'Aucun relevé.'],
            'franchiseNetwork.empty.stores' => ['Nessun punto vendita.', 'No store.', 'Kein Standort.', 'Aucun point de vente.'],
            'franchiseNetwork.empty.syncs' => ['Nessuna distribuzione ricevuta.', 'No push received.', 'Keine Verteilung erhalten.', 'Aucune diffusion reçue.'],
            'franchiseNetwork.empty.template' => ['Checklist non disponibile.', 'Checklist not available.', 'Checkliste nicht verfügbar.', 'Liste de contrôle indisponible.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function notifications(): array
    {
        return [
            'franchiseNetwork.notification.acknowledged' => ['Ricezione confermata.', 'Receipt confirmed.', 'Empfang bestätigt.', 'Réception confirmée.'],
            'franchiseNetwork.notification.auditSubmitted' => ['Audit inviato.', 'Audit submitted.', 'Audit übermittelt.', 'Audit soumis.'],
            'franchiseNetwork.notification.linked' => ['Azienda collegata al punto vendita.', 'Company linked to the store.', 'Unternehmen mit dem Standort verknüpft.', 'Entreprise liée au point de vente.'],
            'franchiseNetwork.notification.photoAdded' => ['Foto allegata.', 'Photo attached.', 'Foto angehängt.', 'Photo jointe.'],
            'franchiseNetwork.notification.pushCreated' => ['Distribuzione creata.', 'Distribution created.', 'Verteilung erstellt.', 'Diffusion créée.'],
            'franchiseNetwork.notification.saved' => ['Salvato.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
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
