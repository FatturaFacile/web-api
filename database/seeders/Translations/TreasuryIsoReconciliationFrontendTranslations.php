<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings for the Swiss ISO 20022 banking flows and reconciliation workbench (issue #282):
 * multi-file camt.053 / camt.054 / pain.002 upload, exception workbench, pain.002 feedback with ISO reason codes,
 * payment flow purpose / charge bearer and the QR-IBAN bank configuration.
 * Supports all 5 locales: it-CH, de-CH, fr-CH, en, it-IT (plus base locales it, de, fr).
 * Every key lives under the `treasuryIso.` prefix and does not overlap with any other translation class.
 */
final class TreasuryIsoReconciliationFrontendTranslations
{
    /**
     * @return array<string, array{it-CH: string, de-CH: string, fr-CH: string, en: string, it-IT: string}>
     */
    public static function definitions(): array
    {
        return [
            // Navigation
            'treasuryIso.nav.upload' => self::row(
                'Upload ISO 20022',
                'ISO-20022-Upload',
                'Import ISO 20022',
                'ISO 20022 upload',
                'Caricamento ISO 20022',
            ),
            'treasuryIso.nav.exceptions' => self::row(
                'Eccezioni di riconciliazione',
                'Abstimmungsausnahmen',
                'Exceptions de rapprochement',
                'Reconciliation exceptions',
            ),

            // Multi-file upload
            'treasuryIso.upload.title' => self::row(
                'Carica estratti conto e avvisi bancari',
                'Kontoauszüge und Bankmeldungen hochladen',
                'Importer relevés et avis bancaires',
                'Upload bank statements and notifications',
                'Carica estratti conto e avvisi bancari',
            ),
            'treasuryIso.upload.subtitle' => self::row(
                'Trascina più file XML: il tipo di messaggio (camt.053, camt.054 o pain.002) viene riconosciuto automaticamente e i file sono elaborati uno dopo l’altro.',
                'Mehrere XML-Dateien hineinziehen: Der Nachrichtentyp (camt.053, camt.054 oder pain.002) wird automatisch erkannt, die Dateien werden nacheinander verarbeitet.',
                'Glissez plusieurs fichiers XML : le type de message (camt.053, camt.054 ou pain.002) est reconnu automatiquement et les fichiers sont traités l’un après l’autre.',
                'Drop several XML files: the message type (camt.053, camt.054 or pain.002) is detected automatically and the files are processed one after the other.',
            ),
            'treasuryIso.upload.hint' => self::row(
                'Clicca o trascina qui i file XML',
                'XML-Dateien hier anklicken oder hineinziehen',
                'Cliquez ou glissez ici les fichiers XML',
                'Click or drag the XML files here',
            ),
            'treasuryIso.upload.types' => self::row(
                'Formati accettati: camt.053, camt.054, pain.002 (XML, max. 10 MB per file).',
                'Akzeptierte Formate: camt.053, camt.054, pain.002 (XML, max. 10 MB pro Datei).',
                'Formats acceptés : camt.053, camt.054, pain.002 (XML, max. 10 Mo par fichier).',
                'Accepted formats: camt.053, camt.054, pain.002 (XML, max. 10 MB per file).',
            ),
            'treasuryIso.upload.autoReconcile' => self::row(
                'Riconcilia automaticamente i movimenti',
                'Buchungen automatisch abstimmen',
                'Rapprocher automatiquement les mouvements',
                'Reconcile the movements automatically',
            ),
            'treasuryIso.upload.batchId' => self::row(
                'Distinta di pagamento (solo pain.002)',
                'Zahlungsauftrag (nur pain.002)',
                'Lot de paiement (pain.002 uniquement)',
                'Payment batch (pain.002 only)',
                'Distinta di pagamento (solo pain.002)',
            ),
            'treasuryIso.upload.batchIdHint' => self::row(
                'ID della distinta, facoltativo',
                'Auftrags-ID, optional',
                'ID du lot, facultatif',
                'Batch ID, optional',
            ),
            'treasuryIso.upload.totals' => self::row('Totale del caricamento', 'Total des Uploads', 'Total de l’import', 'Upload total'),
            'treasuryIso.upload.results' => self::row('Risultato per file', 'Ergebnis pro Datei', 'Résultat par fichier', 'Result per file'),
            'treasuryIso.upload.files' => self::row('File', 'Dateien', 'Fichiers', 'Files'),
            'treasuryIso.upload.reconciled' => self::row('Riconciliati', 'Abgestimmt', 'Rapprochés', 'Reconciled'),
            'treasuryIso.upload.unmatched' => self::row('Non abbinati', 'Nicht zugeordnet', 'Non rapprochés', 'Unmatched'),
            'treasuryIso.upload.duplicates' => self::row('Duplicati', 'Duplikate', 'Doublons', 'Duplicates'),
            'treasuryIso.upload.failed' => self::row('Con errore', 'Fehlgeschlagen', 'En erreur', 'Failed'),
            'treasuryIso.upload.accepted' => self::row('Accettati', 'Akzeptiert', 'Acceptés', 'Accepted'),
            'treasuryIso.upload.rejected' => self::row('Respinti', 'Abgelehnt', 'Rejetés', 'Rejected'),
            'treasuryIso.upload.pending' => self::row('In attesa', 'Ausstehend', 'En attente', 'Pending'),
            'treasuryIso.upload.col.file' => self::row('File', 'Datei', 'Fichier', 'File'),
            'treasuryIso.upload.col.type' => self::row('Tipo', 'Typ', 'Type', 'Type'),
            'treasuryIso.upload.col.detail' => self::row('Dettaglio', 'Detail', 'Détail', 'Detail'),
            'treasuryIso.upload.col.outcome' => self::row('Esito', 'Ergebnis', 'Résultat', 'Outcome'),
            'treasuryIso.upload.state.queued' => self::row('In coda', 'In Warteschlange', 'En file d’attente', 'Queued'),
            'treasuryIso.upload.state.uploading' => self::row('Elaborazione…', 'Verarbeitung…', 'Traitement…', 'Processing…'),
            'treasuryIso.upload.state.failed' => self::row('Errore', 'Fehler', 'Erreur', 'Error'),
            'treasuryIso.upload.invalidFile' => self::row(
                'Il file deve essere un XML non vuoto di al massimo 10 MB.',
                'Die Datei muss eine nicht leere XML-Datei von höchstens 10 MB sein.',
                'Le fichier doit être un XML non vide de 10 Mo au maximum.',
                'The file must be a non-empty XML file of at most 10 MB.',
            ),
            'treasuryIso.upload.failure' => self::row(
                'Il file non è stato elaborato. Riprova più tardi.',
                'Die Datei konnte nicht verarbeitet werden. Bitte später erneut versuchen.',
                'Le fichier n’a pas pu être traité. Veuillez réessayer plus tard.',
                'The file could not be processed. Please try again later.',
            ),

            // Reconciliation workbench
            'treasuryIso.workbench.title' => self::row(
                'Eccezioni di riconciliazione',
                'Abstimmungsausnahmen',
                'Exceptions de rapprochement',
                'Reconciliation exceptions',
            ),
            'treasuryIso.workbench.subtitle' => self::row(
                'Movimenti bancari che la riconciliazione automatica non ha potuto chiudere, raggruppati per causa.',
                'Bankbuchungen, die der automatische Abgleich nicht abschliessen konnte, nach Ursache gruppiert.',
                'Mouvements bancaires que le rapprochement automatique n’a pas pu clore, regroupés par cause.',
                'Bank movements the automatic reconciliation could not settle, grouped by cause.',
                'Movimenti bancari che la riconciliazione automatica non ha potuto chiudere, raggruppati per causa.',
            ),
            'treasuryIso.workbench.codes' => self::row('Filtro per causa', 'Filter nach Ursache', 'Filtre par cause', 'Filter by cause'),
            'treasuryIso.workbench.all' => self::row('Tutte', 'Alle', 'Toutes', 'All'),
            'treasuryIso.workbench.apply' => self::row('Cerca', 'Suchen', 'Rechercher', 'Search'),
            'treasuryIso.workbench.search' => self::row(
                'Cerca per controparte, riferimento, importo',
                'Nach Gegenpartei, Referenz, Betrag suchen',
                'Rechercher par contrepartie, référence, montant',
                'Search by counterpart, reference, amount',
            ),
            'treasuryIso.workbench.direction.label' => self::row('Direzione', 'Richtung', 'Sens', 'Direction'),
            'treasuryIso.workbench.direction.all' => self::row('Entrate e uscite', 'Ein- und Ausgänge', 'Entrées et sorties', 'Credits and debits'),
            'treasuryIso.workbench.direction.credit' => self::row('Entrate', 'Eingänge', 'Entrées', 'Credits'),
            'treasuryIso.workbench.direction.debit' => self::row('Uscite', 'Ausgänge', 'Sorties', 'Debits'),
            'treasuryIso.workbench.col.date' => self::row('Data', 'Datum', 'Date', 'Date'),
            'treasuryIso.workbench.col.counterpart' => self::row('Controparte', 'Gegenpartei', 'Contrepartie', 'Counterpart'),
            'treasuryIso.workbench.col.reference' => self::row('Riferimento', 'Referenz', 'Référence', 'Reference'),
            'treasuryIso.workbench.col.amount' => self::row('Importo', 'Betrag', 'Montant', 'Amount'),
            'treasuryIso.workbench.col.exception' => self::row('Causa', 'Ursache', 'Cause', 'Cause'),
            'treasuryIso.workbench.col.suggestion' => self::row('Proposta', 'Vorschlag', 'Suggestion', 'Suggestion'),
            'treasuryIso.workbench.col.actions' => self::row('Azioni', 'Aktionen', 'Actions', 'Actions'),
            'treasuryIso.workbench.confirm' => self::row('Conferma proposta', 'Vorschlag bestätigen', 'Confirmer la suggestion', 'Confirm suggestion'),
            'treasuryIso.workbench.assign' => self::row('Assegna a fattura', 'Rechnung zuordnen', 'Affecter à une facture', 'Assign to invoice'),
            'treasuryIso.workbench.assignTitle' => self::row(
                'Assegna il movimento a una fattura',
                'Buchung einer Rechnung zuordnen',
                'Affecter le mouvement à une facture',
                'Assign the movement to an invoice',
            ),
            'treasuryIso.workbench.suggested' => self::row('Fattura proposta', 'Vorgeschlagene Rechnung', 'Facture suggérée', 'Suggested invoice'),
            'treasuryIso.workbench.invoice' => self::row('Fattura aperta', 'Offene Rechnung', 'Facture ouverte', 'Open invoice'),
            'treasuryIso.workbench.difference' => self::row('Gestione della differenza', 'Umgang mit der Differenz', 'Traitement de l’écart', 'Difference handling'),
            'treasuryIso.workbench.diff.none' => self::row('Nessuna differenza', 'Keine Differenz', 'Aucun écart', 'No difference'),
            'treasuryIso.workbench.diff.write_off' => self::row(
                'Abbuona la differenza (spese o tolleranza)',
                'Differenz ausbuchen (Spesen oder Toleranz)',
                'Passer l’écart en perte (frais ou tolérance)',
                'Write off the difference (fees or tolerance)',
                'Abbuona la differenza (spese o tolleranza)',
            ),
            'treasuryIso.workbench.diff.partial' => self::row(
                'Registra come pagamento parziale',
                'Als Teilzahlung erfassen',
                'Enregistrer comme paiement partiel',
                'Record as partial payment',
            ),
            'treasuryIso.workbench.assigned' => self::row('Movimento assegnato alla fattura.', 'Buchung der Rechnung zugeordnet.', 'Mouvement affecté à la facture.', 'Movement assigned to the invoice.'),
            'treasuryIso.workbench.categorize' => self::row('Categorizza', 'Kategorisieren', 'Catégoriser', 'Categorize'),
            'treasuryIso.workbench.categorizeTitle' => self::row(
                'Categorizza il movimento',
                'Buchung kategorisieren',
                'Catégoriser le mouvement',
                'Categorize the movement',
            ),
            'treasuryIso.workbench.category' => self::row('Categoria', 'Kategorie', 'Catégorie', 'Category'),
            'treasuryIso.workbench.ledgerAccount' => self::row('Conto contabile (facoltativo)', 'Sachkonto (optional)', 'Compte comptable (facultatif)', 'Ledger account (optional)'),
            'treasuryIso.workbench.ledgerInvalid' => self::row(
                'Solo lettere, cifre, punti e trattini (max. 20 caratteri).',
                'Nur Buchstaben, Ziffern, Punkte und Bindestriche (max. 20 Zeichen).',
                'Uniquement lettres, chiffres, points et traits d’union (20 caractères max.).',
                'Only letters, digits, dots and hyphens (max. 20 characters).',
            ),
            'treasuryIso.workbench.notes' => self::row('Note', 'Notizen', 'Notes', 'Notes'),
            'treasuryIso.workbench.categorized' => self::row('Movimento categorizzato.', 'Buchung kategorisiert.', 'Mouvement catégorisé.', 'Movement categorized.'),
            'treasuryIso.workbench.cancel' => self::row('Annulla', 'Abbrechen', 'Annuler', 'Cancel'),
            'treasuryIso.workbench.details' => self::row('Dettagli', 'Details', 'Détails', 'Details'),
            'treasuryIso.workbench.detailTitle' => self::row('Dettaglio del movimento', 'Buchungsdetail', 'Détail du mouvement', 'Movement detail'),
            'treasuryIso.workbench.detail.fee' => self::row('Spese bancarie', 'Bankspesen', 'Frais bancaires', 'Bank fees'),
            'treasuryIso.workbench.detail.net' => self::row('Importo netto', 'Nettobetrag', 'Montant net', 'Net amount'),
            'treasuryIso.workbench.detail.reference' => self::row('Riferimento', 'Referenz', 'Référence', 'Reference'),
            'treasuryIso.workbench.detail.qrReference' => self::row('Riferimento QR (QRR / SCOR)', 'QR-Referenz (QRR / SCOR)', 'Référence QR (QRR / SCOR)', 'QR reference (QRR / SCOR)'),
            'treasuryIso.workbench.detail.description' => self::row('Causale', 'Verwendungszweck', 'Motif', 'Description'),
            'treasuryIso.workbench.detail.statement' => self::row('Estratto conto', 'Kontoauszug', 'Relevé', 'Statement'),
            'treasuryIso.workbench.error.load' => self::row(
                'Impossibile caricare le eccezioni.',
                'Die Ausnahmen konnten nicht geladen werden.',
                'Impossible de charger les exceptions.',
                'The exceptions could not be loaded.',
            ),
            'treasuryIso.workbench.error.assign' => self::row(
                'Impossibile assegnare il movimento alla fattura.',
                'Die Buchung konnte der Rechnung nicht zugeordnet werden.',
                'Impossible d’affecter le mouvement à la facture.',
                'The movement could not be assigned to the invoice.',
            ),
            'treasuryIso.workbench.error.categorize' => self::row(
                'Impossibile categorizzare il movimento.',
                'Die Buchung konnte nicht kategorisiert werden.',
                'Impossible de catégoriser le mouvement.',
                'The movement could not be categorized.',
            ),

            // Exception codes
            'treasuryIso.exception.Unmatched' => self::row('Senza abbinamento', 'Nicht zugeordnet', 'Sans correspondance', 'Unmatched'),
            'treasuryIso.exception.AmountMismatch' => self::row('Importo diverso', 'Abweichender Betrag', 'Montant divergent', 'Amount mismatch'),
            'treasuryIso.exception.DuplicatePayment' => self::row('Pagamento duplicato', 'Doppelzahlung', 'Paiement en double', 'Duplicate payment'),
            'treasuryIso.exception.AlreadyPaid' => self::row('Già pagata', 'Bereits bezahlt', 'Déjà payée', 'Already paid'),
            'treasuryIso.exception.InvalidReference' => self::row('Riferimento non valido', 'Ungültige Referenz', 'Référence invalide', 'Invalid reference'),

            // Line categories (non-invoice bookings)
            'treasuryIso.category.tax_payment' => self::row('Pagamento imposte', 'Steuerzahlung', 'Paiement d’impôts', 'Tax payment'),
            'treasuryIso.category.payroll' => self::row('Stipendi', 'Lohnzahlung', 'Salaires', 'Payroll'),
            'treasuryIso.category.bank_expense' => self::row('Spese bancarie', 'Bankspesen', 'Frais bancaires', 'Bank expense'),
            'treasuryIso.category.internal_transfer' => self::row('Giroconto interno', 'Interne Umbuchung', 'Virement interne', 'Internal transfer'),
            'treasuryIso.category.generic_expense' => self::row('Altra spesa', 'Übriger Aufwand', 'Autre charge', 'Other expense'),

            // pain.002 feedback
            'treasuryIso.lineStatus.reference' => self::row('Riferimento end-to-end', 'End-to-End-Referenz', 'Référence end-to-end', 'End-to-end reference'),
            'treasuryIso.lineStatus.outcome' => self::row('Esito', 'Ergebnis', 'Résultat', 'Outcome'),
            'treasuryIso.lineStatus.bankStatus' => self::row('Stato della banca', 'Bankstatus', 'Statut de la banque', 'Bank status'),
            'treasuryIso.lineStatus.reason' => self::row('Motivo del rifiuto', 'Ablehnungsgrund', 'Motif du rejet', 'Rejection reason'),
            'treasuryIso.lineStatus.info' => self::row('Informazioni aggiuntive', 'Zusatzinformationen', 'Informations complémentaires', 'Additional information'),
            'treasuryIso.outcome.executed' => self::row('Eseguito', 'Ausgeführt', 'Exécuté', 'Executed'),
            'treasuryIso.outcome.rejected' => self::row('Respinto', 'Abgelehnt', 'Rejeté', 'Rejected'),
            'treasuryIso.outcome.pending' => self::row('In attesa', 'Ausstehend', 'En attente', 'Pending'),

            'treasuryIso.status.ACCP' => self::row('Accettato (profilo cliente)', 'Akzeptiert (Kundenprofil)', 'Accepté (profil client)', 'Accepted (customer profile)'),
            'treasuryIso.status.ACSP' => self::row('Accettato, regolamento in corso', 'Akzeptiert, Abwicklung läuft', 'Accepté, règlement en cours', 'Accepted, settlement in process'),
            'treasuryIso.status.ACSC' => self::row('Accettato, regolamento concluso', 'Akzeptiert, Abwicklung abgeschlossen', 'Accepté, règlement terminé', 'Accepted, settlement completed'),
            'treasuryIso.status.ACWC' => self::row('Accettato con modifiche', 'Akzeptiert mit Änderung', 'Accepté avec modification', 'Accepted with change'),
            'treasuryIso.status.ACTC' => self::row('Accettato (convalida tecnica)', 'Akzeptiert (technische Validierung)', 'Accepté (validation technique)', 'Accepted (technical validation)'),
            'treasuryIso.status.PDNG' => self::row('In sospeso', 'Ausstehend', 'En suspens', 'Pending'),
            'treasuryIso.status.PART' => self::row('Accettato in parte', 'Teilweise akzeptiert', 'Partiellement accepté', 'Partially accepted'),
            'treasuryIso.status.RJCT' => self::row('Respinto', 'Abgelehnt', 'Rejeté', 'Rejected'),

            // ISO 20022 rejection reason codes
            'treasuryIso.reason.AC01' => self::row('Numero di conto errato (IBAN non valido)', 'Falsche Kontonummer (ungültige IBAN)', 'Numéro de compte incorrect (IBAN invalide)', 'Incorrect account number (invalid IBAN)'),
            'treasuryIso.reason.AC04' => self::row('Conto estinto', 'Konto aufgelöst', 'Compte clôturé', 'Closed account'),
            'treasuryIso.reason.AC06' => self::row('Conto bloccato', 'Konto gesperrt', 'Compte bloqué', 'Blocked account'),
            'treasuryIso.reason.AG01' => self::row('Operazione non consentita per questo conto', 'Transaktion für dieses Konto nicht erlaubt', 'Opération interdite pour ce compte', 'Transaction forbidden for this account'),
            'treasuryIso.reason.AM01' => self::row('Importo pari a zero', 'Betrag ist null', 'Montant égal à zéro', 'Zero amount'),
            'treasuryIso.reason.AM02' => self::row('Importo non consentito', 'Betrag nicht zulässig', 'Montant non autorisé', 'Amount not allowed'),
            'treasuryIso.reason.AM04' => self::row('Fondi insufficienti', 'Ungenügende Deckung', 'Provision insuffisante', 'Insufficient funds'),
            'treasuryIso.reason.AM05' => self::row('Duplicato: pagamento già ricevuto', 'Duplikat: Zahlung bereits erhalten', 'Doublon : paiement déjà reçu', 'Duplicate: payment already received'),
            'treasuryIso.reason.BE01' => self::row('Dati incoerenti con il cliente finale', 'Angaben stimmen nicht mit dem Endkunden überein', 'Données incohérentes avec le client final', 'Inconsistent with the end customer'),
            'treasuryIso.reason.DT01' => self::row('Data non valida', 'Ungültiges Datum', 'Date invalide', 'Invalid date'),
            'treasuryIso.reason.FF01' => self::row('Formato del file non valido', 'Ungültiges Dateiformat', 'Format de fichier invalide', 'Invalid file format'),
            'treasuryIso.reason.MD01' => self::row('Mandato assente', 'Kein Mandat vorhanden', 'Aucun mandat', 'No mandate'),
            'treasuryIso.reason.MS02' => self::row('Motivo non specificato (indicato dal cliente)', 'Grund nicht angegeben (vom Kunden)', 'Motif non précisé (indiqué par le client)', 'Reason not specified (customer generated)'),
            'treasuryIso.reason.MS03' => self::row('Motivo non specificato (indicato dalla banca)', 'Grund nicht angegeben (von der Bank)', 'Motif non précisé (indiqué par la banque)', 'Reason not specified (bank generated)'),
            'treasuryIso.reason.NARR' => self::row('Vedi le informazioni aggiuntive', 'Siehe Zusatzinformationen', 'Voir les informations complémentaires', 'See the additional information'),
            'treasuryIso.reason.RC01' => self::row('Identificativo bancario (BIC) errato', 'Falsche Bankkennung (BIC)', 'Identifiant bancaire (BIC) incorrect', 'Incorrect bank identifier (BIC)'),
            'treasuryIso.reason.RR01' => self::row('Conto o identificazione dell’ordinante mancante', 'Konto oder Identifikation des Zahlungspflichtigen fehlt', 'Compte ou identification du donneur d’ordre manquant', 'Missing debtor account or identification'),
            'treasuryIso.reason.RR04' => self::row('Motivo regolamentare', 'Regulatorischer Grund', 'Motif réglementaire', 'Regulatory reason'),
            'treasuryIso.reason.TM01' => self::row('Orario limite superato', 'Annahmeschluss überschritten', 'Heure limite dépassée', 'Cut-off time exceeded'),
            'treasuryIso.reason.UNKNOWN' => self::row('Motivo non riconosciuto', 'Unbekannter Grund', 'Motif non reconnu', 'Unknown reason'),

            // Payment flow form
            'treasuryIso.flows.categoryPurpose' => self::row('Scopo del pagamento (categoria)', 'Zahlungszweck (Kategorie)', 'But du paiement (catégorie)', 'Payment purpose (category)'),
            'treasuryIso.flows.chargeBearer' => self::row('Ripartizione delle spese', 'Spesenregelung', 'Répartition des frais', 'Charge bearer'),
            'treasuryIso.flows.feedback' => self::row('Esito dalla banca per riga', 'Rückmeldung der Bank pro Zeile', 'Retour de la banque par ligne', 'Bank feedback per line'),
            'treasuryIso.purpose.SALA' => self::row('Stipendio', 'Lohn', 'Salaire', 'Salary'),
            'treasuryIso.purpose.SUPP' => self::row('Pagamento fornitore', 'Lieferantenzahlung', 'Paiement fournisseur', 'Supplier payment'),
            'treasuryIso.purpose.TAXS' => self::row('Pagamento imposte', 'Steuerzahlung', 'Paiement d’impôts', 'Tax payment'),
            'treasuryIso.purpose.PENS' => self::row('Previdenza e pensione', 'Vorsorge und Rente', 'Prévoyance et retraite', 'Pension payment'),
            'treasuryIso.purpose.BONU' => self::row('Bonus', 'Bonus', 'Bonus', 'Bonus'),
            'treasuryIso.purpose.TRAD' => self::row('Operazione commerciale', 'Handelsgeschäft', 'Opération commerciale', 'Trade'),
            'treasuryIso.bearer.SLEV' => self::row('Secondo il livello di servizio', 'Gemäss Service Level', 'Selon le niveau de service', 'Following service level'),
            'treasuryIso.bearer.SHAR' => self::row('Condivise', 'Geteilt', 'Partagés', 'Shared'),
            'treasuryIso.bearer.DEBT' => self::row('A carico dell’ordinante', 'Zulasten Auftraggeber', 'À la charge du donneur d’ordre', 'Borne by debtor'),
            'treasuryIso.bearer.CRED' => self::row('A carico del beneficiario', 'Zulasten Begünstigter', 'À la charge du bénéficiaire', 'Borne by creditor'),

            // Company bank configuration
            'treasuryIso.bank.section' => self::row('Flussi ISO 20022 e riconciliazione', 'ISO-20022-Zahlungsverkehr und Abstimmung', 'Flux ISO 20022 et rapprochement', 'ISO 20022 flows and reconciliation'),
            'treasuryIso.bank.painVersion' => self::row('Versione pain.001 preferita', 'Bevorzugte pain.001-Version', 'Version pain.001 préférée', 'Preferred pain.001 version'),
            'treasuryIso.bank.tolerance' => self::row('Tolleranza di riconciliazione', 'Abstimmungstoleranz', 'Tolérance de rapprochement', 'Reconciliation tolerance'),
            'treasuryIso.bank.maxFee' => self::row('Spese massime abbuonabili', 'Maximal ausbuchbare Spesen', 'Frais maximum passables en perte', 'Maximum fee written off'),
            'treasuryIso.validation.qrIban' => self::row(
                'QR-IBAN non valido: serve un IBAN CH o LI con QR-IID tra 30000 e 31999.',
                'Ungültige QR-IBAN: erforderlich ist eine CH- oder LI-IBAN mit QR-IID zwischen 30000 und 31999.',
                'QR-IBAN invalide : il faut un IBAN CH ou LI avec un QR-IID entre 30000 et 31999.',
                'Invalid QR-IBAN: a CH or LI IBAN with a QR-IID between 30000 and 31999 is required.',
            ),
            'treasuryIso.validation.qrIid' => self::row(
                'Il QR-IID deve avere 5 cifre, tra 30000 e 31999.',
                'Die QR-IID muss fünfstellig sein, zwischen 30000 und 31999.',
                'Le QR-IID doit comporter 5 chiffres, entre 30000 et 31999.',
                'The QR-IID must have 5 digits, between 30000 and 31999.',
            ),
            'treasuryIso.validation.besrId' => self::row(
                'Il BESR-ID deve avere da 6 a 9 cifre.',
                'Die BESR-ID muss 6 bis 9 Ziffern haben.',
                'Le BESR-ID doit comporter de 6 à 9 chiffres.',
                'The BESR-ID must have 6 to 9 digits.',
            ),
            'treasuryIso.validation.amount' => self::row(
                'Inserisci un importo non negativo con al massimo due decimali.',
                'Nicht negativen Betrag mit höchstens zwei Dezimalstellen eingeben.',
                'Saisissez un montant non négatif avec deux décimales au maximum.',
                'Enter a non-negative amount with at most two decimals.',
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $resolved = match ($locale) {
            'it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT' => $locale,
            'it' => 'it-IT',
            'de' => 'de-CH',
            'fr' => 'fr-CH',
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $out = [];
        foreach (self::definitions() as $key => $locales) {
            $out[$key] = $locales[$resolved];
        }

        return $out;
    }

    /**
     * One definition; the Italian (Italy) text defaults to the Swiss Italian one when the wording is the same.
     *
     * @return array{it-CH: string, de-CH: string, fr-CH: string, en: string, it-IT: string}
     */
    private static function row(string $it, string $de, string $fr, string $en, ?string $itIt = null): array
    {
        return ['it-CH' => $it, 'de-CH' => $de, 'fr-CH' => $fr, 'en' => $en, 'it-IT' => $itIt ?? $it];
    }
}
