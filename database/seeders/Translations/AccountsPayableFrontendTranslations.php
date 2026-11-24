<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the accounts payable module (Mod 015): the supplier ledger, disputes and blocks, the cash discount (Skonto),
 * the payment runs with the CFO approval, the payables aging, plus the codes the API reports (accountsPayable.error.<code>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Placeholders are written {name}.
 */
final class AccountsPayableFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.ACCOUNTS_PAYABLE' => ['Contabilità fornitori', 'Accounts payable', 'Kreditorenbuchhaltung', 'Comptabilité fournisseurs'],
            'NAV.ACCOUNTS_PAYABLE_OPEN_ITEMS' => ['Partitario fornitori', 'Supplier ledger', 'Lieferantenposten', 'Partiaire fournisseurs'],
            'NAV.ACCOUNTS_PAYABLE_PAYMENT_RUNS' => ['Distinte di pagamento', 'Payment runs', 'Zahlungsläufe', 'Bordereaux de paiement'],
            'NAV.ACCOUNTS_PAYABLE_AGING' => ['Aging fornitori', 'Payables aging', 'Kreditoren-Fälligkeitsanalyse', 'Balance âgée fournisseurs'],
            'NAV.ACCOUNTS_PAYABLE_TERMS' => ['Sconto cassa fornitori', 'Supplier cash discounts', 'Lieferantenskonto', 'Escompte fournisseurs'],
            'PAGE_TITLE.ACCOUNTS_PAYABLE_OPEN_ITEMS' => ['Partite aperte fornitori e scadenzario passivo', 'Supplier open items and payment schedule', 'Offene Lieferantenposten und Zahlungsplan', 'Parties ouvertes fournisseurs et échéancier'],
            'PAGE_TITLE.ACCOUNTS_PAYABLE_PAYMENT_RUNS' => ['Distinte di pagamento fornitori', 'Supplier payment runs', 'Lieferanten-Zahlungsläufe', 'Bordereaux de paiement fournisseurs'],
            'PAGE_TITLE.ACCOUNTS_PAYABLE_AGING' => ['Aging fornitori', 'Payables aging', 'Kreditoren-Fälligkeitsanalyse', 'Balance âgée fournisseurs'],
            'PAGE_TITLE.ACCOUNTS_PAYABLE_TERMS' => ['Accordi di sconto cassa', 'Cash-discount agreements', 'Skontovereinbarungen', 'Accords d’escompte'],

            'accountsPayable.status.open' => ['Aperta', 'Open', 'Offen', 'Ouverte'],
            'accountsPayable.status.in_proposal' => ['In distinta', 'In payment run', 'Im Zahlungslauf', 'Dans un bordereau'],
            'accountsPayable.status.authorized' => ['Autorizzata', 'Authorized', 'Freigegeben', 'Autorisée'],
            'accountsPayable.status.paid' => ['Pagata', 'Paid', 'Bezahlt', 'Payée'],
            'accountsPayable.status.disputed' => ['In contestazione', 'In dispute', 'Beanstandet', 'Contestée'],
            'accountsPayable.status.blocked' => ['Bloccata', 'Blocked', 'Gesperrt', 'Bloquée'],
            'accountsPayable.kind.invoice' => ['Fattura', 'Invoice', 'Rechnung', 'Facture'],
            'accountsPayable.kind.credit_note' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Avoir'],
            'accountsPayable.source.electronic_xml' => ['Fattura elettronica XML', 'Electronic XML invoice', 'Elektronische XML-Rechnung', 'Facture électronique XML'],
            'accountsPayable.source.swiss_qr' => ['QR-fattura svizzera', 'Swiss QR bill', 'Schweizer QR-Rechnung', 'Facture QR suisse'],
            'accountsPayable.source.manual' => ['Inserimento manuale', 'Manual entry', 'Manuelle Erfassung', 'Saisie manuelle'],
            'accountsPayable.action.dispute' => ['Metti in contestazione', 'Put in dispute', 'Beanstanden', 'Contester'],
            'accountsPayable.action.block' => ['Blocca amministrativamente', 'Block administratively', 'Administrativ sperren', 'Bloquer administrativement'],
            'accountsPayable.action.release' => ['Sblocca', 'Release', 'Freigeben', 'Débloquer'],
            'accountsPayable.paymentBlock.disputed' => ['In contestazione', 'In dispute', 'Beanstandet', 'Contestée'],
            'accountsPayable.paymentBlock.administrative_block' => ['Blocco amministrativo', 'Administrative block', 'Administrative Sperre', 'Blocage administratif'],
            'accountsPayable.paymentBlock.invoice_not_approved' => ['Fattura non approvata al pagamento', 'Invoice not approved for payment', 'Rechnung nicht zur Zahlung freigegeben', 'Facture non approuvée pour paiement'],
            'accountsPayable.paymentBlock.in_payment_run' => ['Già in una distinta', 'Already in a payment run', 'Bereits in einem Zahlungslauf', 'Déjà dans un bordereau'],
            'accountsPayable.paymentBlock.settled' => ['Saldata', 'Settled', 'Ausgeglichen', 'Soldée'],

            'accountsPayable.skonto.eligible' => ['Sconto cassa disponibile', 'Cash discount available', 'Skonto verfügbar', 'Escompte disponible'],
            'accountsPayable.skonto.percentage' => ['Sconto cassa %', 'Cash discount %', 'Skonto %', 'Escompte %'],
            'accountsPayable.skonto.deadline' => ['Scadenza sconto', 'Discount deadline', 'Skontofrist', 'Échéance de l’escompte'],
            'accountsPayable.skonto.daysRemaining' => ['{days} giorni allo sconto', '{days} days left for the discount', 'Noch {days} Tage für das Skonto', '{days} jours pour l’escompte'],
            'accountsPayable.skonto.savings' => ['Risparmio', 'Savings', 'Ersparnis', 'Économie'],
            'accountsPayable.skonto.netPayable' => ['Netto da pagare con sconto', 'Net payable with discount', 'Netto zu zahlen mit Skonto', 'Net à payer avec escompte'],
            'accountsPayable.skonto.source.document' => ['Condizioni del documento', 'Document terms', 'Belegkonditionen', 'Conditions du document'],
            'accountsPayable.skonto.source.supplier_agreement' => ['Accordo con il fornitore', 'Supplier agreement', 'Lieferantenvereinbarung', 'Accord fournisseur'],
            'accountsPayable.skonto.source.payment_term' => ['Condizione di pagamento del fornitore', 'Supplier payment term', 'Zahlungsbedingung des Lieferanten', 'Condition de paiement du fournisseur'],

            'accountsPayable.run.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'accountsPayable.run.status.pending_cfo_approval' => ['In attesa del CFO', 'Awaiting CFO approval', 'Wartet auf CFO-Freigabe', 'En attente du CFO'],
            'accountsPayable.run.status.authorized' => ['Autorizzata', 'Authorized', 'Freigegeben', 'Autorisé'],
            'accountsPayable.run.status.rejected' => ['Rifiutata', 'Rejected', 'Abgelehnt', 'Refusé'],
            'accountsPayable.run.status.executed' => ['Eseguita', 'Executed', 'Ausgeführt', 'Exécuté'],
            'accountsPayable.run.status.cancelled' => ['Annullata', 'Cancelled', 'Storniert', 'Annulé'],
            'accountsPayable.run.reason.due' => ['In scadenza', 'Due', 'Fällig', 'Échu'],
            'accountsPayable.run.reason.skonto' => ['Sconto cassa', 'Cash discount', 'Skonto', 'Escompte'],
            'accountsPayable.run.reason.credit_note' => ['Nota di credito compensata', 'Credit note offset', 'Verrechnete Gutschrift', 'Avoir compensé'],
            'accountsPayable.run.excluded.disputed' => ['Esclusa: in contestazione', 'Excluded: in dispute', 'Ausgeschlossen: beanstandet', 'Exclue : contestée'],
            'accountsPayable.run.excluded.administrative_block' => ['Esclusa: blocco amministrativo', 'Excluded: administrative block', 'Ausgeschlossen: administrative Sperre', 'Exclue : blocage administratif'],
            'accountsPayable.run.excluded.invoice_not_approved' => ['Esclusa: fattura non approvata', 'Excluded: invoice not approved', 'Ausgeschlossen: Rechnung nicht freigegeben', 'Exclue : facture non approuvée'],
            'accountsPayable.run.excluded.missing_iban' => ['Esclusa: IBAN del creditore mancante', 'Excluded: creditor IBAN missing', 'Ausgeschlossen: IBAN des Gläubigers fehlt', 'Exclue : IBAN du créancier manquant'],
            'accountsPayable.run.action.submit' => ['Invia al CFO', 'Send to CFO', 'An CFO senden', 'Envoyer au CFO'],
            'accountsPayable.run.action.recall' => ['Richiama', 'Recall', 'Zurückrufen', 'Rappeler'],
            'accountsPayable.run.action.approve' => ['Autorizza', 'Authorize', 'Freigeben', 'Autoriser'],
            'accountsPayable.run.action.reject' => ['Rifiuta', 'Reject', 'Ablehnen', 'Refuser'],
            'accountsPayable.run.action.execute' => ['Esegui pagamenti', 'Execute payments', 'Zahlungen ausführen', 'Exécuter les paiements'],
            'accountsPayable.run.action.cancel' => ['Annulla distinta', 'Cancel run', 'Lauf stornieren', 'Annuler le bordereau'],
            'accountsPayable.run.totals.debit' => ['Fatture', 'Invoices', 'Rechnungen', 'Factures'],
            'accountsPayable.run.totals.creditNetted' => ['Note di credito compensate', 'Credit notes offset', 'Verrechnete Gutschriften', 'Avoirs compensés'],
            'accountsPayable.run.totals.skonto' => ['Sconto cassa', 'Cash discount', 'Skonto', 'Escompte'],
            'accountsPayable.run.totals.net' => ['Da bonificare', 'To transfer', 'Zu überweisen', 'À virer'],

            'accountsPayable.aging.bucket.current' => ['Non ancora scaduto', 'Not yet due', 'Noch nicht fällig', 'Pas encore échu'],
            'accountsPayable.aging.bucket.1_30' => ['Scaduto 1-30 gg', '1-30 days overdue', '1-30 Tage überfällig', 'Échu 1-30 jours'],
            'accountsPayable.aging.bucket.31_60' => ['Scaduto 31-60 gg', '31-60 days overdue', '31-60 Tage überfällig', 'Échu 31-60 jours'],
            'accountsPayable.aging.bucket.61_90' => ['Scaduto 61-90 gg', '61-90 days overdue', '61-90 Tage überfällig', 'Échu 61-90 jours'],
            'accountsPayable.aging.bucket.over_90' => ['Scaduto oltre 90 gg', 'Over 90 days overdue', 'Über 90 Tage überfällig', 'Échu de plus de 90 jours'],
            'accountsPayable.aging.exportXlsx' => ['Esporta in Excel', 'Export to Excel', 'Nach Excel exportieren', 'Exporter vers Excel'],
            'accountsPayable.aging.exportPdf' => ['Esporta in PDF', 'Export to PDF', 'Als PDF exportieren', 'Exporter en PDF'],

            'accountsPayable.error.open_item_not_found' => ['La partita aperta non esiste', 'The open item does not exist', 'Der offene Posten existiert nicht', 'La partie ouverte n’existe pas'],
            'accountsPayable.error.open_item_already_held' => ['La partita è già {status}', 'The open item is already {status}', 'Der offene Posten ist bereits {status}', 'La partie ouverte est déjà {status}'],
            'accountsPayable.error.open_item_settled' => ['La partita è già saldata', 'The open item is already settled', 'Der offene Posten ist bereits ausgeglichen', 'La partie ouverte est déjà soldée'],
            'accountsPayable.error.open_item_not_held' => ['La partita non è bloccata', 'The open item is not held', 'Der offene Posten ist nicht gesperrt', 'La partie ouverte n’est pas bloquée'],
            'accountsPayable.error.hold_type_invalid' => ['Tipo di blocco non valido', 'Invalid hold type', 'Ungültige Sperrart', 'Type de blocage invalide'],
            'accountsPayable.error.supplier_term_not_found' => ['L’accordo di sconto cassa non esiste', 'The cash-discount agreement does not exist', 'Die Skontovereinbarung existiert nicht', 'L’accord d’escompte n’existe pas'],
            'accountsPayable.error.clause_not_understood' => ['La clausola non prevede uno sconto cassa', 'The clause carries no cash discount', 'Die Klausel enthält kein Skonto', 'La clause ne prévoit pas d’escompte'],
            'accountsPayable.error.skonto_terms_invalid' => ['Sconto cassa: percentuale e giorni non validi', 'Cash discount: invalid percentage or days', 'Skonto: ungültiger Prozentsatz oder ungültige Tage', 'Escompte : pourcentage ou jours invalides'],
            'accountsPayable.error.payment_run_not_found' => ['La distinta di pagamento non esiste', 'The payment run does not exist', 'Der Zahlungslauf existiert nicht', 'Le bordereau de paiement n’existe pas'],
            'accountsPayable.error.run_item_not_found' => ['La partita non fa parte della distinta', 'The item is not part of the run', 'Der Posten gehört nicht zum Lauf', 'La partie ne fait pas partie du bordereau'],
            'accountsPayable.error.credit_note_row_not_removable' => ['Rimuovi la fattura su cui la nota di credito è compensata', 'Remove the invoice the credit note is offset against', 'Entfernen Sie die Rechnung, gegen die die Gutschrift verrechnet wird', 'Retirez la facture sur laquelle l’avoir est compensé'],
            'accountsPayable.error.no_items_match' => ['Nessuna partita corrisponde ai criteri', 'No open item matches the criteria', 'Kein offener Posten entspricht den Kriterien', 'Aucune partie ouverte ne correspond aux critères'],
            'accountsPayable.error.run_status_invalid' => ['Operazione non consentita: la distinta è {status}', 'Not allowed: the run is {status}', 'Nicht erlaubt: der Lauf ist {status}', 'Non permis : le bordereau est {status}'],
            'accountsPayable.error.run_not_authorized' => ['Solo una distinta autorizzata può essere eseguita (è {status})', 'Only an authorized run can be executed (it is {status})', 'Nur ein freigegebener Lauf kann ausgeführt werden (er ist {status})', 'Seul un bordereau autorisé peut être exécuté (il est {status})'],
            'accountsPayable.error.run_contains_held_items' => ['La distinta contiene partite in contestazione o bloccate: {documents}', 'The run holds disputed or blocked items: {documents}', 'Der Lauf enthält beanstandete oder gesperrte Posten: {documents}', 'Le bordereau contient des parties contestées ou bloquées : {documents}'],
            'accountsPayable.error.run_contains_unapproved_invoices' => ['La distinta contiene fatture non approvate: {documents}', 'The run holds invoices not approved for payment: {documents}', 'Der Lauf enthält nicht freigegebene Rechnungen: {documents}', 'Le bordereau contient des factures non approuvées : {documents}'],
            'accountsPayable.error.run_item_changed' => ['{document} è cambiata dopo la preparazione: prepara una nuova distinta', '{document} changed since the run was prepared: prepare a new run', '{document} hat sich seit der Vorbereitung geändert: neuen Lauf vorbereiten', '{document} a changé depuis la préparation : préparez un nouveau bordereau'],
            'accountsPayable.error.segregation_of_duties' => ['Segregazione dei compiti: la distinta deve essere approvata da un’altra persona', 'Segregation of duties: another person must approve the run', 'Funktionstrennung: eine andere Person muss den Lauf freigeben', 'Séparation des tâches : une autre personne doit approuver le bordereau'],
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
