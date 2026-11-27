<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the supplier rebates and blanket purchase agreements workspace (Mod 049): programme setup (tiered
 * volume rebates, fixed target bonus, marketing contributions), live tier progress, accrual runs with the journal
 * preview, settlement proposals with the analytical breakdown and the open-item set-off, and the blanket agreement
 * balance. Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French
 * text fr-CH. Placeholders are written {name}. The desktop and mobile apps carry the same texts in their own resources.
 */
final class SupplierRebatesFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'SUPPLIER_REBATES.NAV' => ['Premi fornitori', 'Supplier rebates', 'Lieferantenrückvergütungen', 'Ristournes fournisseurs'],
            'SUPPLIER_REBATES.TITLE' => ['Premi fornitori e accordi quadro', 'Supplier rebates and blanket agreements', 'Lieferantenrückvergütungen und Rahmenverträge', 'Ristournes fournisseurs et accords-cadres'],
            'SUPPLIER_REBATES.SUBTITLE' => ['Premi di fine anno, ratei e liquidazione con compensazione dello scadenzario', 'Year-end rebates, accruals and settlement with open-item set-off', 'Jahresendboni (Rappels), Abgrenzungen und Abrechnung mit Offene-Posten-Verrechnung', 'Ristournes de fin d’année, régularisations et règlement avec compensation de l’échéancier'],
            'SUPPLIER_REBATES.LOAD_ERROR' => ['Impossibile caricare i dati dei premi fornitori', 'The supplier rebate data could not be loaded', 'Die Daten der Lieferantenrückvergütungen konnten nicht geladen werden', 'Les données des ristournes fournisseurs n’ont pas pu être chargées'],
            'SUPPLIER_REBATES.SAVE_ERROR' => ['Operazione non riuscita', 'The operation failed', 'Der Vorgang ist fehlgeschlagen', 'L’opération a échoué'],
            'SUPPLIER_REBATES.EMPTY' => ['Nessun accordo con i fornitori', 'No supplier agreements', 'Keine Lieferantenvereinbarungen', 'Aucun accord fournisseur'],
            'SUPPLIER_REBATES.NEW_AGREEMENT' => ['Nuovo accordo premi', 'New rebate agreement', 'Neue Rückvergütungsvereinbarung', 'Nouvel accord de ristourne'],
            'SUPPLIER_REBATES.EDIT_AGREEMENT' => ['Modifica accordo premi', 'Edit rebate agreement', 'Rückvergütungsvereinbarung bearbeiten', 'Modifier l’accord de ristourne'],

            'SUPPLIER_REBATES.CODE' => ['Codice', 'Code', 'Code', 'Code'],
            'SUPPLIER_REBATES.NAME' => ['Nome', 'Name', 'Name', 'Nom'],
            'SUPPLIER_REBATES.SUPPLIER' => ['Fornitore', 'Supplier', 'Lieferant', 'Fournisseur'],
            'SUPPLIER_REBATES.BLANKET_AGREEMENT' => ['Contratto quadro', 'Blanket agreement', 'Rahmenvertrag', 'Accord-cadre'],
            'SUPPLIER_REBATES.NO_BLANKET' => ['Tutti gli acquisti del fornitore', 'All purchases from the supplier', 'Alle Einkäufe beim Lieferanten', 'Tous les achats auprès du fournisseur'],
            'SUPPLIER_REBATES.REBATE_TYPE' => ['Tipo di premio', 'Rebate type', 'Rückvergütungsart', 'Type de ristourne'],
            'SUPPLIER_REBATES.TYPE_TIERED_VOLUME' => ['Premio a scaglioni', 'Tiered volume rebate', 'Staffelrückvergütung', 'Ristourne à paliers'],
            'SUPPLIER_REBATES.TYPE_FIXED_BONUS' => ['Premio fisso al target', 'Fixed target bonus', 'Fixbonus bei Zielerreichung', 'Prime fixe à l’objectif'],
            'SUPPLIER_REBATES.TYPE_MARKETING_CONTRIBUTION' => ['Contributi marketing e inserimento', 'Marketing and listing contributions', 'Marketing- und Listungsbeiträge', 'Contributions marketing et référencement'],
            'SUPPLIER_REBATES.TIER_MODE' => ['Modalità di calcolo', 'Calculation mode', 'Berechnungsart', 'Mode de calcul'],
            'SUPPLIER_REBATES.MODE_RETROACTIVE' => ['Retroattivo sul volume totale', 'Retroactive on the total volume', 'Rückwirkend auf das Gesamtvolumen', 'Rétroactif sur le volume total'],
            'SUPPLIER_REBATES.MODE_INCREMENTAL' => ['Incrementale sull’eccedenza di ogni scaglione', 'Incremental on the excess of each tier', 'Inkrementell auf den Überschuss je Staffel', 'Incrémental sur l’excédent de chaque palier'],
            'SUPPLIER_REBATES.FREQUENCY' => ['Periodicità del rateo', 'Accrual frequency', 'Abgrenzungsrhythmus', 'Périodicité de la régularisation'],
            'SUPPLIER_REBATES.FREQ_MONTHLY' => ['Mensile', 'Monthly', 'Monatlich', 'Mensuelle'],
            'SUPPLIER_REBATES.FREQ_QUARTERLY' => ['Trimestrale', 'Quarterly', 'Vierteljährlich', 'Trimestrielle'],
            'SUPPLIER_REBATES.FREQ_CUSTOM' => ['Personalizzata', 'Custom', 'Benutzerdefiniert', 'Personnalisée'],
            'SUPPLIER_REBATES.VALID_FROM' => ['Valido dal', 'Valid from', 'Gültig ab', 'Valable à partir du'],
            'SUPPLIER_REBATES.VALID_TO' => ['Valido fino al', 'Valid until', 'Gültig bis', 'Valable jusqu’au'],
            'SUPPLIER_REBATES.CURRENCY' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'SUPPLIER_REBATES.ACCRUAL_ACCOUNT' => ['Conto ratei attivi', 'Accrued income account', 'Konto Aktive Rechnungsabgrenzung', 'Compte de produits à recevoir'],
            'SUPPLIER_REBATES.INCOME_ACCOUNT' => ['Conto premi fornitori c/ricavi', 'Supplier bonus income account', 'Konto Lieferantenboni (Ertrag)', 'Compte ristournes fournisseurs (produits)'],
            'SUPPLIER_REBATES.NOTES' => ['Note', 'Notes', 'Notizen', 'Notes'],

            'SUPPLIER_REBATES.STATUS_DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'SUPPLIER_REBATES.STATUS_ACTIVE' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'SUPPLIER_REBATES.STATUS_CLOSED' => ['Chiuso', 'Closed', 'Abgeschlossen', 'Clos'],
            'SUPPLIER_REBATES.STATUS_CANCELLED' => ['Annullato', 'Cancelled', 'Storniert', 'Annulé'],
            'SUPPLIER_REBATES.ACTIVATE' => ['Attiva', 'Activate', 'Aktivieren', 'Activer'],
            'SUPPLIER_REBATES.CLOSE' => ['Chiudi', 'Close', 'Abschliessen', 'Clore'],
            'SUPPLIER_REBATES.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'SUPPLIER_REBATES.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'SUPPLIER_REBATES.REMOVE' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'SUPPLIER_REBATES.REFRESH' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],

            'SUPPLIER_REBATES.TIERS' => ['Scaglioni', 'Tiers', 'Staffeln', 'Paliers'],
            'SUPPLIER_REBATES.ADD_TIER' => ['Aggiungi scaglione', 'Add tier', 'Staffel hinzufügen', 'Ajouter un palier'],
            'SUPPLIER_REBATES.THRESHOLD_FROM' => ['Da (minimo)', 'From (minimum)', 'Ab (Minimum)', 'De (minimum)'],
            'SUPPLIER_REBATES.THRESHOLD_TO' => ['A (massimo)', 'To (maximum)', 'Bis (Maximum)', 'À (maximum)'],
            'SUPPLIER_REBATES.RATE_PERCENT' => ['Aliquota %', 'Rate %', 'Satz %', 'Taux %'],
            'SUPPLIER_REBATES.FLAT_AMOUNT' => ['Importo fisso', 'Flat amount', 'Fixbetrag', 'Montant fixe'],
            'SUPPLIER_REBATES.OPEN_ENDED' => ['senza limite', 'no upper limit', 'ohne Obergrenze', 'sans limite'],
            'SUPPLIER_REBATES.TIERS_DISTINCT' => ['Ogni scaglione richiede una soglia diversa', 'Every tier needs a different threshold', 'Jede Staffel braucht eine andere Schwelle', 'Chaque palier requiert un seuil différent'],
            'SUPPLIER_REBATES.REQUIRED' => ['Campo obbligatorio', 'This field is required', 'Pflichtfeld', 'Champ obligatoire'],
            'SUPPLIER_REBATES.BONUS_TARGET' => ['Target di spesa', 'Spend target', 'Ausgabenziel', 'Objectif de dépenses'],
            'SUPPLIER_REBATES.BONUS_AMOUNT' => ['Importo del premio', 'Bonus amount', 'Bonusbetrag', 'Montant de la prime'],

            'SUPPLIER_REBATES.CONTRIBUTIONS' => ['Contributi', 'Contributions', 'Beiträge', 'Contributions'],
            'SUPPLIER_REBATES.ADD_CONTRIBUTION' => ['Aggiungi contributo', 'Add contribution', 'Beitrag hinzufügen', 'Ajouter une contribution'],
            'SUPPLIER_REBATES.KIND' => ['Tipo', 'Kind', 'Art', 'Type'],
            'SUPPLIER_REBATES.KIND_MARKETING' => ['Marketing', 'Marketing', 'Marketing', 'Marketing'],
            'SUPPLIER_REBATES.KIND_CO_MARKETING' => ['Co-marketing', 'Co-marketing', 'Co-Marketing', 'Co-marketing'],
            'SUPPLIER_REBATES.KIND_LISTING' => ['Inserimento a catalogo', 'Catalogue listing', 'Katalog-Listung', 'Référencement au catalogue'],
            'SUPPLIER_REBATES.DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'SUPPLIER_REBATES.AMOUNT' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'SUPPLIER_REBATES.DUE_ON' => ['Scadenza', 'Due on', 'Fällig am', 'Échéance'],
            'SUPPLIER_REBATES.CONTRIB_PLANNED' => ['Pianificato', 'Planned', 'Geplant', 'Planifiée'],
            'SUPPLIER_REBATES.CONTRIB_ACCRUED' => ['Maturato', 'Accrued', 'Abgegrenzt', 'Régularisée'],
            'SUPPLIER_REBATES.CONTRIB_SETTLED' => ['Liquidato', 'Settled', 'Abgerechnet', 'Réglée'],

            'SUPPLIER_REBATES.PROGRESS' => ['Avanzamento', 'Progress', 'Fortschritt', 'Avancement'],
            'SUPPLIER_REBATES.AS_OF' => ['Al', 'As of', 'Per', 'Au'],
            'SUPPLIER_REBATES.QUALIFYING_SPEND' => ['Spesa qualificante', 'Qualifying spend', 'Qualifizierender Umsatz', 'Dépenses éligibles'],
            'SUPPLIER_REBATES.CURRENT_TIER' => ['Scaglione attuale', 'Current tier', 'Aktuelle Staffel', 'Palier actuel'],
            'SUPPLIER_REBATES.NEXT_TIER' => ['Prossimo scaglione', 'Next tier', 'Nächste Staffel', 'Palier suivant'],
            'SUPPLIER_REBATES.NO_TIER' => ['Nessuno scaglione raggiunto', 'No tier reached yet', 'Noch keine Staffel erreicht', 'Aucun palier atteint'],
            'SUPPLIER_REBATES.TOP_TIER' => ['Scaglione massimo raggiunto', 'Top tier reached', 'Höchste Staffel erreicht', 'Palier maximal atteint'],
            'SUPPLIER_REBATES.ACCRUED_REBATE' => ['Premio maturato', 'Accrued rebate', 'Abgegrenzte Rückvergütung', 'Ristourne régularisée'],
            'SUPPLIER_REBATES.EARNED_REBATE' => ['Premio acquisito alla data', 'Rebate earned to date', 'Bis heute verdiente Rückvergütung', 'Ristourne acquise à ce jour'],
            'SUPPLIER_REBATES.SETTLED_REBATE' => ['Premio liquidato', 'Settled rebate', 'Abgerechnete Rückvergütung', 'Ristourne réglée'],
            'SUPPLIER_REBATES.DISTANCE_TO_NEXT' => ['Distanza dal prossimo scaglione', 'Distance to the next tier', 'Abstand zur nächsten Staffel', 'Écart avec le palier suivant'],
            'SUPPLIER_REBATES.DISTANCE_TO_TARGET' => ['Distanza dal target', 'Distance to the target', 'Abstand zum Ziel', 'Écart avec l’objectif'],
            'SUPPLIER_REBATES.PROJECTED_GAIN' => ['Premio aggiuntivo al raggiungimento', 'Extra rebate once reached', 'Zusätzliche Rückvergütung bei Erreichen', 'Ristourne supplémentaire une fois atteint'],
            'SUPPLIER_REBATES.PROJECTED_AT_NEXT' => ['Premio proiettato al prossimo scaglione', 'Projected rebate at the next tier', 'Prognostizierte Rückvergütung bei nächster Staffel', 'Ristourne projetée au palier suivant'],
            'SUPPLIER_REBATES.TARGET_REACHED' => ['Target raggiunto', 'Target reached', 'Ziel erreicht', 'Objectif atteint'],

            'SUPPLIER_REBATES.ACCRUAL_RUNS' => ['Calcolo ratei', 'Accrual runs', 'Abgrenzungsläufe', 'Calculs de régularisation'],
            'SUPPLIER_REBATES.RUN_ACCRUAL' => ['Calcola rateo', 'Run accrual', 'Abgrenzung berechnen', 'Calculer la régularisation'],
            'SUPPLIER_REBATES.PERIOD' => ['Periodo', 'Period', 'Periode', 'Période'],
            'SUPPLIER_REBATES.PERIOD_END' => ['Fine periodo', 'Period end', 'Periodenende', 'Fin de période'],
            'SUPPLIER_REBATES.BASE_AMOUNT' => ['Base di calcolo', 'Calculation base', 'Berechnungsbasis', 'Base de calcul'],
            'SUPPLIER_REBATES.CUMULATIVE' => ['Premio cumulato', 'Cumulative rebate', 'Kumulierte Rückvergütung', 'Ristourne cumulée'],
            'SUPPLIER_REBATES.DELTA' => ['Rateo del periodo', 'Accrual of the period', 'Abgrenzung der Periode', 'Régularisation de la période'],
            'SUPPLIER_REBATES.RUN_POSTED' => ['Registrato', 'Posted', 'Gebucht', 'Comptabilisé'],
            'SUPPLIER_REBATES.RUN_REVERSED' => ['Stornato', 'Reversed', 'Storniert', 'Extourné'],
            'SUPPLIER_REBATES.REVERSE_RUN' => ['Storna calcolo', 'Reverse run', 'Lauf stornieren', 'Extourner le calcul'],
            'SUPPLIER_REBATES.REVERSE_REASON' => ['Motivo dello storno', 'Reason for the reversal', 'Grund der Stornierung', 'Motif de l’extourne'],
            'SUPPLIER_REBATES.JOURNAL_PREVIEW' => ['Anteprima scrittura contabile', 'Journal entry preview', 'Buchungsvorschau', 'Aperçu de l’écriture comptable'],
            'SUPPLIER_REBATES.DEBIT_ACCRUED_INCOME' => ['Dare: ratei attivi', 'Debit: accrued income', 'Soll: aktive Rechnungsabgrenzung', 'Débit : produits à recevoir'],
            'SUPPLIER_REBATES.CREDIT_SUPPLIER_INCOME' => ['Avere: premi fornitori c/ricavi', 'Credit: supplier bonus income', 'Haben: Lieferantenboni (Ertrag)', 'Crédit : ristournes fournisseurs (produits)'],
            'SUPPLIER_REBATES.NOT_POSTED' => ['Nessuna scrittura: conti non configurati', 'No entry: accounts not configured', 'Keine Buchung: Konten nicht konfiguriert', 'Aucune écriture : comptes non configurés'],
            'SUPPLIER_REBATES.CONTRIBUTING_INVOICES' => ['Fatture che hanno contribuito', 'Contributing invoices', 'Einbezogene Rechnungen', 'Factures prises en compte'],
            'SUPPLIER_REBATES.INVOICE' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'SUPPLIER_REBATES.INVOICE_DATE' => ['Data', 'Date', 'Datum', 'Date'],
            'SUPPLIER_REBATES.NET_AMOUNT' => ['Imponibile', 'Net amount', 'Nettobetrag', 'Montant net'],

            'SUPPLIER_REBATES.PROPOSALS' => ['Proposte di liquidazione', 'Settlement proposals', 'Abrechnungsvorschläge', 'Propositions de règlement'],
            'SUPPLIER_REBATES.PROPOSE' => ['Proponi liquidazione', 'Propose settlement', 'Abrechnung vorschlagen', 'Proposer le règlement'],
            'SUPPLIER_REBATES.SUBMIT' => ['Invia in approvazione', 'Submit for approval', 'Zur Genehmigung einreichen', 'Soumettre à approbation'],
            'SUPPLIER_REBATES.APPROVE' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'SUPPLIER_REBATES.EXECUTE' => ['Esegui liquidazione', 'Execute settlement', 'Abrechnung ausführen', 'Exécuter le règlement'],
            'SUPPLIER_REBATES.SETTLEMENT_DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'SUPPLIER_REBATES.SETTLEMENT_PENDING_APPROVAL' => ['In approvazione', 'Pending approval', 'In Genehmigung', 'En attente d’approbation'],
            'SUPPLIER_REBATES.SETTLEMENT_APPROVED' => ['Approvata', 'Approved', 'Genehmigt', 'Approuvée'],
            'SUPPLIER_REBATES.SETTLEMENT_SETTLED' => ['Liquidata', 'Settled', 'Abgerechnet', 'Réglée'],
            'SUPPLIER_REBATES.SETTLEMENT_CANCELLED' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'SUPPLIER_REBATES.EXECUTION_MODE' => ['Modalità di liquidazione', 'Settlement mode', 'Abrechnungsart', 'Mode de règlement'],
            'SUPPLIER_REBATES.EXEC_CREDIT_NOTE_REQUEST' => ['Richiesta nota di credito al fornitore', 'Credit note request to the supplier', 'Gutschriftsanforderung beim Lieferanten', 'Demande de note de crédit au fournisseur'],
            'SUPPLIER_REBATES.EXEC_CHARGE_INVOICE' => ['Fattura attiva di addebito al fornitore', 'Active charge invoice to the supplier', 'Belastungsrechnung an den Lieferanten', 'Facture de débit au fournisseur'],
            'SUPPLIER_REBATES.EXEC_AP_SET_OFF' => ['Compensazione nello scadenzario passivo', 'Set-off in the payables schedule', 'Verrechnung in den offenen Verbindlichkeiten', 'Compensation dans l’échéancier fournisseurs'],
            'SUPPLIER_REBATES.GROSS' => ['Totale lordo', 'Gross total', 'Bruttobetrag', 'Total brut'],
            'SUPPLIER_REBATES.NET' => ['Totale netto', 'Net total', 'Nettobetrag', 'Total net'],
            'SUPPLIER_REBATES.TAX' => ['IVA', 'VAT', 'MwSt', 'TVA'],
            'SUPPLIER_REBATES.TAX_RATE' => ['Aliquota IVA %', 'VAT rate %', 'MwSt-Satz %', 'Taux de TVA %'],
            'SUPPLIER_REBATES.SELECT_INVOICE' => ['Fattura da compensare', 'Invoice to set off against', 'Zu verrechnende Rechnung', 'Facture à compenser'],
            'SUPPLIER_REBATES.OPEN_BALANCE' => ['Residuo aperto', 'Open balance', 'Offener Saldo', 'Solde ouvert'],
            'SUPPLIER_REBATES.PAYABLES_ACCOUNT' => ['Conto debiti verso fornitori', 'Supplier payables account', 'Konto Lieferantenverbindlichkeiten', 'Compte fournisseurs'],
            'SUPPLIER_REBATES.VAT_ACCOUNT' => ['Conto IVA', 'VAT account', 'MwSt-Konto', 'Compte de TVA'],
            'SUPPLIER_REBATES.BREAKDOWN' => ['Dettaglio analitico', 'Analytical breakdown', 'Analytische Aufstellung', 'Détail analytique'],
            'SUPPLIER_REBATES.LINE_INVOICE' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'SUPPLIER_REBATES.LINE_CREDIT_NOTE' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Note de crédit'],
            'SUPPLIER_REBATES.LINE_CONTRIBUTION' => ['Contributo', 'Contribution', 'Beitrag', 'Contribution'],
            'SUPPLIER_REBATES.LINE_BONUS' => ['Premio fisso', 'Fixed bonus', 'Fixbonus', 'Prime fixe'],
            'SUPPLIER_REBATES.REBATE_SHARE' => ['Quota di premio', 'Rebate share', 'Rückvergütungsanteil', 'Part de ristourne'],
            'SUPPLIER_REBATES.CONFIRM_EXECUTE' => ['Eseguire la liquidazione approvata?', 'Execute the approved settlement?', 'Die genehmigte Abrechnung ausführen?', 'Exécuter le règlement approuvé ?'],
            'SUPPLIER_REBATES.CONFIRM_CANCEL' => ['Annullare la proposta? I ratei tornano aperti.', 'Cancel the proposal? The accruals become open again.', 'Vorschlag stornieren? Die Abgrenzungen werden wieder offen.', 'Annuler la proposition ? Les régularisations redeviennent ouvertes.'],
            'SUPPLIER_REBATES.EXECUTED' => ['Liquidazione eseguita', 'Settlement executed', 'Abrechnung ausgeführt', 'Règlement exécuté'],

            'SUPPLIER_REBATES.BLANKET_BALANCE' => ['Saldo del contratto quadro', 'Blanket agreement balance', 'Saldo des Rahmenvertrags', 'Solde de l’accord-cadre'],
            'SUPPLIER_REBATES.CEILING' => ['Plafond', 'Ceiling', 'Plafond', 'Plafond'],
            'SUPPLIER_REBATES.MINIMUM_SPEND' => ['Spesa minima garantita', 'Guaranteed minimum spend', 'Garantierter Mindestumsatz', 'Dépenses minimales garanties'],
            'SUPPLIER_REBATES.ORDERED' => ['Ordinato', 'Ordered', 'Bestellt', 'Commandé'],
            'SUPPLIER_REBATES.RECEIVED' => ['Ricevuto', 'Received', 'Erhalten', 'Reçu'],
            'SUPPLIER_REBATES.INVOICED' => ['Fatturato', 'Invoiced', 'Fakturiert', 'Facturé'],
            'SUPPLIER_REBATES.REMAINING_CEILING' => ['Plafond residuo', 'Remaining ceiling', 'Verbleibendes Plafond', 'Plafond restant'],
            'SUPPLIER_REBATES.SHORTFALL' => ['Mancante al minimo', 'Shortfall to the minimum', 'Fehlbetrag zum Minimum', 'Manque pour atteindre le minimum'],
            'SUPPLIER_REBATES.COMMITTED_QTY' => ['Quantità impegnata', 'Committed quantity', 'Verpflichtete Menge', 'Quantité engagée'],
            'SUPPLIER_REBATES.RELEASED_QTY' => ['Quantità rilasciata', 'Released quantity', 'Abgerufene Menge', 'Quantité appelée'],
            'SUPPLIER_REBATES.REMAINING_QTY' => ['Quantità residua', 'Remaining quantity', 'Restmenge', 'Quantité restante'],
            'SUPPLIER_REBATES.ALERT_CEILING_NEAR' => ['Il plafond è quasi esaurito', 'The ceiling is almost used up', 'Das Plafond ist fast ausgeschöpft', 'Le plafond est presque épuisé'],
            'SUPPLIER_REBATES.ALERT_CEILING_EXCEEDED' => ['Plafond superato', 'Ceiling exceeded', 'Plafond überschritten', 'Plafond dépassé'],
            'SUPPLIER_REBATES.ALERT_MINIMUM_AT_RISK' => ['Spesa minima a rischio', 'Minimum spend at risk', 'Mindestumsatz gefährdet', 'Dépenses minimales à risque'],
            'SUPPLIER_REBATES.ALERT_MINIMUM_NOT_MET' => ['Spesa minima non raggiunta', 'Minimum spend not met', 'Mindestumsatz nicht erreicht', 'Dépenses minimales non atteintes'],

            'RES.F.minimum_spend_amount' => ['Spesa minima garantita', 'Guaranteed minimum spend', 'Garantierter Mindestumsatz', 'Dépenses minimales garanties'],
            'RES.F.alert_threshold_percent' => ['Soglia di allerta %', 'Alert threshold %', 'Warnschwelle %', 'Seuil d’alerte %'],
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
