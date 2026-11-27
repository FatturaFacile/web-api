<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the down-payment chain module (Mod 203, acconti, caparre e depositi cauzionali): the chain list and detail with the
 * timeline, the installment plan wizard with its cent-exact multi-VAT preview, the deposit receipts and their resolution (impute to price
 * with VAT, forfeit on breach out of VAT, refund single or double under Art. 1385 c.c.), the final settlement drawer with the
 * DatiFattureCollegate references and the Prima Nota closure, the credit note (TD04) of an advance, the IT (Art. 15 DPR 633/72, imposta
 * di bollo) and CH (Art. 158 CO) notices, plus the codes the API reports (down_payments.error.<code>). The error texts are taken from
 * the backend dictionary (lang/{it,en,de,fr}/down_payments.php) so the two never drift apart.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI, TD01, TD02, TD04, TD24, N1, DatiFattureCollegate) stay untranslated.
 */
final class DownPaymentsFrontendTranslations
{
    private const array Locales = ['it', 'en', 'de', 'fr'];

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
            self::sections(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.DOWN_PAYMENTS' => ['Acconti e caparre', 'Down payments', 'Anzahlungen', 'Acomptes et arrhes'],
            'NAV.DOWN_PAYMENTS_CHAINS' => ['Catene di acconti', 'Down-payment chains', 'Anzahlungsketten', 'Chaînes d’acomptes'],
            'NAV.DOWN_PAYMENTS_CHAIN_DETAIL' => ['Dettaglio catena', 'Chain detail', 'Kettendetail', 'Détail de la chaîne'],
            'PAGE_TITLE.DOWN_PAYMENTS_CHAINS' => ['Catene di acconti, caparre e depositi cauzionali', 'Down-payment chains, earnest money and security deposits', 'Anzahlungsketten, Draufgaben und Kautionen', 'Chaînes d’acomptes, arrhes et dépôts de garantie'],
            'PAGE_TITLE.DOWN_PAYMENTS_CHAIN_DETAIL' => ['Catena di acconti', 'Down-payment chain', 'Anzahlungskette', 'Chaîne d’acomptes'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            // Status of the chain
            'down_payments.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'down_payments.status.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'down_payments.status.partially_settled' => ['Parzialmente saldata', 'Partially settled', 'Teilweise abgerechnet', 'Partiellement soldée'],
            'down_payments.status.settled' => ['Saldata', 'Settled', 'Abgerechnet', 'Soldée'],
            'down_payments.status.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],
            'down_payments.status.forfeited' => ['Incamerata', 'Forfeited', 'Verfallen', 'Acquise'],

            // Status of an installment
            'down_payments.installmentStatus.planned' => ['Pianificata', 'Planned', 'Geplant', 'Planifiée'],
            'down_payments.installmentStatus.requested' => ['Richiesta', 'Requested', 'Angefordert', 'Demandée'],
            'down_payments.installmentStatus.invoiced' => ['Fatturata', 'Invoiced', 'Fakturiert', 'Facturée'],
            'down_payments.installmentStatus.paid' => ['Incassata', 'Paid', 'Bezahlt', 'Encaissée'],
            'down_payments.installmentStatus.converted' => ['Convertita', 'Converted', 'Umgewandelt', 'Convertie'],
            'down_payments.installmentStatus.forfeited' => ['Incamerata', 'Forfeited', 'Verfallen', 'Acquise'],
            'down_payments.installmentStatus.refunded' => ['Restituita', 'Refunded', 'Zurückerstattet', 'Remboursée'],
            'down_payments.installmentStatus.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],

            // Status of a linked document
            'down_payments.linkStatus.open' => ['Aperta', 'Open', 'Offen', 'Ouverte'],
            'down_payments.linkStatus.issued' => ['Emessa', 'Issued', 'Ausgestellt', 'Émise'],
            'down_payments.linkStatus.partially_credited' => ['Stornata in parte', 'Partially credited', 'Teilweise gutgeschrieben', 'Partiellement créditée'],
            'down_payments.linkStatus.credited' => ['Stornata', 'Credited', 'Gutgeschrieben', 'Créditée'],
            'down_payments.linkStatus.settled' => ['Dedotta a saldo', 'Deducted at settlement', 'In der Schlussrechnung abgezogen', 'Déduite au solde'],
            'down_payments.linkStatus.converted' => ['Convertita', 'Converted', 'Umgewandelt', 'Convertie'],
            'down_payments.linkStatus.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulée'],

            // Type of a linked document
            'down_payments.linkType.advance_invoice_td02' => ['Fattura di acconto TD02', 'Advance invoice TD02', 'Anzahlungsrechnung TD02', 'Facture d’acompte TD02'],
            'down_payments.linkType.proforma_advance_request' => ['Richiesta di acconto (pro-forma)', 'Advance request (pro-forma)', 'Anzahlungsanforderung (Pro-forma)', 'Demande d’acompte (pro forma)'],
            'down_payments.linkType.earnest_money_deposit' => ['Caparra confirmatoria', 'Earnest money deposit', 'Draufgabe', 'Arrhes confirmatoires'],
            'down_payments.linkType.security_deposit' => ['Deposito cauzionale', 'Security deposit', 'Kaution', 'Dépôt de garantie'],
            'down_payments.linkType.credit_note_td04' => ['Nota di credito TD04', 'Credit note TD04', 'Gutschrift TD04', 'Note de crédit TD04'],
            'down_payments.linkType.final_settlement_td01' => ['Fattura di saldo TD01', 'Final settlement invoice TD01', 'Schlussrechnung TD01', 'Facture de solde TD01'],

            // Deposits
            'down_payments.depositType.earnest_money_caparra_confirmatoria' => ['Caparra confirmatoria', 'Earnest money (caparra confirmatoria)', 'Draufgabe (caparra confirmatoria)', 'Arrhes confirmatoires (caparra confirmatoria)'],
            'down_payments.depositType.security_deposit_cauzionale' => ['Deposito cauzionale', 'Security deposit', 'Kaution', 'Dépôt de garantie'],
            'down_payments.depositStatus.issued_unpaid' => ['Emessa, non incassata', 'Issued, unpaid', 'Ausgestellt, unbezahlt', 'Émise, non encaissée'],
            'down_payments.depositStatus.held_active' => ['Trattenuta', 'Held', 'Gehalten', 'Détenue'],
            'down_payments.depositStatus.converted_to_advance' => ['Imputata al prezzo (acconto)', 'Imputed to the price (advance)', 'Auf den Preis angerechnet (Anzahlung)', 'Imputée au prix (acompte)'],
            'down_payments.depositStatus.deducted_in_final_invoice' => ['Dedotta in fattura di saldo', 'Deducted in the final invoice', 'In der Schlussrechnung abgezogen', 'Déduite de la facture de solde'],
            'down_payments.depositStatus.forfeited_on_breach' => ['Incamerata per inadempimento', 'Forfeited on breach', 'Wegen Vertragsbruch verfallen', 'Acquise pour inexécution'],
            'down_payments.depositStatus.refunded_single' => ['Restituita (semplice)', 'Refunded (single)', 'Zurückerstattet (einfach)', 'Remboursée (simple)'],
            'down_payments.depositStatus.refunded_double_art1385' => ['Restituita al doppio (art. 1385 c.c.)', 'Refunded double (Art. 1385 c.c.)', 'Doppelt zurückerstattet (Art. 1385 c.c.)', 'Remboursée au double (art. 1385 c.c.)'],

            // Source of the chain and jurisdiction
            'down_payments.sourceType.sales_order' => ['Ordine cliente', 'Sales order', 'Kundenauftrag', 'Commande client'],
            'down_payments.sourceType.quote' => ['Preventivo', 'Quote', 'Offerte', 'Devis'],
            'down_payments.sourceType.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'down_payments.jurisdiction.IT' => ['Italia', 'Italy', 'Italien', 'Italie'],
            'down_payments.jurisdiction.CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse'],

            // Plan wizard: presets, triggers, calculation modes and instruments
            'down_payments.preset.30_40_30' => ['Tre acconti 30 / 40 / 30', 'Three advances 30 / 40 / 30', 'Drei Anzahlungen 30 / 40 / 30', 'Trois acomptes 30 / 40 / 30'],
            'down_payments.preset.50_50' => ['Due acconti 50 / 50', 'Two advances 50 / 50', 'Zwei Anzahlungen 50 / 50', 'Deux acomptes 50 / 50'],
            'down_payments.preset.caparra_balance' => ['Caparra + acconto', 'Earnest money + advance', 'Draufgabe + Anzahlung', 'Arrhes + acompte'],
            'down_payments.preset.custom' => ['Piano personalizzato', 'Custom plan', 'Individueller Plan', 'Plan personnalisé'],
            'down_payments.trigger.on_order_confirmation' => ['Alla conferma d’ordine', 'On order confirmation', 'Bei Auftragsbestätigung', 'À la confirmation de commande'],
            'down_payments.trigger.on_material_delivery' => ['Alla consegna del materiale', 'On material delivery', 'Bei Materiallieferung', 'À la livraison du matériel'],
            'down_payments.trigger.on_milestone_sal' => ['A stato avanzamento lavori (SAL)', 'On progress milestone (SAL)', 'Bei Baufortschritt (SAL)', 'À l’avancement des travaux (SAL)'],
            'down_payments.trigger.on_commissioning' => ['Al collaudo', 'On commissioning', 'Bei Inbetriebnahme', 'À la mise en service'],
            'down_payments.trigger.custom_date' => ['A data fissata', 'On a fixed date', 'An einem festen Datum', 'À une date fixe'],
            'down_payments.mode.percentage' => ['Percentuale', 'Percentage', 'Prozent', 'Pourcentage'],
            'down_payments.mode.fixed_net' => ['Importo netto fisso', 'Fixed net amount', 'Fester Nettobetrag', 'Montant net fixe'],
            'down_payments.mode.fixed_gross' => ['Importo lordo fisso', 'Fixed gross amount', 'Fester Bruttobetrag', 'Montant brut fixe'],
            'down_payments.instrument.advance_invoice_immediate' => ['Fattura di acconto immediata (TD02)', 'Immediate advance invoice (TD02)', 'Sofortige Anzahlungsrechnung (TD02)', 'Facture d’acompte immédiate (TD02)'],
            'down_payments.instrument.advance_proforma_request' => ['Richiesta di acconto pro-forma', 'Pro-forma advance request', 'Pro-forma-Anzahlungsanforderung', 'Demande d’acompte pro forma'],
            'down_payments.instrument.earnest_money_deposit' => ['Caparra confirmatoria', 'Earnest money deposit', 'Draufgabe', 'Arrhes confirmatoires'],
            'down_payments.instrument.security_deposit' => ['Deposito cauzionale', 'Security deposit', 'Kaution', 'Dépôt de garantie'],

            // Resolution of a held deposit
            'down_payments.resolution.impute_to_price' => ['Imputa al prezzo (con IVA)', 'Impute to the price (with VAT)', 'Auf den Preis anrechnen (mit MWST)', 'Imputer au prix (avec TVA)'],
            'down_payments.resolution.forfeit_breach' => ['Incamera per inadempimento (fuori campo IVA)', 'Forfeit on breach (outside VAT)', 'Wegen Vertragsbruch verfallen lassen (ausserhalb MWST)', 'Acquérir pour inexécution (hors TVA)'],
            'down_payments.resolution.refund' => ['Restituisci', 'Refund', 'Zurückerstatten', 'Rembourser'],
            'down_payments.refundMode.single' => ['Restituzione semplice', 'Single refund', 'Einfache Rückerstattung', 'Remboursement simple'],
            'down_payments.refundMode.double' => ['Restituzione al doppio (art. 1385 c.c.)', 'Double refund (Art. 1385 c.c.)', 'Doppelte Rückerstattung (Art. 1385 c.c.)', 'Remboursement au double (art. 1385 c.c.)'],
            'down_payments.hint.impute_to_price' => ['La caparra diventa un acconto sul prezzo: si emette la fattura di acconto con IVA, poi dedotta dalla fattura di saldo.', 'The deposit becomes an advance on the price: the advance invoice is issued with VAT and later deducted from the final invoice.', 'Die Draufgabe wird zur Anzahlung auf den Preis: Die Anzahlungsrechnung wird mit MWST ausgestellt und später von der Schlussrechnung abgezogen.', 'Le dépôt devient un acompte sur le prix : la facture d’acompte est émise avec TVA, puis déduite de la facture de solde.'],
            'down_payments.hint.forfeit_breach' => ['La caparra è trattenuta per inadempimento del cliente: è un indennizzo fuori campo IVA, senza fattura, registrato tra i proventi.', 'The deposit is kept because the customer breached the contract: it is compensation outside VAT, without an invoice, booked as income.', 'Die Draufgabe wird wegen Vertragsbruchs des Kunden einbehalten: Sie ist eine Entschädigung ausserhalb der MWST, ohne Rechnung, und wird als Ertrag gebucht.', 'Le dépôt est conservé pour inexécution du client : c’est une indemnité hors TVA, sans facture, comptabilisée en produits.'],
            'down_payments.hint.refund' => ['La somma è restituita al cliente. Con la restituzione al doppio (solo caparra confirmatoria, art. 1385 c.c.) si rende l’importo più una penale pari alla caparra.', 'The amount is returned to the customer. With the double refund (earnest money only, Art. 1385 c.c.) the amount is returned plus a penalty equal to the deposit.', 'Der Betrag wird dem Kunden zurückerstattet. Bei der doppelten Rückerstattung (nur Draufgabe, Art. 1385 c.c.) wird der Betrag zuzüglich einer Konventionalstrafe in Höhe der Draufgabe zurückgezahlt.', 'Le montant est restitué au client. Avec le remboursement au double (arrhes confirmatoires uniquement, art. 1385 c.c.), le montant est rendu plus une pénalité égale au dépôt.'],

            // Credit note, settlement document and payment accounts
            'down_payments.creditMode.total' => ['Intero importo residuo', 'Whole open amount', 'Gesamter offener Betrag', 'Totalité du montant ouvert'],
            'down_payments.creditMode.amount' => ['Per importo', 'By amount', 'Nach Betrag', 'Par montant'],
            'down_payments.creditMode.lines' => ['Per riga IVA', 'By VAT line', 'Nach MWST-Zeile', 'Par ligne de TVA'],
            'down_payments.settlementType.TD01' => ['Fattura (TD01)', 'Invoice (TD01)', 'Rechnung (TD01)', 'Facture (TD01)'],
            'down_payments.settlementType.TD24' => ['Fattura differita (TD24)', 'Deferred invoice (TD24)', 'Sammelrechnung (TD24)', 'Facture différée (TD24)'],
            'down_payments.paymentAccount.bank' => ['Banca', 'Bank', 'Bank', 'Banque'],
            'down_payments.paymentAccount.cash' => ['Cassa', 'Cash', 'Kasse', 'Caisse'],

            // Accounts of the Prima Nota previews
            'down_payments.account.receivables' => ['Crediti verso clienti', 'Accounts receivable', 'Forderungen aus Lieferungen und Leistungen', 'Créances clients'],
            'down_payments.account.customer_advances' => ['Clienti c/anticipi', 'Customer advances', 'Kundenanzahlungen', 'Acomptes clients'],
            'down_payments.account.sales_revenue' => ['Ricavi di vendita', 'Sales revenue', 'Verkaufserlöse', 'Produits des ventes'],
            'down_payments.account.output_vat' => ['IVA a debito', 'Output VAT', 'Geschuldete MWST', 'TVA due'],
            'down_payments.account.deposits_received' => ['Depositi e caparre ricevuti', 'Deposits received', 'Erhaltene Depots und Draufgaben', 'Dépôts et arrhes reçus'],
            'down_payments.account.forfeited_deposits_income' => ['Proventi da caparre incamerate', 'Income from forfeited deposits', 'Erträge aus verfallenen Draufgaben', 'Produits des arrhes acquises'],
            'down_payments.account.deposit_penalties' => ['Penali su restituzione al doppio', 'Penalties on double refunds', 'Konventionalstrafen bei doppelter Rückerstattung', 'Pénalités sur remboursement au double'],
            'down_payments.account.bank' => ['Banca', 'Bank', 'Bank', 'Banque'],
            'down_payments.account.cash' => ['Cassa', 'Cash', 'Kasse', 'Caisse'],
        ];
    }

    /**
     * The refusals of the API, in the four languages of the backend dictionary, with `:param` turned into `{param}`.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        $codes = array_keys(self::dictionary('en')['errors']);
        $errors = [
            'down_payments.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Try again.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es erneut.', 'L’opération a échoué. Réessayez.'],
        ];

        foreach ($codes as $code) {
            $errors['down_payments.error.'.$code] = array_map(
                static fn (string $text): string => (string) preg_replace('/:([a-z_]+)/', '{$1}', $text),
                self::backend('errors.'.$code),
            );
        }

        return $errors;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'down_payments.field.account' => ['Conto', 'Account', 'Konto', 'Compte'],
            'down_payments.field.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'down_payments.field.advanceInvoice' => ['Fattura di acconto', 'Advance invoice', 'Anzahlungsrechnung', 'Facture d’acompte'],
            'down_payments.field.advanceResidualAfter' => ['Residuo dell’acconto dopo la nota di credito', 'Advance residual after the credit note', 'Rest der Anzahlung nach der Gutschrift', 'Solde de l’acompte après la note de crédit'],
            'down_payments.field.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'down_payments.field.calculationMode' => ['Modalità di calcolo', 'Calculation mode', 'Berechnungsart', 'Mode de calcul'],
            'down_payments.field.chainNumber' => ['Catena', 'Chain', 'Kette', 'Chaîne'],
            'down_payments.field.contractGross' => ['Totale contratto (lordo)', 'Contract total (gross)', 'Vertragssumme (brutto)', 'Total du contrat (brut)'],
            'down_payments.field.country' => ['Paese', 'Country', 'Land', 'Pays'],
            'down_payments.field.createdAt' => ['Creata il', 'Created on', 'Erstellt am', 'Créée le'],
            'down_payments.field.credit' => ['Avere', 'Credit', 'Haben', 'Crédit'],
            'down_payments.field.creditAmount' => ['Importo della nota di credito (lordo)', 'Credit note amount (gross)', 'Gutschriftsbetrag (brutto)', 'Montant de la note de crédit (brut)'],
            'down_payments.field.creditNet' => ['Netto da stornare', 'Net to credit', 'Gutzuschreibender Nettobetrag', 'Net à créditer'],
            'down_payments.field.creditable' => ['Stornabile (lordo)', 'Creditable (gross)', 'Gutschreibbar (brutto)', 'Créditable (brut)'],
            'down_payments.field.credited' => ['Stornato', 'Credited', 'Gutgeschrieben', 'Crédité'],
            'down_payments.field.creditedGross' => ['Totale stornato (lordo)', 'Total credited (gross)', 'Total gutgeschrieben (brutto)', 'Total crédité (brut)'],
            'down_payments.field.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'down_payments.field.date' => ['Data', 'Date', 'Datum', 'Date'],
            'down_payments.field.debit' => ['Dare', 'Debit', 'Soll', 'Débit'],
            'down_payments.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'down_payments.field.document' => ['Documento', 'Document', 'Dokument', 'Document'],
            'down_payments.field.documentType' => ['Tipo di documento', 'Document type', 'Dokumenttyp', 'Type de document'],
            'down_payments.field.dueDate' => ['Scadenza', 'Due date', 'Fälligkeit', 'Échéance'],
            'down_payments.field.emitFirst' => ['Emetti subito la prima rata', 'Emit the first installment immediately', 'Erste Rate sofort ausstellen', 'Émettre tout de suite la première échéance'],
            'down_payments.field.gross' => ['Lordo', 'Gross', 'Brutto', 'Brut'],
            'down_payments.field.installments' => ['Rate', 'Installments', 'Raten', 'Échéances'],
            'down_payments.field.instrument' => ['Strumento', 'Instrument', 'Instrument', 'Instrument'],
            'down_payments.field.issueDate' => ['Data di emissione', 'Issue date', 'Ausstellungsdatum', 'Date d’émission'],
            'down_payments.field.milestone' => ['Tappa', 'Milestone', 'Meilenstein', 'Étape'],
            'down_payments.field.net' => ['Netto', 'Net', 'Netto', 'Net'],
            'down_payments.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'down_payments.field.openNet' => ['Netto residuo', 'Open net', 'Offener Nettobetrag', 'Net restant'],
            'down_payments.field.outOfVat' => ['Fuori campo IVA', 'Outside VAT', 'Ausserhalb MWST', 'Hors TVA'],
            'down_payments.field.paidAt' => ['Data di incasso', 'Payment date', 'Zahlungsdatum', 'Date d’encaissement'],
            'down_payments.field.paymentAccount' => ['Conto di regolamento', 'Settlement account', 'Abwicklungskonto', 'Compte de règlement'],
            'down_payments.field.paymentDate' => ['Data di incasso', 'Payment date', 'Zahlungsdatum', 'Date d’encaissement'],
            'down_payments.field.penalty' => ['Penale', 'Penalty', 'Konventionalstrafe', 'Pénalité'],
            'down_payments.field.percentage' => ['Percentuale', 'Percentage', 'Prozent', 'Pourcentage'],
            'down_payments.field.plannedGross' => ['Totale pianificato (lordo)', 'Planned total (gross)', 'Geplante Summe (brutto)', 'Total planifié (brut)'],
            'down_payments.field.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'down_payments.field.reason' => ['Motivo', 'Reason', 'Grund', 'Motif'],
            'down_payments.field.receiptNumber' => ['Numero ricevuta', 'Receipt number', 'Quittungsnummer', 'Numéro de reçu'],
            'down_payments.field.reference' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'down_payments.field.refundMode' => ['Tipo di restituzione', 'Refund type', 'Art der Rückerstattung', 'Type de remboursement'],
            'down_payments.field.residualAfter' => ['Residuo ordine dopo la nota di credito', 'Order residual after the credit note', 'Auftragsrest nach der Gutschrift', 'Solde de la commande après la note de crédit'],
            'down_payments.field.residualBefore' => ['Residuo ordine prima della nota di credito', 'Order residual before the credit note', 'Auftragsrest vor der Gutschrift', 'Solde de la commande avant la note de crédit'],
            'down_payments.field.resolution' => ['Risoluzione', 'Resolution', 'Erledigung', 'Résolution'],
            'down_payments.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'down_payments.field.settled' => ['Dedotto a saldo', 'Deducted at settlement', 'In Schlussrechnung abgezogen', 'Déduit au solde'],
            'down_payments.field.sliceSum' => ['Verifica al centesimo (somma delle quote = totale)', 'Cent-exact check (sum of the slices = total)', 'Centgenaue Prüfung (Summe der Anteile = Total)', 'Vérification au centime (somme des parts = total)'],
            'down_payments.field.source' => ['Origine', 'Source', 'Quelle', 'Origine'],
            'down_payments.field.stampApplied' => ['Imposta di bollo virtuale applicata', 'Virtual stamp duty applied', 'Virtuelle Stempelsteuer angewendet', 'Droit de timbre virtuel appliqué'],
            'down_payments.field.stampDuty' => ['Imposta di bollo', 'Stamp duty', 'Stempelsteuer', 'Droit de timbre'],
            'down_payments.field.stampNotDue' => ['Imposta di bollo non dovuta', 'Stamp duty not due', 'Stempelsteuer nicht geschuldet', 'Droit de timbre non dû'],
            'down_payments.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'down_payments.field.subtotalRate' => ['Totale aliquota {rate}%', 'Subtotal at {rate}%', 'Zwischensumme zu {rate}%', 'Sous-total au taux de {rate}%'],
            'down_payments.field.trigger' => ['Evento', 'Trigger', 'Auslöser', 'Déclencheur'],
            'down_payments.field.type' => ['Tipo', 'Type', 'Typ', 'Type'],
            'down_payments.field.unitPrice' => ['Prezzo unitario', 'Unit price', 'Einzelpreis', 'Prix unitaire'],
            'down_payments.field.vat' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'down_payments.field.vatExemption' => ['Codice esenzione IVA', 'VAT exemption code', 'MWST-Befreiungscode', 'Code d’exonération TVA'],
            'down_payments.field.vatRate' => ['Aliquota IVA', 'VAT rate', 'MWST-Satz', 'Taux de TVA'],
            'down_payments.field.verification' => ['Verifica', 'Check', 'Prüfung', 'Vérification'],
            'down_payments.field.withVat' => ['Con IVA', 'With VAT', 'Mit MWST', 'Avec TVA'],

            'down_payments.kpi.advances' => ['Acconti fatturati', 'Invoiced advances', 'Fakturierte Anzahlungen', 'Acomptes facturés'],
            'down_payments.kpi.contract' => ['Totale contratto', 'Contract total', 'Vertragssumme', 'Total du contrat'],
            'down_payments.kpi.credited' => ['Acconti stornati', 'Credited advances', 'Gutgeschriebene Anzahlungen', 'Acomptes crédités'],
            'down_payments.kpi.deposits' => ['Depositi e caparre trattenuti', 'Deposits held', 'Gehaltene Depots', 'Dépôts détenus'],
            'down_payments.kpi.residual' => ['Residuo da fatturare', 'Residual to invoice', 'Restbetrag zur Verrechnung', 'Solde à facturer'],
            'down_payments.legend.advances' => ['Acconti fatturati', 'Invoiced advances', 'Fakturierte Anzahlungen', 'Acomptes facturés'],
            'down_payments.legend.deposits' => ['Depositi trattenuti', 'Deposits held', 'Gehaltene Depots', 'Dépôts détenus'],
            'down_payments.legend.residual' => ['Residuo', 'Residual', 'Rest', 'Solde'],

            'down_payments.matrix.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'down_payments.matrix.requested' => ['Richiesto', 'Requested', 'Angefordert', 'Demandé'],
            'down_payments.matrix.invoiced' => ['Fatturato', 'Invoiced', 'Fakturiert', 'Facturé'],
            'down_payments.matrix.credited' => ['Stornato', 'Credited', 'Gutgeschrieben', 'Crédité'],
            'down_payments.matrix.settled' => ['Dedotto a saldo', 'Deducted at settlement', 'In Schlussrechnung abgezogen', 'Déduit au solde'],
            'down_payments.matrix.residual' => ['Residuo', 'Residual', 'Rest', 'Solde'],
            'down_payments.matrix.net' => ['Netto', 'Net', 'Netto', 'Net'],
            'down_payments.matrix.vat' => ['IVA', 'VAT', 'MWST', 'TVA'],
            'down_payments.matrix.gross' => ['Lordo', 'Gross', 'Brutto', 'Brut'],

            'down_payments.totals.supplyNet' => ['Fornitura (netto)', 'Supply (net)', 'Lieferung (netto)', 'Fourniture (net)'],
            'down_payments.totals.supplyVat' => ['Fornitura (IVA)', 'Supply (VAT)', 'Lieferung (MWST)', 'Fourniture (TVA)'],
            'down_payments.totals.deductedNet' => ['Acconti dedotti (netto)', 'Advances deducted (net)', 'Abgezogene Anzahlungen (netto)', 'Acomptes déduits (net)'],
            'down_payments.totals.deductedVat' => ['Acconti dedotti (IVA)', 'Advances deducted (VAT)', 'Abgezogene Anzahlungen (MWST)', 'Acomptes déduits (TVA)'],
            'down_payments.totals.finalNet' => ['Saldo (netto)', 'Balance (net)', 'Saldo (netto)', 'Solde (net)'],
            'down_payments.totals.finalVat' => ['Saldo (IVA)', 'Balance (VAT)', 'Saldo (MWST)', 'Solde (TVA)'],
            'down_payments.totals.finalGross' => ['Totale fattura di saldo', 'Final invoice total', 'Total der Schlussrechnung', 'Total de la facture de solde'],
            'down_payments.totals.vatRoundingDifference' => ['Differenza di arrotondamento IVA', 'VAT rounding difference', 'MWST-Rundungsdifferenz', 'Écart d’arrondi de TVA'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'down_payments.action.addMilestone' => ['Aggiungi tappa', 'Add milestone', 'Meilenstein hinzufügen', 'Ajouter une étape'],
            'down_payments.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'down_payments.action.cancelChain' => ['Annulla catena', 'Cancel chain', 'Kette stornieren', 'Annuler la chaîne'],
            'down_payments.action.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'down_payments.action.confirmPayment' => ['Conferma incasso', 'Confirm payment', 'Zahlung bestätigen', 'Confirmer l’encaissement'],
            'down_payments.action.creditNote' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Note de crédit'],
            'down_payments.action.emit' => ['Emetti', 'Emit', 'Ausstellen', 'Émettre'],
            'down_payments.action.finalSettlement' => ['Fattura di saldo', 'Final settlement', 'Schlussabrechnung', 'Facture de solde'],
            'down_payments.action.generateSettlement' => ['Genera fattura di saldo', 'Generate final settlement invoice', 'Schlussrechnung erstellen', 'Générer la facture de solde'],
            'down_payments.action.issueCreditNote' => ['Emetti nota di credito (TD04)', 'Issue credit note (TD04)', 'Gutschrift ausstellen (TD04)', 'Émettre la note de crédit (TD04)'],
            'down_payments.action.manageDeposit' => ['Gestisci', 'Manage', 'Verwalten', 'Gérer'],
            'down_payments.action.next' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'down_payments.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'down_payments.action.openChain' => ['Apri catena di acconti', 'Open down-payment chain', 'Anzahlungskette eröffnen', 'Ouvrir la chaîne d’acomptes'],
            'down_payments.action.refresh' => ['Aggiorna', 'Refresh', 'Aktualisieren', 'Actualiser'],
            'down_payments.action.removeMilestone' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'down_payments.action.resolve' => ['Risolvi deposito', 'Resolve deposit', 'Depot abschliessen', 'Solder le dépôt'],
            'down_payments.action.viewReceipt' => ['Ricevuta', 'Receipt', 'Quittung', 'Reçu'],

            'down_payments.confirm.cancelChain' => ['Annullare la catena? Non è stato emesso alcun documento.', 'Cancel the chain? No document has been issued.', 'Die Kette stornieren? Es wurde kein Dokument ausgestellt.', 'Annuler la chaîne ? Aucun document n’a été émis.'],
            'down_payments.confirm.emit' => ['Emettere questa rata?', 'Emit this installment?', 'Diese Rate ausstellen?', 'Émettre cette échéance ?'],

            'down_payments.modal.confirmProforma' => ['Conferma incasso della pro-forma', 'Confirm the payment of the pro-forma', 'Zahlung der Pro-forma bestätigen', 'Confirmer l’encaissement de la pro forma'],
            'down_payments.modal.creditNote' => ['Nota di credito (TD04) su fattura di acconto', 'Credit note (TD04) on an advance invoice', 'Gutschrift (TD04) auf eine Anzahlungsrechnung', 'Note de crédit (TD04) sur une facture d’acompte'],
            'down_payments.modal.deposit' => ['Ricevuta e risoluzione del deposito', 'Deposit receipt and resolution', 'Quittung und Abschluss des Depots', 'Reçu et résolution du dépôt'],
            'down_payments.modal.settlement' => ['Anteprima della fattura di saldo', 'Final settlement invoice preview', 'Vorschau der Schlussrechnung', 'Aperçu de la facture de solde'],
            'down_payments.modal.wizard' => ['Apri la catena di acconti', 'Open the down-payment chain', 'Anzahlungskette eröffnen', 'Ouvrir la chaîne d’acomptes'],

            'down_payments.step.plan' => ['Piano', 'Plan', 'Plan', 'Plan'],
            'down_payments.step.preview' => ['Anteprima IVA', 'VAT preview', 'MWST-Vorschau', 'Aperçu TVA'],
            'down_payments.step.confirm' => ['Conferma', 'Confirmation', 'Bestätigung', 'Confirmation'],

            'down_payments.check.exact' => ['Esatto al centesimo', 'Cent-exact', 'Centgenau', 'Exact au centime'],
            'down_payments.check.mismatch' => ['Differenza di arrotondamento', 'Rounding difference', 'Rundungsdifferenz', 'Écart d’arrondi'],
            'down_payments.check.noSplit' => ['Fuori campo IVA, nessuna ripartizione', 'Outside VAT, no split', 'Ausserhalb MWST, keine Aufteilung', 'Hors TVA, sans répartition'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function sections(): array
    {
        return [
            'down_payments.section.accountingImpact' => ['Impatto contabile (Prima Nota)', 'Accounting impact (journal entry)', 'Buchhalterische Auswirkung (Buchungssatz)', 'Impact comptable (écriture)'],
            'down_payments.section.confirmPayment' => ['Conferma dell’incasso', 'Payment confirmation', 'Zahlungsbestätigung', 'Confirmation de l’encaissement'],
            'down_payments.section.creditMode' => ['Modalità della nota di credito', 'Credit note mode', 'Art der Gutschrift', 'Mode de la note de crédit'],
            'down_payments.section.creditPreview' => ['Anteprima della nota di credito', 'Credit note preview', 'Vorschau der Gutschrift', 'Aperçu de la note de crédit'],
            'down_payments.section.deductions' => ['Acconti dedotti (per aliquota IVA e fattura)', 'Deducted advances (per VAT rate and invoice)', 'Abgezogene Anzahlungen (pro MWST-Satz und Rechnung)', 'Acomptes déduits (par taux de TVA et facture)'],
            'down_payments.section.deposits' => ['Depositi e caparre', 'Deposits and earnest money', 'Depots und Draufgaben', 'Dépôts et arrhes'],
            'down_payments.section.installments' => ['Piano delle rate', 'Installment plan', 'Ratenplan', 'Plan d’échéances'],
            'down_payments.section.journalClosure' => ['Prima Nota: chiusura Clienti c/anticipi', 'Journal entry: closing of customer advances', 'Buchungssatz: Auflösung der Kundenanzahlungen', 'Écriture : clôture des acomptes clients'],
            'down_payments.section.linkedDocuments' => ['DatiFattureCollegate (riferimenti XML)', 'DatiFattureCollegate (XML references)', 'DatiFattureCollegate (XML-Verweise)', 'DatiFattureCollegate (références XML)'],
            'down_payments.section.links' => ['Documenti collegati', 'Linked documents', 'Verknüpfte Dokumente', 'Documents liés'],
            'down_payments.section.matrix' => ['Matrice analitica multi-aliquota IVA', 'Multi-VAT analytical matrix', 'Analytische Multi-MWST-Matrix', 'Matrice analytique multi-taux de TVA'],
            'down_payments.section.progress' => ['Avanzamento: contratto, depositi, acconti e residuo', 'Progress: contract, deposits, advances and residual', 'Fortschritt: Vertrag, Depots, Anzahlungen und Rest', 'Avancement : contrat, dépôts, acomptes et solde'],
            'down_payments.section.resolution' => ['Come si chiude il deposito', 'How the deposit ends', 'Wie das Depot abgeschlossen wird', 'Comment le dépôt est soldé'],
            'down_payments.section.supplyLines' => ['Righe di fornitura', 'Supply lines', 'Lieferpositionen', 'Lignes de fourniture'],
            'down_payments.section.timeline' => ['Cronologia dei documenti', 'Document timeline', 'Dokumentenverlauf', 'Chronologie des documents'],

            'down_payments.tab.title' => ['Acconti e caparre', 'Down payments', 'Anzahlungen', 'Acomptes et arrhes'],
            'down_payments.list.subtitle' => ['Catene di acconti, caparre e depositi cauzionali di ordini, preventivi e contratti, fino alla fattura di saldo.', 'Down-payment chains, earnest money and security deposits of orders, quotes and contracts, up to the final settlement invoice.', 'Anzahlungsketten, Draufgaben und Kautionen von Aufträgen, Offerten und Verträgen bis zur Schlussrechnung.', 'Chaînes d’acomptes, arrhes et dépôts de garantie des commandes, devis et contrats, jusqu’à la facture de solde.'],
            'down_payments.detail.subtitle' => ['Piano delle rate, documenti collegati, depositi e matrice IVA della catena.', 'Installment plan, linked documents, deposits and VAT matrix of the chain.', 'Ratenplan, verknüpfte Dokumente, Depots und MWST-Matrix der Kette.', 'Plan d’échéances, documents liés, dépôts et matrice de TVA de la chaîne.'],

            'down_payments.empty.chains' => ['Nessuna catena di acconti.', 'No down-payment chains.', 'Keine Anzahlungsketten.', 'Aucune chaîne d’acomptes.'],
            'down_payments.empty.deposits' => ['Nessun deposito o caparra.', 'No deposits or earnest money.', 'Keine Depots oder Draufgaben.', 'Aucun dépôt ni arrhes.'],
            'down_payments.empty.links' => ['Nessun documento emesso.', 'No document issued yet.', 'Noch kein Dokument ausgestellt.', 'Aucun document émis.'],
            'down_payments.empty.matrix' => ['Nessuna aliquota IVA da mostrare.', 'No VAT rate to show.', 'Kein MWST-Satz anzuzeigen.', 'Aucun taux de TVA à afficher.'],
            'down_payments.empty.noChain' => ['Questo documento non ha ancora una catena di acconti.', 'This document has no down-payment chain yet.', 'Dieses Dokument hat noch keine Anzahlungskette.', 'Ce document n’a pas encore de chaîne d’acomptes.'],

            'down_payments.notice.itTitle' => ['Acconti e caparre in Italia', 'Down payments and earnest money in Italy', 'Anzahlungen und Draufgaben in Italien', 'Acomptes et arrhes en Italie'],
            'down_payments.notice.itBody' => ['Caparre e depositi non sono corrispettivi (Art. 15 DPR 633/72): la ricevuta è fuori campo IVA (N1) e, oltre EUR 77,47, porta l’imposta di bollo di EUR 2,00 assolta in modo virtuale. L’acconto sul prezzo è fatturato con IVA (TD02) e dedotto dalla fattura di saldo.', 'Earnest money and deposits are not consideration (Art. 15 DPR 633/72): the receipt is outside VAT (N1) and, above EUR 77.47, carries the imposta di bollo of EUR 2.00, paid virtually. An advance on the price is invoiced with VAT (TD02) and deducted from the final invoice.', 'Draufgaben und Depots sind kein Entgelt (Art. 15 DPR 633/72): Die Quittung liegt ausserhalb der MWST (N1) und trägt über EUR 77.47 die imposta di bollo von EUR 2.00, virtuell entrichtet. Eine Anzahlung auf den Preis wird mit MWST fakturiert (TD02) und von der Schlussrechnung abgezogen.', 'Les arrhes et dépôts ne sont pas une contrepartie (Art. 15 DPR 633/72) : le reçu est hors TVA (N1) et, au-delà de EUR 77,47, porte l’imposta di bollo de EUR 2,00, acquittée de manière virtuelle. Un acompte sur le prix est facturé avec TVA (TD02) et déduit de la facture de solde.'],
            'down_payments.notice.chTitle' => ['Acconti e caparre in Svizzera', 'Down payments and earnest money in Switzerland', 'Anzahlungen und Draufgaben in der Schweiz', 'Acomptes et arrhes en Suisse'],
            'down_payments.notice.chBody' => ['Secondo l’Art. 158 CO la caparra non è una controprestazione: resta fuori campo MWST finché non è imputata al prezzo. Solo l’acconto sul prezzo è soggetto a MWST e viene dedotto dalla fattura di saldo.', 'Under Art. 158 CO the earnest money is not consideration: it stays outside MWST until it is imputed to the price. Only an advance on the price is subject to MWST and is deducted from the final invoice.', 'Nach Art. 158 OR ist die Draufgabe kein Entgelt: Sie bleibt ausserhalb der MWST, bis sie auf den Preis angerechnet wird. Nur die Anzahlung auf den Preis unterliegt der MWST und wird von der Schlussrechnung abgezogen.', 'Selon l’art. 158 CO, les arrhes ne sont pas une contre-prestation : elles restent hors TVA tant qu’elles ne sont pas imputées au prix. Seul l’acompte sur le prix est soumis à la TVA et déduit de la facture de solde.'],

            'down_payments.blockers.title' => ['Il saldo è bloccato', 'The settlement is blocked', 'Die Schlussabrechnung ist blockiert', 'Le solde est bloqué'],
            'down_payments.blockers.open_proforma' => ['Ci sono richieste pro-forma ancora aperte: convertile o annullale prima del saldo.', 'Pro-forma requests are still open: convert or cancel them before the settlement.', 'Es sind noch Pro-forma-Anforderungen offen: Wandeln Sie sie um oder stornieren Sie sie vor der Schlussabrechnung.', 'Des demandes pro forma sont encore ouvertes : convertissez-les ou annulez-les avant le solde.'],
            'down_payments.blockers.held_earnest_money' => ['La caparra è ancora trattenuta: va imputata al prezzo, incamerata o restituita prima del saldo.', 'Earnest money is still held: impute it to the price, forfeit it or refund it before the settlement.', 'Die Draufgabe wird noch gehalten: Sie muss vor der Schlussabrechnung auf den Preis angerechnet, einbehalten oder zurückerstattet werden.', 'Les arrhes sont encore détenues : imputez-les au prix, acquérez-les ou remboursez-les avant le solde.'],
            'down_payments.blockers.imputeEarnest' => ['Imputa al prezzo la caparra {receipt} ({amount}) in questa fattura', 'Impute earnest money {receipt} ({amount}) to the price in this invoice', 'Draufgabe {receipt} ({amount}) in dieser Rechnung auf den Preis anrechnen', 'Imputer au prix les arrhes {receipt} ({amount}) dans cette facture'],
            'down_payments.blockers.unpaidEarnest' => ['La caparra {receipt} non è ancora incassata: conferma l’incasso e risolvila prima del saldo.', 'Earnest money {receipt} is not paid yet: confirm the payment and resolve it before the settlement.', 'Die Draufgabe {receipt} ist noch nicht bezahlt: Bestätigen Sie die Zahlung und schliessen Sie sie vor der Schlussabrechnung ab.', 'Les arrhes {receipt} ne sont pas encore encaissées : confirmez l’encaissement et soldez-les avant le solde.'],

            'down_payments.warning.roundingDrift' => ['Differenza di arrotondamento nel piano: {drift}', 'Rounding difference in the plan: {drift}', 'Rundungsdifferenz im Plan: {drift}', 'Écart d’arrondi dans le plan : {drift}'],
            'down_payments.warning.roundingDriftHint' => ['La somma delle quote per aliquota IVA non coincide al centesimo con la rata. Modifica gli importi prima di aprire la catena.', 'The sum of the slices per VAT rate does not match the installment to the cent. Adjust the amounts before opening the chain.', 'Die Summe der Anteile pro MWST-Satz stimmt nicht centgenau mit der Rate überein. Passen Sie die Beträge an, bevor Sie die Kette eröffnen.', 'La somme des parts par taux de TVA ne correspond pas au centime à l’échéance. Ajustez les montants avant d’ouvrir la chaîne.'],

            'down_payments.hint.wizardPlan' => ['Scegli come ripartire l’importo di {source}: un piano predefinito o tappe personalizzate. L’IVA è ripartita in proporzione alle aliquote del documento.', 'Choose how to split the amount of {source}: a preset plan or custom milestones. VAT is split in proportion to the rates of the document.', 'Wählen Sie, wie der Betrag von {source} aufgeteilt wird: ein vordefinierter Plan oder eigene Meilensteine. Die MWST wird im Verhältnis zu den Sätzen des Dokuments aufgeteilt.', 'Choisissez comment répartir le montant de {source} : un plan prédéfini ou des étapes personnalisées. La TVA est répartie proportionnellement aux taux du document.'],
            'down_payments.hint.customPlan' => ['Percentuale o importo fisso per ogni tappa, con evento, scadenza e strumento.', 'Percentage or fixed amount for each milestone, with trigger, due date and instrument.', 'Prozent oder Festbetrag pro Meilenstein, mit Auslöser, Fälligkeit und Instrument.', 'Pourcentage ou montant fixe pour chaque étape, avec déclencheur, échéance et instrument.'],
            'down_payments.hint.confirmDeposit' => ['Registra l’incasso della ricevuta: il deposito risulta trattenuto e il debito verso il cliente è contabilizzato.', 'Record the payment of the receipt: the deposit is held and the liability towards the customer is posted.', 'Erfassen Sie die Zahlung der Quittung: Das Depot gilt als gehalten und die Verbindlichkeit gegenüber dem Kunden wird gebucht.', 'Enregistrez l’encaissement du reçu : le dépôt est détenu et la dette envers le client est comptabilisée.'],
            'down_payments.hint.confirmProforma' => ['L’incasso trasforma la richiesta pro-forma nella fattura di acconto TD02, datata nel giorno dell’incasso.', 'The payment turns the pro-forma request into the TD02 advance invoice, dated on the payment day.', 'Die Zahlung wandelt die Pro-forma-Anforderung in die Anzahlungsrechnung TD02 um, datiert auf den Zahlungstag.', 'L’encaissement transforme la demande pro forma en facture d’acompte TD02, datée du jour de l’encaissement.'],
            'down_payments.hint.resolution' => ['Scegli come si chiude la caparra o il deposito: l’anteprima mostra la scrittura contabile prima di registrarla.', 'Choose how the earnest money or deposit ends: the preview shows the journal entry before it is posted.', 'Wählen Sie, wie die Draufgabe oder das Depot abgeschlossen wird: Die Vorschau zeigt den Buchungssatz vor der Buchung.', 'Choisissez comment les arrhes ou le dépôt sont soldés : l’aperçu montre l’écriture avant sa comptabilisation.'],
            'down_payments.hint.doubleOnlyEarnest' => ['La restituzione al doppio vale solo per la caparra confirmatoria.', 'The double refund applies to earnest money only.', 'Die doppelte Rückerstattung gilt nur für die Draufgabe.', 'Le remboursement au double ne vaut que pour les arrhes confirmatoires.'],
            'down_payments.hint.noEntry' => ['Nessuna scrittura immediata: l’acconto è fatturato con la fattura di acconto.', 'No immediate entry: the advance is invoiced with the advance invoice.', 'Kein sofortiger Buchungssatz: Die Anzahlung wird mit der Anzahlungsrechnung fakturiert.', 'Aucune écriture immédiate : l’acompte est facturé avec la facture d’acompte.'],
            'down_payments.hint.linkedDocuments' => ['Riferimenti alle fatture di acconto che la fattura di saldo porta nell’XML.', 'References to the advance invoices that the final invoice carries in the XML.', 'Verweise auf die Anzahlungsrechnungen, die die Schlussrechnung im XML trägt.', 'Références aux factures d’acompte que la facture de solde porte dans le XML.'],
            'down_payments.hint.journalClosure' => ['La fattura di saldo chiude il conto Clienti c/anticipi per gli acconti dedotti.', 'The final invoice closes the customer advances account for the deducted advances.', 'Die Schlussrechnung löst das Konto Kundenanzahlungen für die abgezogenen Anzahlungen auf.', 'La facture de solde clôture le compte acomptes clients pour les acomptes déduits.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'down_payments.message.cancelled' => ['Catena di acconti annullata.', 'Down-payment chain cancelled.', 'Anzahlungskette storniert.', 'Chaîne d’acomptes annulée.'],
            'down_payments.message.created' => ['Catena di acconti aperta.', 'Down-payment chain opened.', 'Anzahlungskette eröffnet.', 'Chaîne d’acomptes ouverte.'],
            'down_payments.message.createdEmitFailed' => ['Catena aperta, ma la prima rata non è stata emessa: emettila dal piano.', 'Chain opened, but the first installment was not emitted: emit it from the plan.', 'Kette eröffnet, die erste Rate wurde jedoch nicht ausgestellt: Stellen Sie sie im Plan aus.', 'Chaîne ouverte, mais la première échéance n’a pas été émise : émettez-la depuis le plan.'],
            'down_payments.message.createdEmitted' => ['Catena aperta e prima rata emessa.', 'Chain opened and first installment emitted.', 'Kette eröffnet und erste Rate ausgestellt.', 'Chaîne ouverte et première échéance émise.'],
            'down_payments.message.creditNoteIssued' => ['Nota di credito emessa e residuo dell’ordine riaperto.', 'Credit note issued and order residual reopened.', 'Gutschrift ausgestellt und Auftragsrest wieder geöffnet.', 'Note de crédit émise et solde de la commande rouvert.'],
            'down_payments.message.depositCollected' => ['Incasso del deposito registrato.', 'Deposit payment recorded.', 'Zahlung des Depots erfasst.', 'Encaissement du dépôt enregistré.'],
            'down_payments.message.depositResolved' => ['Deposito risolto.', 'Deposit resolved.', 'Depot abgeschlossen.', 'Dépôt soldé.'],
            'down_payments.message.emitted' => ['Rata emessa.', 'Installment emitted.', 'Rate ausgestellt.', 'Échéance émise.'],
            'down_payments.message.proformaConverted' => ['Pro-forma convertita nella fattura di acconto.', 'Pro-forma converted into the advance invoice.', 'Pro-forma in die Anzahlungsrechnung umgewandelt.', 'Pro forma convertie en facture d’acompte.'],
            'down_payments.message.settled' => ['Fattura di saldo emessa.', 'Final settlement invoice issued.', 'Schlussrechnung ausgestellt.', 'Facture de solde émise.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dictionary(string $locale): array
    {
        /** @var array<string, mixed> $dictionary */
        $dictionary = require lang_path($locale.'/down_payments.php');

        return $dictionary;
    }

    /**
     * One text of the backend dictionary in the four languages.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function backend(string $path): array
    {
        $texts = array_map(static function (string $locale) use ($path): string {
            $value = self::dictionary($locale);

            foreach (explode('.', $path, 2) as $segment) {
                $value = $value[$segment] ?? null;
            }

            return (string) $value;
        }, self::Locales);

        return [$texts[0], $texts[1], $texts[2], $texts[3]];
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
