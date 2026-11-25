<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the GDO trade terms module (Mod 231): framework contracts with retail chains and buying groups, tiered
 * year-end rebates (PFA / Jahresrückvergütung / Umsatzbonus), sell-in and sell-out promotions, monthly accruals, settlements
 * (credit note or promotional invoice reconciliation), the net-net margin and the codes the API reports (gdoTradeTerms.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, PFA, TD04, GDO, Ce.Di., MWSTG) stay untranslated.
 */
final class GdoTradeTermsFrontendTranslations
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
            self::fields(),
            self::actions(),
            self::confirmations(),
            self::sections(),
            self::hints(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.GDO_TRADE_TERMS' => ['Condizioni GDO', 'GDO trade terms', 'GDO-Konditionen', 'Conditions GDO'],
            'NAV.GDO_TRADE_TERMS_DASHBOARD' => ['Cruscotto', 'Dashboard', 'Übersicht', 'Tableau de bord'],
            'NAV.GDO_TRADE_TERMS_CONTRACTS' => ['Contratti quadro', 'Framework contracts', 'Rahmenverträge', 'Contrats-cadres'],
            'NAV.GDO_TRADE_TERMS_CONTRACT' => ['Contratto quadro', 'Framework contract', 'Rahmenvertrag', 'Contrat-cadre'],
            'NAV.GDO_TRADE_TERMS_PROMOTIONS' => ['Promozioni e contributi', 'Promotions and contributions', 'Aktionen und Beiträge', 'Promotions et contributions'],
            'NAV.GDO_TRADE_TERMS_SIMULATOR' => ['Simulatore scaglioni', 'Bracket simulator', 'Staffelsimulator', 'Simulateur de paliers'],
            'NAV.GDO_TRADE_TERMS_MARGIN' => ['Margine net-net', 'Net-net margin', 'Netto-Netto-Marge', 'Marge net-net'],
            'NAV.GDO_TRADE_TERMS_ACCRUALS' => ['Ratei mensili', 'Monthly accruals', 'Monatliche Abgrenzungen', 'Provisions mensuelles'],
            'NAV.GDO_TRADE_TERMS_SETTLEMENTS' => ['Liquidazioni', 'Settlements', 'Abrechnungen', 'Règlements'],
            'NAV.GDO_TRADE_TERMS_SETTLEMENT' => ['Liquidazione', 'Settlement', 'Abrechnung', 'Règlement'],
            'NAV.GDO_TRADE_TERMS_DELIVERY_POINTS' => ['Punti di consegna', 'Delivery points', 'Lieferstellen', 'Points de livraison'],
            'PAGE_TITLE.GDO_TRADE_TERMS_DASHBOARD' => ['Condizioni commerciali GDO', 'GDO trade terms', 'GDO-Handelskonditionen', 'Conditions commerciales GDO'],
            'PAGE_TITLE.GDO_TRADE_TERMS_CONTRACTS' => ['Contratti quadro con la GDO', 'GDO framework contracts', 'GDO-Rahmenverträge', 'Contrats-cadres GDO'],
            'PAGE_TITLE.GDO_TRADE_TERMS_CONTRACT' => ['Dettaglio del contratto quadro', 'Framework contract detail', 'Details des Rahmenvertrags', 'Détail du contrat-cadre'],
            'PAGE_TITLE.GDO_TRADE_TERMS_PROMOTIONS' => ['Promozioni sell-in, sell-out e contributi', 'Sell-in, sell-out promotions and contributions', 'Sell-in-, Sell-out-Aktionen und Beiträge', 'Promotions sell-in, sell-out et contributions'],
            'PAGE_TITLE.GDO_TRADE_TERMS_SIMULATOR' => ['Simulatore degli scaglioni del premio di fine anno', 'Year-end rebate bracket simulator', 'Simulator der Jahresrückvergütungs-Staffel', 'Simulateur des paliers de la remise de fin d’année'],
            'PAGE_TITLE.GDO_TRADE_TERMS_MARGIN' => ['Margine lordo, netto e net-net', 'Gross, net and net-net margin', 'Brutto-, Netto- und Netto-Netto-Marge', 'Marge brute, nette et net-net'],
            'PAGE_TITLE.GDO_TRADE_TERMS_ACCRUALS' => ['Stanziamento dei ratei mensili', 'Monthly accrual of rebates', 'Monatliche Abgrenzung der Rückvergütungen', 'Provision mensuelle des remises'],
            'PAGE_TITLE.GDO_TRADE_TERMS_SETTLEMENTS' => ['Liquidazione di premi e contributi', 'Settlement of rebates and contributions', 'Abrechnung von Boni und Beiträgen', 'Règlement des remises et contributions'],
            'PAGE_TITLE.GDO_TRADE_TERMS_SETTLEMENT' => ['Dettaglio della liquidazione', 'Settlement detail', 'Details der Abrechnung', 'Détail du règlement'],
            'PAGE_TITLE.GDO_TRADE_TERMS_DELIVERY_POINTS' => ['Punti vendita e Ce.Di.', 'Stores and Ce.Di.', 'Filialen und Ce.Di.', 'Magasins et Ce.Di.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'gdoTradeTerms.contractStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'gdoTradeTerms.contractStatus.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'gdoTradeTerms.contractStatus.suspended' => ['Sospeso', 'Suspended', 'Ausgesetzt', 'Suspendu'],
            'gdoTradeTerms.contractStatus.closed' => ['Chiuso', 'Closed', 'Abgeschlossen', 'Clos'],
            'gdoTradeTerms.contractStatus.cancelled' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],
            'gdoTradeTerms.jurisdiction.IT' => ['Italia', 'Italy', 'Italien', 'Italie'],
            'gdoTradeTerms.jurisdiction.CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse'],
            'gdoTradeTerms.tierMode.retroactive' => ['Retroattivo', 'Retroactive', 'Rückwirkend', 'Rétroactif'],
            'gdoTradeTerms.tierMode.progressive' => ['Progressivo (marginale)', 'Progressive (marginal)', 'Progressiv (marginal)', 'Progressif (marginal)'],
            'gdoTradeTerms.basis.turnover' => ['Fatturato (valore)', 'Turnover (value)', 'Umsatz (Wert)', 'Chiffre d’affaires (valeur)'],
            'gdoTradeTerms.basis.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'gdoTradeTerms.volumeMode.total' => ['Volume totale', 'Total volume', 'Gesamtvolumen', 'Volume total'],
            'gdoTradeTerms.volumeMode.incremental' => ['Crescita sulla base di confronto', 'Growth over the baseline', 'Wachstum gegenüber der Vergleichsbasis', 'Croissance par rapport à la base'],
            'gdoTradeTerms.baselineSource.manual' => ['Valore concordato', 'Agreed value', 'Vereinbarter Wert', 'Valeur convenue'],
            'gdoTradeTerms.baselineSource.prior_period' => ['Periodo precedente', 'Previous period', 'Vorperiode', 'Période précédente'],
            'gdoTradeTerms.promotionType.sell_in_flyer' => ['Volantino (sell-in)', 'Flyer (sell-in)', 'Flyer-Aktion (Sell-in)', 'Prospectus (sell-in)'],
            'gdoTradeTerms.promotionType.sell_out' => ['Sell-out', 'Sell-out', 'Sell-out', 'Sell-out'],
            'gdoTradeTerms.promotionType.new_store_opening' => ['Nuova apertura', 'New store opening', 'Neueröffnung', 'Nouvelle ouverture'],
            'gdoTradeTerms.promotionType.listing_fee' => ['Referenziamento', 'Listing fee', 'Listungsgebühr', 'Référencement'],
            'gdoTradeTerms.promotionType.display' => ['Esposizione preferenziale (gondola / testata)', 'Preferential display (gondola / end cap)', 'Bevorzugte Platzierung (Gondel / Kopfplatz)', 'Mise en avant (gondole / tête de gondole)'],
            'gdoTradeTerms.promotionType.cedi_logistics' => ['Contributo logistico Ce.Di.', 'Ce.Di. logistics contribution', 'Logistikbeitrag Ce.Di.', 'Contribution logistique Ce.Di.'],
            'gdoTradeTerms.promotionStatus.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'gdoTradeTerms.promotionStatus.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'gdoTradeTerms.promotionStatus.ended' => ['Terminata', 'Ended', 'Beendet', 'Terminée'],
            'gdoTradeTerms.promotionStatus.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'gdoTradeTerms.dateBasis.order' => ['Data ordine', 'Order date', 'Bestelldatum', 'Date de commande'],
            'gdoTradeTerms.dateBasis.delivery' => ['Data consegna', 'Delivery date', 'Lieferdatum', 'Date de livraison'],
            'gdoTradeTerms.dateBasis.invoice' => ['Data fattura', 'Invoice date', 'Rechnungsdatum', 'Date de facture'],
            'gdoTradeTerms.contributionMode.percent_of_sales' => ['% sul venduto (sell-in)', '% of sell-in sales', '% des Sell-in-Umsatzes', '% des ventes sell-in'],
            'gdoTradeTerms.contributionMode.per_unit' => ['Importo per unità', 'Amount per unit', 'Betrag pro Einheit', 'Montant par unité'],
            'gdoTradeTerms.contributionMode.fixed_amount' => ['Importo fisso', 'Fixed amount', 'Fixbetrag', 'Montant fixe'],
            'gdoTradeTerms.contributionMode.percent_of_sell_out' => ['Sul sell-out comunicato', 'On the reported sell-out', 'Auf dem gemeldeten Sell-out', 'Sur le sell-out déclaré'],
            'gdoTradeTerms.fixedAccrual.linear' => ['Lineare sul periodo', 'Linear over the period', 'Linear über den Zeitraum', 'Linéaire sur la période'],
            'gdoTradeTerms.fixedAccrual.on_start' => ['Tutto all’inizio', 'In full at the start', 'Vollständig zu Beginn', 'En totalité au début'],
            'gdoTradeTerms.runStatus.posted' => ['Contabilizzata', 'Posted', 'Gebucht', 'Comptabilisée'],
            'gdoTradeTerms.runStatus.reversed' => ['Stornata', 'Reversed', 'Storniert', 'Extournée'],
            'gdoTradeTerms.lineStatus.accrued' => ['Accantonata', 'Accrued', 'Abgegrenzt', 'Provisionnée'],
            'gdoTradeTerms.lineStatus.reversed' => ['Stornata', 'Reversed', 'Storniert', 'Extournée'],
            'gdoTradeTerms.lineStatus.settled' => ['Liquidata', 'Settled', 'Abgerechnet', 'Réglée'],
            'gdoTradeTerms.sourceType.scale' => ['Premio a scaglioni', 'Tiered rebate', 'Staffelbonus', 'Remise à paliers'],
            'gdoTradeTerms.sourceType.promotion' => ['Promozione', 'Promotion', 'Aktion', 'Promotion'],
            'gdoTradeTerms.settlementKind.year_end' => ['Fine anno', 'Year-end', 'Jahresende', 'Fin d’année'],
            'gdoTradeTerms.settlementKind.quarterly' => ['Trimestrale', 'Quarterly', 'Quartalsweise', 'Trimestriel'],
            'gdoTradeTerms.settlementKind.campaign' => ['Per campagna', 'Per campaign', 'Pro Kampagne', 'Par campagne'],
            'gdoTradeTerms.settlementStatus.proposed' => ['Proposta', 'Proposed', 'Vorgeschlagen', 'Proposé'],
            'gdoTradeTerms.settlementStatus.disputed' => ['Contestata', 'Disputed', 'Strittig', 'Contesté'],
            'gdoTradeTerms.settlementStatus.settled' => ['Liquidata', 'Settled', 'Abgerechnet', 'Réglé'],
            'gdoTradeTerms.settlementStatus.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulé'],
            'gdoTradeTerms.settlementLineStatus.open' => ['Aperta', 'Open', 'Offen', 'Ouverte'],
            'gdoTradeTerms.settlementLineStatus.settled' => ['Liquidata', 'Settled', 'Abgerechnet', 'Réglée'],
            'gdoTradeTerms.settlementLineStatus.disputed' => ['Contestata', 'Disputed', 'Strittig', 'Contestée'],
            'gdoTradeTerms.settlementLineStatus.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'gdoTradeTerms.treatment.credit_note' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Avoir'],
            'gdoTradeTerms.treatment.promo_invoice' => ['Fattura promozionale della GDO', 'Promotional invoice of the retailer', 'Werbekostenrechnung des Händlers', 'Facture promotionnelle de l’enseigne'],
            'gdoTradeTerms.taxTreatment.it_art26_td04' => ['Nota di credito TD04: variazione in diminuzione della base imponibile (art. 26 DPR 633/72)', 'Credit note TD04: reduction of the taxable amount (art. 26 DPR 633/72)', 'Gutschrift TD04: Minderung der Bemessungsgrundlage (Art. 26 DPR 633/72)', 'Avoir TD04 : réduction de la base imposable (art. 26 DPR 633/72)'],
            'gdoTradeTerms.taxTreatment.ch_art41_consideration_reduction' => ['Nota di credito: riduzione della controprestazione (art. 41 LIVA / MWSTG)', 'Credit note: reduction of the consideration (art. 41 MWSTG)', 'Gutschrift: Entgeltsminderung (Art. 41 MWSTG)', 'Avoir : diminution de la contre-prestation (art. 41 LTVA)'],
            'gdoTradeTerms.taxTreatment.standard_vat' => ['Servizio promozionale con IVA ordinaria', 'Promotional service with standard VAT', 'Werbeleistung mit ordentlicher MWST', 'Prestation promotionnelle avec TVA ordinaire'],
            'gdoTradeTerms.deliveryPointType.store' => ['Punto vendita', 'Store', 'Filiale', 'Magasin'],
            'gdoTradeTerms.deliveryPointType.cedi' => ['Ce.Di. (centro di distribuzione)', 'Ce.Di. (distribution centre)', 'Ce.Di. (Verteilzentrum)', 'Ce.Di. (centre de distribution)'],
            'gdoTradeTerms.deliveryPointType.headquarters' => ['Sede centrale', 'Head office', 'Hauptsitz', 'Siège'],
            'gdoTradeTerms.disputeReason.claim_exceeds_earned' => ['La fattura dell’insegna supera l’importo maturato oltre la tolleranza', 'The retailer’s invoice exceeds the earned amount beyond the tolerance', 'Die Rechnung des Händlers übersteigt den verdienten Betrag über die Toleranz hinaus', 'La facture de l’enseigne dépasse le montant acquis au-delà de la tolérance'],
            'gdoTradeTerms.disputeReason.claim_below_earned' => ['La fattura dell’insegna è inferiore all’importo maturato oltre la tolleranza', 'The retailer’s invoice is below the earned amount beyond the tolerance', 'Die Rechnung des Händlers liegt unter dem verdienten Betrag über die Toleranz hinaus', 'La facture de l’enseigne est inférieure au montant acquis au-delà de la tolérance'],
            'gdoTradeTerms.groupBy.product' => ['Per prodotto', 'By product', 'Nach Produkt', 'Par produit'],
            'gdoTradeTerms.groupBy.contract' => ['Per contratto', 'By contract', 'Nach Vertrag', 'Par contrat'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'gdoTradeTerms.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es erneut.', 'L’opération a échoué. Réessayez.'],
            'gdoTradeTerms.error.contact_not_found' => ['Cliente non trovato.', 'The customer was not found.', 'Der Kunde wurde nicht gefunden.', 'Client introuvable.'],
            'gdoTradeTerms.error.period_invalid' => ['La fine del periodo precede l’inizio.', 'The end of the period is before its start.', 'Das Ende des Zeitraums liegt vor dem Beginn.', 'La fin de la période précède son début.'],
            'gdoTradeTerms.error.period_in_future' => ['Non si può accantonare un mese non ancora iniziato.', 'A month that has not started yet cannot be accrued.', 'Ein noch nicht begonnener Monat kann nicht abgegrenzt werden.', 'Un mois qui n’a pas commencé ne peut pas être provisionné.'],
            'gdoTradeTerms.error.invalid_action' => ['Azione non valida.', 'The action is not valid.', 'Die Aktion ist ungültig.', 'Action non valide.'],
            'gdoTradeTerms.error.invalid_transition' => ['Lo stato non può passare da :from a :to.', 'The status cannot move from :from to :to.', 'Der Status kann nicht von :from auf :to wechseln.', 'Le statut ne peut pas passer de :from à :to.'],
            'gdoTradeTerms.error.nothing_to_activate' => ['Aggiungi almeno una scala premi con scaglioni o una promozione prima di attivare il contratto.', 'Add at least one rebate scale with brackets, or one promotion, before activating the contract.', 'Fügen Sie vor der Aktivierung mindestens eine Bonusstaffel mit Stufen oder eine Aktion hinzu.', 'Ajoutez au moins un barème avec paliers ou une promotion avant d’activer le contrat.'],
            'gdoTradeTerms.error.contract_has_accruals' => ['Il contratto ha già degli accantonamenti.', 'The contract already has accruals.', 'Der Vertrag hat bereits Abgrenzungen.', 'Le contrat a déjà des provisions.'],
            'gdoTradeTerms.error.contract_not_deletable' => ['Si può eliminare solo un contratto in bozza senza accantonamenti.', 'Only a draft contract without accruals can be deleted.', 'Nur ein Entwurfsvertrag ohne Abgrenzungen kann gelöscht werden.', 'Seul un contrat en brouillon sans provisions peut être supprimé.'],
            'gdoTradeTerms.error.contract_locked' => ['Un contratto chiuso o annullato non si può modificare.', 'A closed or cancelled contract cannot be changed.', 'Ein abgeschlossener oder stornierter Vertrag kann nicht geändert werden.', 'Un contrat clos ou annulé ne peut pas être modifié.'],
            'gdoTradeTerms.error.contract_not_live' => ['Si può liquidare solo un contratto attivo o chiuso.', 'Only an active or closed contract can be settled.', 'Nur ein aktiver oder abgeschlossener Vertrag kann abgerechnet werden.', 'Seul un contrat actif ou clos peut être réglé.'],
            'gdoTradeTerms.error.open_settlements' => ['Liquida o annulla le liquidazioni aperte prima di chiudere il contratto.', 'Settle or cancel the open settlements before closing the contract.', 'Schliessen Sie die offenen Abrechnungen ab oder stornieren Sie sie, bevor Sie den Vertrag beenden.', 'Réglez ou annulez les règlements ouverts avant de clore le contrat.'],
            'gdoTradeTerms.error.window_excludes_children' => ['Le nuove date del contratto escludono una scala premi o una promozione.', 'The new contract dates leave out a rebate scale or promotion.', 'Die neuen Vertragsdaten schliessen eine Bonusstaffel oder Aktion aus.', 'Les nouvelles dates du contrat excluent un barème ou une promotion.'],
            'gdoTradeTerms.error.window_outside_contract' => ['Le date devono rientrare nel periodo del contratto.', 'The dates must fall inside the contract period.', 'Die Daten müssen in den Vertragszeitraum fallen.', 'Les dates doivent se situer dans la période du contrat.'],
            'gdoTradeTerms.error.tiers_required' => ['Una scala attiva richiede almeno uno scaglione.', 'An active rebate scale needs at least one bracket.', 'Eine aktive Staffel braucht mindestens eine Stufe.', 'Un barème actif exige au moins un palier.'],
            'gdoTradeTerms.error.tier_invalid' => ['Uno scaglione ha soglia o importo negativi, oppure un’aliquota superiore al 100%.', 'A bracket has a negative threshold or amount, or a rate above 100%.', 'Eine Stufe hat einen negativen Schwellenwert oder Betrag oder einen Satz über 100 %.', 'Un palier a un seuil ou un montant négatif, ou un taux supérieur à 100 %.'],
            'gdoTradeTerms.error.tier_duplicate_threshold' => ['Due scaglioni hanno la stessa soglia.', 'Two brackets have the same threshold.', 'Zwei Stufen haben denselben Schwellenwert.', 'Deux paliers ont le même seuil.'],
            'gdoTradeTerms.error.tier_rate_decreasing' => ['Uno scaglione più alto non può riconoscere un’aliquota inferiore a quello precedente.', 'A higher bracket cannot pay a lower rate than the bracket below it.', 'Eine höhere Stufe darf keinen niedrigeren Satz zahlen als die Stufe darunter.', 'Un palier supérieur ne peut pas accorder un taux inférieur à celui du palier précédent.'],
            'gdoTradeTerms.error.scale_overlap' => ['La scala si sovrappone alla scala :code sullo stesso periodo e sugli stessi prodotti.', 'The scale overlaps the scale :code on the same period and products.', 'Die Staffel überschneidet sich mit der Staffel :code im selben Zeitraum und für dieselben Produkte.', 'Le barème chevauche le barème :code sur la même période et les mêmes produits.'],
            'gdoTradeTerms.error.scale_settled' => ['La scala è già stata liquidata: scaglioni e periodo non si possono più modificare.', 'The scale has already been settled: its brackets and period can no longer change.', 'Die Staffel wurde bereits abgerechnet: Stufen und Zeitraum können nicht mehr geändert werden.', 'Le barème a déjà été réglé : ses paliers et sa période ne peuvent plus changer.'],
            'gdoTradeTerms.error.scale_has_accruals' => ['La scala ha accantonamenti o liquidazioni e non si può eliminare.', 'The scale has accruals or settlements and cannot be deleted.', 'Die Staffel hat Abgrenzungen oder Abrechnungen und kann nicht gelöscht werden.', 'Le barème a des provisions ou des règlements et ne peut pas être supprimé.'],
            'gdoTradeTerms.error.promotion_overlap' => ['La promozione si sovrappone a :code (stesso tipo, periodo e prodotti).', 'The promotion overlaps :code (same type, period and products).', 'Die Aktion überschneidet sich mit :code (gleiche Art, Zeitraum und Produkte).', 'La promotion chevauche :code (même type, période et produits).'],
            'gdoTradeTerms.error.promotion_settled' => ['La promozione è già stata liquidata.', 'The promotion has already been settled.', 'Die Aktion wurde bereits abgerechnet.', 'La promotion a déjà été réglée.'],
            'gdoTradeTerms.error.promotion_not_deletable' => ['Si può eliminare solo una promozione in bozza.', 'Only a draft promotion can be deleted.', 'Nur eine Entwurfsaktion kann gelöscht werden.', 'Seule une promotion en brouillon peut être supprimée.'],
            'gdoTradeTerms.error.contribution_value_required' => ['Indica l’aliquota, l’importo unitario o l’importo fisso richiesto dalla modalità del contributo.', 'Enter the rate, the unit amount or the fixed amount that the contribution mode needs.', 'Geben Sie den Satz, den Stückbetrag oder den Fixbetrag an, den die Beitragsart verlangt.', 'Indiquez le taux, le montant unitaire ou le montant fixe que demande le mode de contribution.'],
            'gdoTradeTerms.error.sell_out_mode_requires_sell_out_type' => ['La modalità sul sell-out appartiene alle promozioni sell-out.', 'The sell-out contribution mode belongs to sell-out promotions.', 'Die Sell-out-Berechnung gehört zu Sell-out-Aktionen.', 'Le mode sur le sell-out appartient aux promotions sell-out.'],
            'gdoTradeTerms.error.sell_out_type_requires_sell_out_mode' => ['Una promozione sell-out si calcola sul sell-out comunicato dall’insegna.', 'A sell-out promotion is calculated on the sell-out reported by the retailer.', 'Eine Sell-out-Aktion wird auf dem vom Händler gemeldeten Sell-out berechnet.', 'Une promotion sell-out se calcule sur le sell-out déclaré par l’enseigne.'],
            'gdoTradeTerms.error.rate_invalid' => ['L’aliquota non può superare il 100%.', 'The rate cannot exceed 100%.', 'Der Satz darf 100 % nicht überschreiten.', 'Le taux ne peut pas dépasser 100 %.'],
            'gdoTradeTerms.error.delivery_point_code_taken' => ['L’insegna ha già un punto di consegna con questo codice.', 'This retailer already has a delivery point with that code.', 'Dieser Händler hat bereits eine Lieferstelle mit diesem Code.', 'Cette enseigne a déjà un point de livraison avec ce code.'],
            'gdoTradeTerms.error.assignment_target_not_found' => ['Fattura, punto di consegna o prodotto non trovato.', 'The invoice, the delivery point or the product was not found.', 'Rechnung, Lieferstelle oder Produkt nicht gefunden.', 'Facture, point de livraison ou produit introuvable.'],
            'gdoTradeTerms.error.assignment_contact_mismatch' => ['La fattura non è intestata a questa insegna né a un suo punto vendita.', 'The invoice is not addressed to this retailer or to one of its member stores.', 'Die Rechnung ist weder an diesen Händler noch an eine seiner Filialen adressiert.', 'La facture n’est adressée ni à cette enseigne ni à l’un de ses magasins.'],
            'gdoTradeTerms.error.product_not_found' => ['Prodotto non trovato.', 'The product was not found.', 'Das Produkt wurde nicht gefunden.', 'Produit introuvable.'],
            'gdoTradeTerms.error.ledger_account_missing' => ['Nessun conto contabile per «:role» (codice piano dei conti :code): sceglilo sul contratto.', 'No ledger account found for “:role” (chart code :code): choose one on the contract.', 'Kein Sachkonto für «:role» (Kontenplan-Code :code): Wählen Sie eines im Vertrag.', 'Aucun compte comptable pour « :role » (code du plan comptable :code) : choisissez-en un sur le contrat.'],
            'gdoTradeTerms.error.later_runs_exist' => ['Un mese successivo è già contabilizzato: solo l’ultima chiusura si può stornare o ricalcolare (:period).', 'A later month is already posted: only the latest accrual run can be reversed or recalculated (:period).', 'Ein späterer Monat ist bereits gebucht: Nur der letzte Lauf kann storniert oder neu berechnet werden (:period).', 'Un mois suivant est déjà comptabilisé : seul le dernier traitement peut être extourné ou recalculé (:period).'],
            'gdoTradeTerms.error.period_before_latest_run' => ['Un mese successivo (:period) è già contabilizzato: accantona i mesi in ordine.', 'A later month (:period) is already posted: accrue the months in order.', 'Ein späterer Monat (:period) ist bereits gebucht: Grenzen Sie die Monate der Reihe nach ab.', 'Un mois suivant (:period) est déjà comptabilisé : provisionnez les mois dans l’ordre.'],
            'gdoTradeTerms.error.run_not_posted' => ['La chiusura mensile non è contabilizzata.', 'The accrual run is not posted.', 'Der Abgrenzungslauf ist nicht gebucht.', 'Le traitement de provision n’est pas comptabilisé.'],
            'gdoTradeTerms.error.run_has_settled_lines' => ['Alcuni accantonamenti della chiusura sono già liquidati.', 'Some accruals of the run are already settled.', 'Einige Abgrenzungen des Laufs sind bereits abgerechnet.', 'Certaines provisions du traitement sont déjà réglées.'],
            'gdoTradeTerms.error.nothing_to_settle' => ['Non c’è nulla da liquidare.', 'There is nothing to settle.', 'Es gibt nichts abzurechnen.', 'Il n’y a rien à régler.'],
            'gdoTradeTerms.error.open_settlement_exists' => ['Il contratto ha una liquidazione ancora aperta: liquidala o annullala prima.', 'The contract has a settlement still open: settle or cancel it first.', 'Der Vertrag hat noch eine offene Abrechnung: Schliessen Sie sie ab oder stornieren Sie sie zuerst.', 'Le contrat a un règlement encore ouvert : réglez-le ou annulez-le d’abord.'],
            'gdoTradeTerms.error.credit_note_not_positive' => ['L’importo della nota di credito deve essere positivo su ogni riga: regola a parte con l’insegna le righe negative.', 'The credit note amount must be positive on every line: settle the negative lines with the retailer separately.', 'Der Gutschriftbetrag muss auf jeder Zeile positiv sein: Regeln Sie negative Zeilen separat mit dem Händler.', 'Le montant de l’avoir doit être positif sur chaque ligne : réglez séparément les lignes négatives avec l’enseigne.'],
            'gdoTradeTerms.error.settlement_closed' => ['La liquidazione non è più aperta.', 'The settlement is no longer open.', 'Die Abrechnung ist nicht mehr offen.', 'Le règlement n’est plus ouvert.'],
            'gdoTradeTerms.error.settlement_not_disputed' => ['La liquidazione non ha contestazioni da risolvere.', 'The settlement has no dispute to resolve.', 'Die Abrechnung hat keinen Streitfall zu lösen.', 'Le règlement n’a pas de litige à résoudre.'],
            'gdoTradeTerms.error.settlement_has_settled_lines' => ['Una liquidazione con righe liquidate non si può annullare.', 'A settlement with settled lines cannot be cancelled.', 'Eine Abrechnung mit abgerechneten Zeilen kann nicht storniert werden.', 'Un règlement avec des lignes réglées ne peut pas être annulé.'],
            'gdoTradeTerms.error.volumes_required' => ['Indica almeno un volume da simulare.', 'Enter at least one volume to simulate.', 'Geben Sie mindestens ein Volumen zur Simulation an.', 'Indiquez au moins un volume à simuler.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'gdoTradeTerms.field.accrued' => ['Accantonato', 'Accrued', 'Abgegrenzt', 'Provisionné'],
            'gdoTradeTerms.field.active' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'gdoTradeTerms.field.inactive' => ['Non attivo', 'Inactive', 'Inaktiv', 'Inactif'],
            'gdoTradeTerms.field.attainment' => ['Raggiungimento dello scaglione successivo (proiezione)', 'Attainment of the next bracket (projection)', 'Erreichung der nächsten Stufe (Prognose)', 'Atteinte du palier suivant (projection)'],
            'gdoTradeTerms.field.base' => ['Base di calcolo', 'Base', 'Berechnungsbasis', 'Base de calcul'],
            'gdoTradeTerms.field.baselineSource' => ['Origine della base di confronto', 'Baseline source', 'Quelle der Vergleichsbasis', 'Source de la base de comparaison'],
            'gdoTradeTerms.field.baselineValue' => ['Base di confronto', 'Baseline', 'Vergleichsbasis', 'Base de comparaison'],
            'gdoTradeTerms.field.basis' => ['Base di calcolo del premio', 'Rebate basis', 'Berechnungsgrundlage', 'Base de la remise'],
            'gdoTradeTerms.field.brands' => ['Marchi', 'Brands', 'Marken', 'Marques'],
            'gdoTradeTerms.field.capAmount' => ['Tetto massimo', 'Cap', 'Höchstbetrag', 'Plafond'],
            'gdoTradeTerms.field.categories' => ['Categorie (con sottocategorie)', 'Categories (with sub-categories)', 'Kategorien (mit Unterkategorien)', 'Catégories (avec sous-catégories)'],
            'gdoTradeTerms.field.cediDiscount' => ['Sconto Ce.Di. (%)', 'Ce.Di. discount (%)', 'Ce.Di.-Rabatt (%)', 'Remise Ce.Di. (%)'],
            'gdoTradeTerms.field.city' => ['Città', 'City', 'Ort', 'Ville'],
            'gdoTradeTerms.field.claimInvoice' => ['Fattura dell’insegna', 'Retailer’s invoice', 'Rechnung des Händlers', 'Facture de l’enseigne'],
            'gdoTradeTerms.field.claimNet' => ['Imponibile fatturato', 'Invoiced net amount', 'Fakturierter Nettobetrag', 'Montant net facturé'],
            'gdoTradeTerms.field.claimTax' => ['IVA fatturata', 'Invoiced VAT', 'Fakturierte MWST', 'TVA facturée'],
            'gdoTradeTerms.field.claimVariance' => ['Scostamento dal maturato', 'Variance from earned', 'Abweichung vom Verdienten', 'Écart par rapport à l’acquis'],
            'gdoTradeTerms.field.code' => ['Codice', 'Code', 'Code', 'Code'],
            'gdoTradeTerms.field.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'gdoTradeTerms.field.contracts' => ['Contratti', 'Contracts', 'Verträge', 'Contrats'],
            'gdoTradeTerms.field.contribution' => ['Contributo', 'Contribution', 'Beitrag', 'Contribution'],
            'gdoTradeTerms.field.contributionMode' => ['Modalità del contributo', 'Contribution mode', 'Beitragsart', 'Mode de contribution'],
            'gdoTradeTerms.field.cost' => ['Costo', 'Cost', 'Kosten', 'Coût'],
            'gdoTradeTerms.field.country' => ['Paese (ISO)', 'Country (ISO)', 'Land (ISO)', 'Pays (ISO)'],
            'gdoTradeTerms.field.creditNote' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Avoir'],
            'gdoTradeTerms.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'gdoTradeTerms.field.dateBasis' => ['Data di riferimento', 'Reference date', 'Bezugsdatum', 'Date de référence'],
            'gdoTradeTerms.field.deliveryPoints' => ['Punti di consegna (punti vendita, Ce.Di.)', 'Delivery points (stores, Ce.Di.)', 'Lieferstellen (Filialen, Ce.Di.)', 'Points de livraison (magasins, Ce.Di.)'],
            'gdoTradeTerms.field.delta' => ['Da accantonare', 'To accrue', 'Zuzuführen', 'À provisionner'],
            'gdoTradeTerms.field.discount1' => ['Primo sconto (%)', '1st discount (%)', '1. Rabatt (%)', '1re remise (%)'],
            'gdoTradeTerms.field.discount2' => ['Secondo sconto (%)', '2nd discount (%)', '2. Rabatt (%)', '2e remise (%)'],
            'gdoTradeTerms.field.discount3' => ['Terzo sconto (%)', '3rd discount (%)', '3. Rabatt (%)', '3e remise (%)'],
            'gdoTradeTerms.field.earned' => ['Maturato', 'Earned', 'Verdient', 'Acquis'],
            'gdoTradeTerms.field.earnedCumulative' => ['Maturato cumulato', 'Cumulative earned', 'Kumuliert verdient', 'Acquis cumulé'],
            'gdoTradeTerms.field.earnedToDate' => ['Maturato a oggi', 'Earned to date', 'Bisher verdient', 'Acquis à ce jour'],
            'gdoTradeTerms.field.effectivePercent' => ['Incidenza effettiva (%)', 'Effective rate (%)', 'Effektiver Satz (%)', 'Taux effectif (%)'],
            'gdoTradeTerms.field.endsOn' => ['Fine', 'End', 'Ende', 'Fin'],
            'gdoTradeTerms.field.expenseAccount' => ['Conto premi e sconti passivi', 'Rebates and discounts expense account', 'Konto Rückvergütungen und Erlösminderungen', 'Compte de remises et ristournes'],
            'gdoTradeTerms.field.fixedAccrual' => ['Maturazione dell’importo fisso', 'Fixed amount accrual', 'Abgrenzung des Fixbetrags', 'Provision du montant fixe'],
            'gdoTradeTerms.field.fixedAmount' => ['Importo fisso', 'Fixed amount', 'Fixbetrag', 'Montant fixe'],
            'gdoTradeTerms.field.flatAmount' => ['Importo forfettario', 'Flat amount', 'Pauschalbetrag', 'Montant forfaitaire'],
            'gdoTradeTerms.field.from' => ['Dal', 'From', 'Von', 'Du'],
            'gdoTradeTerms.field.gapToNext' => ['Distanza dallo scaglione', 'Gap to the bracket', 'Abstand zur Stufe', 'Écart au palier'],
            'gdoTradeTerms.field.grossMargin' => ['Margine lordo', 'Gross margin', 'Bruttomarge', 'Marge brute'],
            'gdoTradeTerms.field.grossSales' => ['Vendite a listino', 'Sales at list price', 'Umsatz zu Listenpreisen', 'Ventes au tarif'],
            'gdoTradeTerms.field.groupBy' => ['Raggruppa', 'Group by', 'Gruppieren', 'Regrouper'],
            'gdoTradeTerms.field.includeChildContacts' => ['Includi le fatture dei punti vendita collegati', 'Include invoices of the member stores', 'Rechnungen der verbundenen Filialen einbeziehen', 'Inclure les factures des magasins rattachés'],
            'gdoTradeTerms.field.invoiceDate' => ['Data fattura', 'Invoice date', 'Rechnungsdatum', 'Date de la facture'],
            'gdoTradeTerms.field.invoiceNumber' => ['Numero fattura', 'Invoice number', 'Rechnungsnummer', 'Numéro de facture'],
            'gdoTradeTerms.field.jump' => ['Salto', 'Jump', 'Sprung', 'Saut'],
            'gdoTradeTerms.field.jurisdiction' => ['Giurisdizione', 'Jurisdiction', 'Rechtsraum', 'Juridiction'],
            'gdoTradeTerms.field.kind' => ['Tipo di liquidazione', 'Settlement type', 'Abrechnungsart', 'Type de règlement'],
            'gdoTradeTerms.field.label' => ['Etichetta', 'Label', 'Bezeichnung', 'Libellé'],
            'gdoTradeTerms.field.month' => ['Mese', 'Month', 'Monat', 'Mois'],
            'gdoTradeTerms.field.name' => ['Nome', 'Name', 'Name', 'Nom'],
            'gdoTradeTerms.field.netNetMargin' => ['Margine net-net', 'Net-net margin', 'Netto-Netto-Marge', 'Marge net-net'],
            'gdoTradeTerms.field.netSales' => ['Vendite nette (fatturate)', 'Net sales (invoiced)', 'Nettoumsatz (fakturiert)', 'Ventes nettes (facturées)'],
            'gdoTradeTerms.field.nextTier' => ['Prossimo scaglione', 'Next bracket', 'Nächste Stufe', 'Palier suivant'],
            'gdoTradeTerms.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'gdoTradeTerms.field.offInvoiceRebates' => ['Premi fuori fattura (stima)', 'Off-invoice rebates (estimate)', 'Rückvergütungen ausserhalb der Rechnung (Schätzung)', 'Remises hors facture (estimation)'],
            'gdoTradeTerms.field.onInvoiceDiscount' => ['Sconto in fattura', 'On-invoice discount', 'Rabatt auf der Rechnung', 'Remise en facture'],
            'gdoTradeTerms.field.palletDiscount' => ['Sconto per pallet', 'Pallet discount', 'Palettenrabatt', 'Remise par palette'],
            'gdoTradeTerms.field.payableAccount' => ['Conto debiti verso l’insegna', 'Payable to the retailer account', 'Konto Verbindlichkeiten gegenüber dem Händler', 'Compte de dettes envers l’enseigne'],
            'gdoTradeTerms.field.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'gdoTradeTerms.field.periodEnd' => ['Fine periodo', 'Period end', 'Ende des Zeitraums', 'Fin de la période'],
            'gdoTradeTerms.field.periodStart' => ['Inizio periodo', 'Period start', 'Beginn des Zeitraums', 'Début de la période'],
            'gdoTradeTerms.field.pointType' => ['Tipo', 'Type', 'Art', 'Type'],
            'gdoTradeTerms.field.postalCode' => ['CAP', 'Postal code', 'PLZ', 'Code postal'],
            'gdoTradeTerms.field.postedAt' => ['Contabilizzata il', 'Posted on', 'Gebucht am', 'Comptabilisée le'],
            'gdoTradeTerms.field.previouslyAccrued' => ['Già accantonato', 'Already accrued', 'Bereits abgegrenzt', 'Déjà provisionné'],
            'gdoTradeTerms.field.product' => ['Prodotto', 'Product', 'Produkt', 'Produit'],
            'gdoTradeTerms.field.products' => ['Prodotti', 'Products', 'Produkte', 'Produits'],
            'gdoTradeTerms.field.projectedHere' => ['Proiezione di fine periodo', 'Year-end projection', 'Prognose zum Periodenende', 'Projection de fin de période'],
            'gdoTradeTerms.field.projectedRebate' => ['Premio proiettato a fine periodo', 'Projected year-end rebate', 'Prognostizierte Rückvergütung am Periodenende', 'Remise projetée en fin de période'],
            'gdoTradeTerms.field.projectedVolume' => ['Volume proiettato', 'Projected volume', 'Prognostiziertes Volumen', 'Volume projeté'],
            'gdoTradeTerms.field.promotion' => ['Promozione', 'Promotion', 'Aktion', 'Promotion'],
            'gdoTradeTerms.field.promotionContributions' => ['Contributi promozionali', 'Promotion contributions', 'Aktionsbeiträge', 'Contributions promotionnelles'],
            'gdoTradeTerms.field.promotionType' => ['Tipo di promozione', 'Promotion type', 'Aktionsart', 'Type de promotion'],
            'gdoTradeTerms.field.provisionAccount' => ['Conto fatture e note di credito da emettere', 'Credit notes to be issued account', 'Konto Rückstellung für Gutschriften', 'Compte d’avoirs à établir'],
            'gdoTradeTerms.field.qualifying' => ['Volume qualificante', 'Qualifying volume', 'Qualifizierendes Volumen', 'Volume qualifiant'],
            'gdoTradeTerms.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'gdoTradeTerms.field.rate' => ['Aliquota', 'Rate', 'Satz', 'Taux'],
            'gdoTradeTerms.field.ratePercent' => ['Aliquota (%)', 'Rate (%)', 'Satz (%)', 'Taux (%)'],
            'gdoTradeTerms.field.reachedNow' => ['Raggiunto', 'Reached', 'Erreicht', 'Atteint'],
            'gdoTradeTerms.field.reason' => ['Motivo', 'Reason', 'Grund', 'Motif'],
            'gdoTradeTerms.field.rebateAt' => ['Premio alla soglia', 'Rebate at the threshold', 'Rückvergütung an der Schwelle', 'Remise au seuil'],
            'gdoTradeTerms.field.rebateBelow' => ['Premio appena sotto la soglia', 'Rebate just below the threshold', 'Rückvergütung knapp unter der Schwelle', 'Remise juste sous le seuil'],
            'gdoTradeTerms.field.requiredDaily' => ['Volume giornaliero necessario', 'Daily volume needed', 'Erforderliches Tagesvolumen', 'Volume quotidien nécessaire'],
            'gdoTradeTerms.field.retailer' => ['Insegna / centrale d’acquisto', 'Retailer / buying group', 'Händler / Einkaufsgruppe', 'Enseigne / centrale d’achat'],
            'gdoTradeTerms.field.reversalReason' => ['Motivo dello storno', 'Reversal reason', 'Stornogrund', 'Motif de l’extourne'],
            'gdoTradeTerms.field.scale' => ['Scala premi', 'Rebate scale', 'Bonusstaffel', 'Barème de remises'],
            'gdoTradeTerms.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'gdoTradeTerms.field.settledOn' => ['Data di liquidazione', 'Settlement date', 'Abrechnungsdatum', 'Date de règlement'],
            'gdoTradeTerms.field.settlement' => ['Liquidazione', 'Settlement', 'Abrechnung', 'Règlement'],
            'gdoTradeTerms.field.source' => ['Origine', 'Source', 'Quelle', 'Source'],
            'gdoTradeTerms.field.startsOn' => ['Inizio', 'Start', 'Beginn', 'Début'],
            'gdoTradeTerms.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'gdoTradeTerms.field.taxRate' => ['Aliquota IVA (%)', 'VAT rate (%)', 'MWST-Satz (%)', 'Taux de TVA (%)'],
            'gdoTradeTerms.field.threshold' => ['Soglia', 'Threshold', 'Schwelle', 'Seuil'],
            'gdoTradeTerms.field.tier' => ['Scaglione', 'Bracket', 'Stufe', 'Palier'],
            'gdoTradeTerms.field.tierMode' => ['Tipo di scaglione', 'Bracket type', 'Staffelart', 'Type de palier'],
            'gdoTradeTerms.field.tiers' => ['Scaglioni', 'Brackets', 'Stufen', 'Paliers'],
            'gdoTradeTerms.field.timeProgress' => ['Tempo trascorso', 'Time elapsed', 'Verstrichene Zeit', 'Temps écoulé'],
            'gdoTradeTerms.field.to' => ['Al', 'To', 'Bis', 'Au'],
            'gdoTradeTerms.field.tolerance' => ['Tolleranza sulla fattura promozionale (%)', 'Tolerance on the promotional invoice (%)', 'Toleranz auf der Werbekostenrechnung (%)', 'Tolérance sur la facture promotionnelle (%)'],
            'gdoTradeTerms.field.totalAccrued' => ['Totale accantonato', 'Total accrued', 'Total abgegrenzt', 'Total provisionné'],
            'gdoTradeTerms.field.treatment' => ['Trattamento', 'Treatment', 'Behandlung', 'Traitement'],
            'gdoTradeTerms.field.unitAmount' => ['Importo per unità', 'Amount per unit', 'Betrag pro Einheit', 'Montant par unité'],
            'gdoTradeTerms.field.variance' => ['Scostamento', 'Variance', 'Abweichung', 'Écart'],
            'gdoTradeTerms.field.vatAccount' => ['Conto IVA a credito', 'Input VAT account', 'Konto Vorsteuer', 'Compte de TVA déductible'],
            'gdoTradeTerms.field.volume' => ['Volume', 'Volume', 'Volumen', 'Volume'],
            'gdoTradeTerms.field.volumeMode' => ['Volume qualificante', 'Qualifying volume', 'Qualifizierendes Volumen', 'Volume qualifiant'],
            'gdoTradeTerms.field.volumeToDate' => ['Volume a oggi', 'Volume to date', 'Bisheriges Volumen', 'Volume à ce jour'],
            'gdoTradeTerms.field.volumes' => ['Volumi da simulare', 'Volumes to simulate', 'Zu simulierende Volumen', 'Volumes à simuler'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'gdoTradeTerms.action.acceptClaim' => ['Accetta la fattura dell’insegna', 'Accept the retailer’s invoice', 'Rechnung des Händlers akzeptieren', 'Accepter la facture de l’enseigne'],
            'gdoTradeTerms.action.accrue' => ['Accantona e contabilizza', 'Accrue and post', 'Abgrenzen und buchen', 'Provisionner et comptabiliser'],
            'gdoTradeTerms.action.addTier' => ['Aggiungi scaglione', 'Add bracket', 'Stufe hinzufügen', 'Ajouter un palier'],
            'gdoTradeTerms.action.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'gdoTradeTerms.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'gdoTradeTerms.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'gdoTradeTerms.action.cancelSettlement' => ['Annulla la liquidazione', 'Cancel the settlement', 'Abrechnung stornieren', 'Annuler le règlement'],
            'gdoTradeTerms.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'gdoTradeTerms.action.contract.activate' => ['Attiva', 'Activate', 'Aktivieren', 'Activer'],
            'gdoTradeTerms.action.contract.resume' => ['Riprendi', 'Resume', 'Fortsetzen', 'Reprendre'],
            'gdoTradeTerms.action.contract.suspend' => ['Sospendi', 'Suspend', 'Aussetzen', 'Suspendre'],
            'gdoTradeTerms.action.contract.close' => ['Chiudi', 'Close', 'Abschliessen', 'Clore'],
            'gdoTradeTerms.action.contract.cancel' => ['Annulla contratto', 'Cancel contract', 'Vertrag stornieren', 'Annuler le contrat'],
            'gdoTradeTerms.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'gdoTradeTerms.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'gdoTradeTerms.action.editPromotion' => ['Modifica promozione', 'Edit promotion', 'Aktion bearbeiten', 'Modifier la promotion'],
            'gdoTradeTerms.action.editScale' => ['Modifica scala premi', 'Edit rebate scale', 'Bonusstaffel bearbeiten', 'Modifier le barème'],
            'gdoTradeTerms.action.issueCreditNote' => ['Emetti la nota di credito', 'Issue the credit note', 'Gutschrift erstellen', 'Établir l’avoir'],
            'gdoTradeTerms.action.ledger' => ['Registro', 'Ledger', 'Journal', 'Journal'],
            'gdoTradeTerms.action.newContract' => ['Nuovo contratto quadro', 'New framework contract', 'Neuer Rahmenvertrag', 'Nouveau contrat-cadre'],
            'gdoTradeTerms.action.newDeliveryPoint' => ['Nuovo punto di consegna', 'New delivery point', 'Neue Lieferstelle', 'Nouveau point de livraison'],
            'gdoTradeTerms.action.newPromotion' => ['Nuova promozione', 'New promotion', 'Neue Aktion', 'Nouvelle promotion'],
            'gdoTradeTerms.action.newScale' => ['Nuova scala premi (PFA)', 'New rebate scale (PFA)', 'Neue Bonusstaffel (PFA)', 'Nouveau barème de remises (PFA)'],
            'gdoTradeTerms.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'gdoTradeTerms.action.openInvoices' => ['Apri le fatture', 'Open the invoices', 'Rechnungen öffnen', 'Ouvrir les factures'],
            'gdoTradeTerms.action.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'gdoTradeTerms.action.promotion.activate' => ['Attiva', 'Activate', 'Aktivieren', 'Activer'],
            'gdoTradeTerms.action.promotion.end' => ['Termina', 'End', 'Beenden', 'Terminer'],
            'gdoTradeTerms.action.promotion.cancel' => ['Annulla', 'Cancel', 'Stornieren', 'Annuler'],
            'gdoTradeTerms.action.propose' => ['Proponi', 'Propose', 'Vorschlagen', 'Proposer'],
            'gdoTradeTerms.action.proposeSettlement' => ['Proponi una liquidazione', 'Propose a settlement', 'Abrechnung vorschlagen', 'Proposer un règlement'],
            'gdoTradeTerms.action.recalculate' => ['Ricalcola il mese', 'Recalculate the month', 'Monat neu berechnen', 'Recalculer le mois'],
            'gdoTradeTerms.action.reconcileInvoice' => ['Riconcilia la fattura', 'Reconcile the invoice', 'Rechnung abstimmen', 'Rapprocher la facture'],
            'gdoTradeTerms.action.rejectClaim' => ['Respingi la fattura', 'Reject the invoice', 'Rechnung zurückweisen', 'Rejeter la facture'],
            'gdoTradeTerms.action.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'gdoTradeTerms.action.reverse' => ['Storna la chiusura', 'Reverse the run', 'Lauf stornieren', 'Extourner le traitement'],
            'gdoTradeTerms.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'gdoTradeTerms.action.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'gdoTradeTerms.action.simulate' => ['Simula', 'Simulate', 'Simulieren', 'Simuler'],
            'gdoTradeTerms.action.simulateScale' => ['Simula questi scaglioni', 'Simulate these brackets', 'Diese Stufen simulieren', 'Simuler ces paliers'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function confirmations(): array
    {
        return [
            'gdoTradeTerms.confirm.acceptClaim' => ['Liquidare ai valori della fattura dell’insegna?', 'Settle at the amounts of the retailer’s invoice?', 'Zu den Beträgen der Händlerrechnung abrechnen?', 'Régler aux montants de la facture de l’enseigne ?'],
            'gdoTradeTerms.confirm.accrue' => ['Accantonare e contabilizzare il mese?', 'Accrue and post the month?', 'Den Monat abgrenzen und buchen?', 'Provisionner et comptabiliser le mois ?'],
            'gdoTradeTerms.confirm.cancelSettlement' => ['Annullare la liquidazione?', 'Cancel the settlement?', 'Die Abrechnung stornieren?', 'Annuler le règlement ?'],
            'gdoTradeTerms.confirm.contract.activate' => ['Attivare il contratto? Da ora matura premi e contributi.', 'Activate the contract? It starts earning rebates and contributions.', 'Den Vertrag aktivieren? Er verdient ab jetzt Boni und Beiträge.', 'Activer le contrat ? Il acquiert dès maintenant remises et contributions.'],
            'gdoTradeTerms.confirm.contract.resume' => ['Riprendere il contratto?', 'Resume the contract?', 'Den Vertrag fortsetzen?', 'Reprendre le contrat ?'],
            'gdoTradeTerms.confirm.contract.suspend' => ['Sospendere il contratto? Smette di maturare.', 'Suspend the contract? It stops earning.', 'Den Vertrag aussetzen? Er verdient nichts mehr.', 'Suspendre le contrat ? Il n’acquiert plus rien.'],
            'gdoTradeTerms.confirm.contract.close' => ['Chiudere il contratto? Non potrà più essere modificato.', 'Close the contract? It can no longer be changed.', 'Den Vertrag abschliessen? Er kann nicht mehr geändert werden.', 'Clore le contrat ? Il ne pourra plus être modifié.'],
            'gdoTradeTerms.confirm.contract.cancel' => ['Annullare il contratto?', 'Cancel the contract?', 'Den Vertrag stornieren?', 'Annuler le contrat ?'],
            'gdoTradeTerms.confirm.deleteDeliveryPoint' => ['Eliminare il punto di consegna?', 'Delete the delivery point?', 'Die Lieferstelle löschen?', 'Supprimer le point de livraison ?'],
            'gdoTradeTerms.confirm.deletePromotion' => ['Eliminare la promozione?', 'Delete the promotion?', 'Die Aktion löschen?', 'Supprimer la promotion ?'],
            'gdoTradeTerms.confirm.deleteScale' => ['Eliminare la scala premi?', 'Delete the rebate scale?', 'Die Bonusstaffel löschen?', 'Supprimer le barème ?'],
            'gdoTradeTerms.confirm.promotion.activate' => ['Attivare la promozione?', 'Activate the promotion?', 'Die Aktion aktivieren?', 'Activer la promotion ?'],
            'gdoTradeTerms.confirm.promotion.end' => ['Terminare la promozione?', 'End the promotion?', 'Die Aktion beenden?', 'Terminer la promotion ?'],
            'gdoTradeTerms.confirm.promotion.cancel' => ['Annullare la promozione? L’accantonamento viene rilasciato al prossimo calcolo.', 'Cancel the promotion? Its accrual is released at the next run.', 'Die Aktion stornieren? Die Abgrenzung wird im nächsten Lauf aufgelöst.', 'Annuler la promotion ? Sa provision est libérée au prochain traitement.'],
            'gdoTradeTerms.confirm.recalculate' => ['Stornare il mese già contabilizzato e ricalcolarlo?', 'Reverse the month that is already posted and calculate it again?', 'Den bereits gebuchten Monat stornieren und neu berechnen?', 'Extourner le mois déjà comptabilisé et le recalculer ?'],
            'gdoTradeTerms.confirm.rejectClaim' => ['Respingere la fattura? La liquidazione torna aperta.', 'Reject the invoice? The settlement goes back to open.', 'Die Rechnung zurückweisen? Die Abrechnung wird wieder offen.', 'Rejeter la facture ? Le règlement redevient ouvert.'],
            'gdoTradeTerms.confirm.reverse' => ['Stornare la chiusura mensile?', 'Reverse the monthly run?', 'Den Monatslauf stornieren?', 'Extourner le traitement mensuel ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'gdoTradeTerms.tab.terms' => ['Condizioni', 'Terms', 'Konditionen', 'Conditions'],
            'gdoTradeTerms.tab.scales' => ['Premi a scaglioni (PFA)', 'Tiered rebates (PFA)', 'Staffelboni (PFA)', 'Remises à paliers (PFA)'],
            'gdoTradeTerms.tab.promotions' => ['Promozioni e contributi', 'Promotions and contributions', 'Aktionen und Beiträge', 'Promotions et contributions'],
            'gdoTradeTerms.tab.attainment' => ['Raggiungimento e proiezione', 'Attainment and projection', 'Zielerreichung und Prognose', 'Atteinte et projection'],
            'gdoTradeTerms.tab.simulator' => ['Simulatore', 'Simulator', 'Simulator', 'Simulateur'],
            'gdoTradeTerms.section.cliffs' => ['Salti alle soglie', 'Jumps at the thresholds', 'Sprünge an den Schwellen', 'Sauts aux seuils'],
            'gdoTradeTerms.section.contribution' => ['Contributo', 'Contribution', 'Beitrag', 'Contribution'],
            'gdoTradeTerms.section.creditNoteLines' => ['Premi e riduzioni di prezzo: nota di credito', 'Rebates and price reductions: credit note', 'Boni und Preisreduktionen: Gutschrift', 'Remises et réductions de prix : avoir'],
            'gdoTradeTerms.section.ledger' => ['Registro degli accantonamenti', 'Accrual ledger', 'Abgrenzungsjournal', 'Journal des provisions'],
            'gdoTradeTerms.section.onInvoice' => ['Sconti in fattura', 'On-invoice discounts', 'Rabatte auf der Rechnung', 'Remises en facture'],
            'gdoTradeTerms.section.preview' => ['Anteprima del mese', 'Preview of the month', 'Vorschau des Monats', 'Aperçu du mois'],
            'gdoTradeTerms.section.promoLines' => ['Servizi promozionali: fattura dell’insegna', 'Promotional services: the retailer’s invoice', 'Werbeleistungen: Rechnung des Händlers', 'Prestations promotionnelles : facture de l’enseigne'],
            'gdoTradeTerms.section.promotions' => ['Promozioni e contributi', 'Promotions and contributions', 'Aktionen und Beiträge', 'Promotions et contributions'],
            'gdoTradeTerms.section.scope' => ['Ambito: prodotti, marchi e punti di consegna', 'Scope: products, brands and delivery points', 'Geltungsbereich: Produkte, Marken und Lieferstellen', 'Périmètre : produits, marques et points de livraison'],
            'gdoTradeTerms.section.settlementRules' => ['Regole di liquidazione e conti contabili', 'Settlement rules and ledger accounts', 'Abrechnungsregeln und Sachkonten', 'Règles de règlement et comptes comptables'],
            'gdoTradeTerms.section.tiers' => ['Scaglioni', 'Brackets', 'Stufen', 'Paliers'],
            'gdoTradeTerms.dashboard.accruedPromotions' => ['Contributi accantonati e da liquidare', 'Contributions accrued and unsettled', 'Abgegrenzte, nicht abgerechnete Beiträge', 'Contributions provisionnées non réglées'],
            'gdoTradeTerms.dashboard.accruedScales' => ['Premi accantonati e da liquidare', 'Rebates accrued and unsettled', 'Abgegrenzte, nicht abgerechnete Boni', 'Remises provisionnées non réglées'],
            'gdoTradeTerms.dashboard.activePromotions' => ['Promozioni in corso', 'Running promotions', 'Laufende Aktionen', 'Promotions en cours'],
            'gdoTradeTerms.dashboard.disputedSettlements' => ['Liquidazioni contestate', 'Disputed settlements', 'Strittige Abrechnungen', 'Règlements contestés'],
            'gdoTradeTerms.dashboard.latestRun' => ['Ultimo mese accantonato', 'Latest month accrued', 'Zuletzt abgegrenzter Monat', 'Dernier mois provisionné'],
            'gdoTradeTerms.dashboard.openSettlements' => ['Liquidazioni aperte', 'Open settlements', 'Offene Abrechnungen', 'Règlements ouverts'],
            'gdoTradeTerms.dashboard.projections' => ['Proiezione dei contratti in corso', 'Projection of the running contracts', 'Prognose der laufenden Verträge', 'Projection des contrats en cours'],
            'gdoTradeTerms.empty.contracts' => ['Nessun contratto quadro.', 'No framework contracts.', 'Keine Rahmenverträge.', 'Aucun contrat-cadre.'],
            'gdoTradeTerms.empty.deliveryPoints' => ['Nessun punto di consegna.', 'No delivery points.', 'Keine Lieferstellen.', 'Aucun point de livraison.'],
            'gdoTradeTerms.empty.lines' => ['Nessuna riga.', 'No lines.', 'Keine Zeilen.', 'Aucune ligne.'],
            'gdoTradeTerms.empty.margin' => ['Nessuna vendita nel periodo.', 'No sales in the period.', 'Keine Verkäufe im Zeitraum.', 'Aucune vente sur la période.'],
            'gdoTradeTerms.empty.preview' => ['Nulla da accantonare per il mese.', 'Nothing to accrue for the month.', 'Für den Monat ist nichts abzugrenzen.', 'Rien à provisionner pour le mois.'],
            'gdoTradeTerms.empty.progress' => ['Nessun dato di avanzamento.', 'No progress data.', 'Keine Fortschrittsdaten.', 'Aucune donnée d’avancement.'],
            'gdoTradeTerms.empty.projections' => ['Nessun contratto attivo in corso.', 'No running active contracts.', 'Keine laufenden aktiven Verträge.', 'Aucun contrat actif en cours.'],
            'gdoTradeTerms.empty.promotions' => ['Nessuna promozione.', 'No promotions.', 'Keine Aktionen.', 'Aucune promotion.'],
            'gdoTradeTerms.empty.runs' => ['Nessuna chiusura mensile.', 'No monthly runs.', 'Keine Monatsläufe.', 'Aucun traitement mensuel.'],
            'gdoTradeTerms.empty.scales' => ['Nessuna scala premi.', 'No rebate scales.', 'Keine Bonusstaffeln.', 'Aucun barème de remises.'],
            'gdoTradeTerms.empty.settlements' => ['Nessuna liquidazione.', 'No settlements.', 'Keine Abrechnungen.', 'Aucun règlement.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function hints(): array
    {
        return [
            'gdoTradeTerms.hint.dispute' => ['Scostamento oltre la tolleranza del contratto (:tolerance). Accetta la fattura o respingila e chiedi un documento corretto.', 'Variance beyond the tolerance of the contract (:tolerance). Accept the invoice, or reject it and ask for a corrected document.', 'Abweichung über der Toleranz des Vertrags (:tolerance). Akzeptieren Sie die Rechnung oder weisen Sie sie zurück und verlangen Sie ein korrigiertes Dokument.', 'Écart au-delà de la tolérance du contrat (:tolerance). Acceptez la facture ou rejetez-la et demandez un document corrigé.'],
            'gdoTradeTerms.hint.effectiveDiscount' => ['Sconto effettivo a cascata (primo, secondo, terzo sconto e Ce.Di.)', 'Effective cascaded discount (1st, 2nd, 3rd discount and Ce.Di.)', 'Effektiver kaskadierter Rabatt (1., 2., 3. Rabatt und Ce.Di.)', 'Remise effective en cascade (1re, 2e, 3e remise et Ce.Di.)'],
            'gdoTradeTerms.hint.marginEstimated' => ['Premi fuori fattura e contributi sono stime finché non sono liquidati: i premi usano la proiezione di fine periodo di ogni scala.', 'Off-invoice rebates and contributions are estimates until settled: rebates use the year-end projection of every scale.', 'Rückvergütungen und Beiträge ausserhalb der Rechnung sind bis zur Abrechnung Schätzungen: Boni nutzen die Prognose zum Periodenende jeder Staffel.', 'Les remises hors facture et les contributions sont des estimations jusqu’au règlement : les remises utilisent la projection de fin de période de chaque barème.'],
            'gdoTradeTerms.hint.progressive' => ['Progressivo: ogni fascia di volume tra due soglie matura l’aliquota del proprio scaglione.', 'Progressive: every slice of volume between two thresholds earns the rate of its own bracket.', 'Progressiv: Jeder Volumenabschnitt zwischen zwei Schwellen verdient den Satz seiner Stufe.', 'Progressif : chaque tranche de volume entre deux seuils acquiert le taux de son palier.'],
            'gdoTradeTerms.hint.retroactive' => ['Retroattivo: raggiunta una soglia, l’aliquota dello scaglione si applica a tutto il volume dal primo euro.', 'Retroactive: once a threshold is reached, the rate of that bracket applies to the whole volume from zero.', 'Rückwirkend: Ist eine Schwelle erreicht, gilt der Satz dieser Stufe für das gesamte Volumen ab dem ersten Franken.', 'Rétroactif : dès qu’un seuil est atteint, le taux du palier s’applique à tout le volume depuis le premier euro.'],
            'gdoTradeTerms.hint.scaleWindow' => ['Lascia vuote le date per usare il periodo del contratto.', 'Leave the dates empty to use the period of the contract.', 'Lassen Sie die Daten leer, um den Vertragszeitraum zu verwenden.', 'Laissez les dates vides pour utiliser la période du contrat.'],
            'gdoTradeTerms.hint.scopeEmpty' => ['Un elenco vuoto non pone limiti in quella dimensione.', 'An empty list sets no limit in that dimension.', 'Eine leere Liste setzt in dieser Dimension keine Grenze.', 'Une liste vide ne pose aucune limite dans cette dimension.'],
            'gdoTradeTerms.hint.simulatorVolumes' => ['Volumi separati da virgola, nella base della scala (valore o quantità).', 'Volumes separated by commas, in the basis of the scale (value or quantity).', 'Durch Komma getrennte Volumen in der Basis der Staffel (Wert oder Menge).', 'Volumes séparés par des virgules, dans la base du barème (valeur ou quantité).'],
            'gdoTradeTerms.hint.topTier' => ['Lo scaglione più alto è già raggiunto nella proiezione.', 'The top bracket is already reached in the projection.', 'Die höchste Stufe wird in der Prognose bereits erreicht.', 'Le palier le plus élevé est déjà atteint dans la projection.'],
            'gdoTradeTerms.hint.treatment.creditNote' => ['Riduzione di prezzo: si liquida con nota di credito.', 'Price reduction: settled with a credit note.', 'Preisreduktion: wird mit einer Gutschrift abgerechnet.', 'Réduction de prix : réglée par un avoir.'],
            'gdoTradeTerms.hint.treatment.promoInvoice' => ['Servizio dell’insegna: si liquida con la sua fattura promozionale.', 'Service of the retailer: settled with its promotional invoice.', 'Leistung des Händlers: wird mit dessen Werbekostenrechnung abgerechnet.', 'Prestation de l’enseigne : réglée par sa facture promotionnelle.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'gdoTradeTerms.message.saved' => ['Salvato.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
            'gdoTradeTerms.message.deleted' => ['Eliminato.', 'Deleted.', 'Gelöscht.', 'Supprimé.'],
            'gdoTradeTerms.message.transitioned' => ['Stato aggiornato.', 'Status updated.', 'Status aktualisiert.', 'Statut mis à jour.'],
            'gdoTradeTerms.message.accrued' => ['Mese accantonato e contabilizzato.', 'Month accrued and posted.', 'Monat abgegrenzt und gebucht.', 'Mois provisionné et comptabilisé.'],
            'gdoTradeTerms.message.reversed' => ['Chiusura mensile stornata.', 'Monthly run reversed.', 'Monatslauf storniert.', 'Traitement mensuel extourné.'],
            'gdoTradeTerms.message.creditNote' => ['Nota di credito creata in bozza e fondo rilasciato.', 'Credit note drafted and provision released.', 'Gutschrift als Entwurf erstellt und Rückstellung aufgelöst.', 'Avoir créé en brouillon et provision libérée.'],
            'gdoTradeTerms.message.reconciled' => ['Fattura promozionale riconciliata e compensata con il fondo.', 'Promotional invoice reconciled and set off against the provision.', 'Werbekostenrechnung abgestimmt und mit der Rückstellung verrechnet.', 'Facture promotionnelle rapprochée et compensée avec la provision.'],
            'gdoTradeTerms.message.disputed' => ['La fattura differisce dal maturato: liquidazione in contestazione.', 'The invoice differs from the earned amount: settlement disputed.', 'Die Rechnung weicht vom verdienten Betrag ab: Abrechnung strittig.', 'La facture diffère du montant acquis : règlement contesté.'],
            'gdoTradeTerms.message.disputeResolved' => ['Contestazione risolta.', 'Dispute resolved.', 'Streitfall gelöst.', 'Litige résolu.'],
            'gdoTradeTerms.message.cancelled' => ['Liquidazione annullata.', 'Settlement cancelled.', 'Abrechnung storniert.', 'Règlement annulé.'],
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
