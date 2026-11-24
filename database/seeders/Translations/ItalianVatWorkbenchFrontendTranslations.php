<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * Labels, hints and messages of the Angular pages of the Italian VAT compliance module (Mod 029): registers, ventilation,
 * third-party goods, plafond, LIPE, annual VAT, Certificazione Unica and F24. They complete ItalianVatFrontendTranslations
 * (which holds the navigation, the register vocabulary and the codes of the API) and never overlap with it. Each entry is
 * [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Placeholders are written {name}.
 */
final class ItalianVatWorkbenchFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            // Registers
            'italianVat.ui.tabRegisters' => ['Registri', 'Registers', 'Register', 'Registres'],
            'italianVat.ui.register' => ['Registro', 'Register', 'Register', 'Registre'],
            'italianVat.ui.section' => ['Sezionale', 'Section', 'Sektion', 'Section'],
            'italianVat.ui.documentKind' => ['Tipo documento', 'Document type', 'Belegart', 'Type de document'],
            'italianVat.ui.nature' => ['Natura IVA', 'VAT nature', 'MwSt-Art', 'Nature TVA'],
            'italianVat.ui.period' => ['Periodo', 'Period', 'Periode', 'Période'],
            'italianVat.ui.prints' => ['Stampe definitive', 'Definitive prints', 'Endgültige Drucke', 'Impressions définitives'],
            'italianVat.ui.printView' => ['Vista di stampa', 'Print view', 'Druckansicht', 'Aperçu d\'impression'],
            'italianVat.ui.proRataInForce' => ['Pro-rata di detraibilità in vigore: {percent}%', 'Deductibility pro-rata in force: {percent}%', 'Geltender Pro-rata-Satz: {percent}%', 'Prorata de déductibilité en vigueur : {percent}%'],

            // Ventilation
            'italianVat.ui.addLine' => ['Aggiungi riga', 'Add line', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'italianVat.ui.removeLine' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'italianVat.ui.basisTotal' => ['Totale base acquisti', 'Total purchase basis', 'Summe Einkaufsbasis', 'Total de la base d\'achats'],
            'italianVat.ui.preview' => ['Anteprima', 'Preview', 'Vorschau', 'Aperçu'],
            'italianVat.ui.registerTakings' => ['Registra corrispettivi', 'Register takings', 'Einnahmen erfassen', 'Enregistrer les recettes'],
            'italianVat.ui.takingsRegistered' => ['Corrispettivi registrati', 'Takings registered', 'Einnahmen erfasst', 'Recettes enregistrées'],
            'italianVat.ui.gross' => ['Lordo', 'Gross', 'Brutto', 'Brut'],
            'italianVat.ui.ventilationIssue_gross' => ['Inserisci i corrispettivi lordi del giorno.', 'Enter the gross takings of the day.', 'Bruttoeinnahmen des Tages eingeben.', 'Saisissez les recettes brutes du jour.'],
            'italianVat.ui.ventilationIssue_basis' => ['Servono acquisti con importo per ventilare.', 'Purchases with an amount are needed to ventilate.', 'Zum Verteilen sind Einkäufe mit Betrag nötig.', 'Des achats avec montant sont nécessaires pour ventiler.'],
            'italianVat.ui.ventilationIssue_nature' => ['Le righe con aliquota 0 richiedono la natura IVA.', 'Lines with a 0 rate need the VAT nature.', 'Zeilen mit Satz 0 benötigen die MwSt-Art.', 'Les lignes à taux 0 exigent la nature TVA.'],

            // Third-party goods
            'italianVat.ui.newMovement' => ['Nuovo movimento', 'New movement', 'Neue Bewegung', 'Nouveau mouvement'],
            'italianVat.ui.movements' => ['Movimenti', 'Movements', 'Bewegungen', 'Mouvements'],
            'italianVat.ui.movementType' => ['Tipo di movimento', 'Movement type', 'Bewegungsart', 'Type de mouvement'],
            'italianVat.ui.movementSaved' => ['Movimento registrato', 'Movement registered', 'Bewegung erfasst', 'Mouvement enregistré'],
            'italianVat.ui.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'italianVat.ui.quantity' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'italianVat.ui.value' => ['Valore', 'Value', 'Wert', 'Valeur'],

            // Plafond
            'italianVat.ui.plafondLaw' => ['Art. 8 c. 1 lett. c DPR 633/1972: le operazioni senza IVA sono ammesse solo entro il plafond e il periodo di validità della dichiarazione.', 'Art. 8 c. 1 lett. c DPR 633/1972: VAT-free operations are only allowed within the plafond and validity period of the declaration.', 'Art. 8 Abs. 1 lit. c DPR 633/1972: Umsätze ohne MwSt sind nur innerhalb von Plafond und Gültigkeitsdauer der Erklärung zulässig.', 'Art. 8 al. 1 let. c DPR 633/1972 : les opérations sans TVA ne sont admises que dans la limite du plafond et de la validité de la déclaration.'],
            'italianVat.ui.declarationsBlocked' => ['{count} lettere d\'intento non utilizzabili: plafond esaurito, scadute, revocate o non ancora valide', '{count} declarations of intent cannot be used: plafond exhausted, expired, revoked or not yet valid', '{count} Absichtserklärungen nicht verwendbar: Plafond aufgebraucht, abgelaufen, widerrufen oder noch nicht gültig', '{count} déclarations d\'intention inutilisables : plafond épuisé, expirées, révoquées ou pas encore valables'],
            'italianVat.ui.customer' => ['Cliente', 'Customer', 'Kunde', 'Client'],
            'italianVat.ui.protocolAde' => ['Protocollo Agenzia delle Entrate', 'Revenue Agency protocol', 'Protokoll der Steueragentur', 'Protocole de l\'Agence des revenus'],
            'italianVat.ui.validity' => ['Validità', 'Validity', 'Gültigkeit', 'Validité'],
            'italianVat.ui.validFrom' => ['Valida dal', 'Valid from', 'Gültig ab', 'Valable du'],
            'italianVat.ui.validTo' => ['Valida fino al', 'Valid until', 'Gültig bis', 'Valable jusqu\'au'],
            'italianVat.ui.receiptDate' => ['Data di ricezione', 'Receipt date', 'Eingangsdatum', 'Date de réception'],
            'italianVat.ui.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'italianVat.ui.newDeclaration' => ['Nuova lettera d\'intento', 'New declaration of intent', 'Neue Absichtserklärung', 'Nouvelle déclaration d\'intention'],
            'italianVat.ui.declaration' => ['Lettera d\'intento', 'Declaration of intent', 'Absichtserklärung', 'Déclaration d\'intention'],
            'italianVat.ui.declarationSaved' => ['Lettera d\'intento salvata', 'Declaration of intent saved', 'Absichtserklärung gespeichert', 'Déclaration d\'intention enregistrée'],
            'italianVat.ui.declarationRevoked' => ['Lettera d\'intento revocata', 'Declaration of intent revoked', 'Absichtserklärung widerrufen', 'Déclaration d\'intention révoquée'],
            'italianVat.ui.revokeConfirm' => ['Revocare la lettera d\'intento? Le nuove fatture senza IVA saranno bloccate.', 'Revoke the declaration of intent? New invoices without VAT will be blocked.', 'Absichtserklärung widerrufen? Neue Rechnungen ohne MwSt werden gesperrt.', 'Révoquer la déclaration d\'intention ? Les nouvelles factures sans TVA seront bloquées.'],
            'italianVat.ui.guardCheck' => ['Verifica operazione senza IVA', 'Check a VAT-free operation', 'Umsatz ohne MwSt prüfen', 'Vérifier une opération sans TVA'],
            'italianVat.ui.check' => ['Verifica', 'Check', 'Prüfen', 'Vérifier'],
            'italianVat.ui.covered' => ['Operazione coperta dal plafond', 'Operation covered by the plafond', 'Umsatz durch das Plafond gedeckt', 'Opération couverte par le plafond'],
            'italianVat.ui.declarationIssue_protocol_format' => ['Il protocollo deve avere 17 cifre, con eventuale suffisso di 6 cifre.', 'The protocol must be 17 digits, optionally followed by 6 digits.', 'Das Protokoll muss 17 Ziffern haben, optional gefolgt von 6 Ziffern.', 'Le protocole doit comporter 17 chiffres, suivis éventuellement de 6 chiffres.'],
            'italianVat.ui.declarationIssue_dates_order' => ['La fine validità non può precedere l\'inizio.', 'The validity end cannot precede its start.', 'Das Gültigkeitsende darf nicht vor dem Beginn liegen.', 'La fin de validité ne peut précéder le début.'],
            'italianVat.ui.declarationIssue_protocol_after_validity' => ['La data di ricezione è successiva alla fine di validità.', 'The receipt date is after the end of validity.', 'Das Eingangsdatum liegt nach dem Gültigkeitsende.', 'La date de réception est postérieure à la fin de validité.'],
            'italianVat.ui.declarationIssue_plafond_not_positive' => ['Il plafond deve essere maggiore di zero.', 'The plafond must be greater than zero.', 'Das Plafond muss grösser als null sein.', 'Le plafond doit être supérieur à zéro.'],

            // LIPE
            'italianVat.ui.lipeGenerated' => ['LIPE generata', 'LIPE generated', 'LIPE erstellt', 'LIPE générée'],
            'italianVat.ui.xsdValid' => ['XML conforme allo schema XSD', 'XML complies with the XSD schema', 'XML entspricht dem XSD-Schema', 'XML conforme au schéma XSD'],
            'italianVat.ui.xsdInvalid' => ['XML non conforme allo schema XSD: scarico bloccato', 'XML does not comply with the XSD schema: download blocked', 'XML entspricht nicht dem XSD-Schema: Download gesperrt', 'XML non conforme au schéma XSD : téléchargement bloqué'],
            'italianVat.ui.signature_unsigned' => ['Non firmata', 'Unsigned', 'Nicht signiert', 'Non signée'],
            'italianVat.ui.signature_signed' => ['Firmata', 'Signed', 'Signiert', 'Signée'],
            'italianVat.ui.signature_invalid' => ['Firma non valida', 'Invalid signature', 'Ungültige Signatur', 'Signature invalide'],
            'italianVat.ui.outputVat' => ['IVA a debito', 'Output VAT', 'Umsatzsteuer', 'TVA collectée'],
            'italianVat.ui.inputVat' => ['IVA a credito', 'Input VAT', 'Vorsteuer', 'TVA déductible'],
            'italianVat.ui.balance' => ['Saldo', 'Balance', 'Saldo', 'Solde'],
            'italianVat.ui.expected' => ['Atteso', 'Expected', 'Erwartet', 'Attendu'],
            'italianVat.ui.ledger' => ['Erario c/IVA', 'Erario c/IVA', 'Erario c/IVA', 'Erario c/IVA'],
            'italianVat.ui.difference' => ['Differenza', 'Difference', 'Differenz', 'Écart'],
            'italianVat.ui.recordTransmission' => ['Registra trasmissione', 'Record transmission', 'Übermittlung erfassen', 'Enregistrer la transmission'],
            'italianVat.ui.recordRejection' => ['Registra scarto', 'Record rejection', 'Ablehnung erfassen', 'Enregistrer le rejet'],
            'italianVat.ui.transmissionProtocol' => ['Protocollo di trasmissione', 'Transmission protocol', 'Übermittlungsprotokoll', 'Protocole de transmission'],
            'italianVat.ui.protocolFormat' => ['17 cifre, con eventuale suffisso di 6 cifre.', '17 digits, optionally followed by 6 digits.', '17 Ziffern, optional gefolgt von 6 Ziffern.', '17 chiffres, suivis éventuellement de 6 chiffres.'],
            'italianVat.ui.rejectionReason' => ['Motivo dello scarto', 'Rejection reason', 'Ablehnungsgrund', 'Motif du rejet'],
            'italianVat.ui.signatureValue' => ['Firma (base64)', 'Signature (base64)', 'Signatur (Base64)', 'Signature (base64)'],
            'italianVat.ui.certificate' => ['Certificato (PEM)', 'Certificate (PEM)', 'Zertifikat (PEM)', 'Certificat (PEM)'],
            'italianVat.ui.dialog_sign' => ['Firma digitale della LIPE', 'Digital signature of the LIPE', 'Digitale Signatur der LIPE', 'Signature numérique de la LIPE'],
            'italianVat.ui.dialog_transmit' => ['Trasmissione della LIPE', 'LIPE transmission', 'Übermittlung der LIPE', 'Transmission de la LIPE'],
            'italianVat.ui.dialog_reject' => ['Scarto della LIPE', 'LIPE rejection', 'Ablehnung der LIPE', 'Rejet de la LIPE'],

            // Annual VAT
            'italianVat.ui.annualNotCalculated' => ['IVA annuale non ancora calcolata per questo anno.', 'Annual VAT not calculated yet for this year.', 'Jahres-MwSt für dieses Jahr noch nicht berechnet.', 'TVA annuelle pas encore calculée pour cette année.'],
            'italianVat.ui.notReconciled' => ['Contabilità e liquidazioni non coincidono', 'Ledger and settlements do not match', 'Buchhaltung und Abrechnungen stimmen nicht überein', 'Comptabilité et liquidations ne concordent pas'],
            'italianVat.ui.interest' => ['Interessi', 'Interest', 'Zinsen', 'Intérêts'],
            'italianVat.ui.paid' => ['Versato', 'Paid', 'Bezahlt', 'Payé'],
            'italianVat.ui.netPosition' => ['Posizione netta (debito - credito)', 'Net position (debit - credit)', 'Nettoposition (Soll - Haben)', 'Position nette (débit - crédit)'],
            'italianVat.ui.finalizeConfirm' => ['Dopo la chiusura l\'IVA annuale non è più ricalcolabile. Confermi?', 'Once finalised the annual VAT can no longer be recalculated. Confirm?', 'Nach dem Abschluss kann die Jahres-MwSt nicht mehr neu berechnet werden. Bestätigen?', 'Une fois clôturée, la TVA annuelle ne peut plus être recalculée. Confirmer ?'],
            'italianVat.ui.finalized' => ['IVA annuale chiusa', 'Annual VAT finalised', 'Jahres-MwSt abgeschlossen', 'TVA annuelle clôturée'],

            // CU and F24
            'italianVat.ui.recipient' => ['Percipiente', 'Recipient', 'Empfänger', 'Bénéficiaire'],
            'italianVat.ui.taxCode' => ['Codice fiscale / P. IVA', 'Tax code / VAT number', 'Steuernummer / MwSt-Nr.', 'Code fiscal / n° TVA'],
            'italianVat.ui.reasonCode' => ['Causale', 'Reason code', 'Grundcode', 'Code motif'],
            'italianVat.ui.cuValid' => ['Le certificazioni sono valide per il file telematico', 'The certifications are valid for the telematic file', 'Die Bescheinigungen sind für die elektronische Datei gültig', 'Les certifications sont valides pour le fichier télématique'],
            'italianVat.ui.issueConfirm' => ['Dopo l\'emissione le certificazioni non sono più modificabili. Confermi?', 'Once issued the certifications can no longer be changed. Confirm?', 'Nach der Ausstellung können die Bescheinigungen nicht mehr geändert werden. Bestätigen?', 'Une fois émises, les certifications ne sont plus modifiables. Confirmer ?'],
            'italianVat.ui.cuIssued' => ['Certificazioni Uniche emesse', 'Certificazioni Uniche issued', 'Certificazioni Uniche ausgestellt', 'Certificazioni Uniche émises'],
            'italianVat.ui.syncF24' => ['Sincronizza F24', 'Sync F24', 'F24 synchronisieren', 'Synchroniser le F24'],
            'italianVat.ui.certifiedWithheld' => ['Ritenute certificate', 'Certified withholdings', 'Bescheinigte Quellensteuern', 'Retenues certifiées'],
            'italianVat.ui.f24Unpaid' => ['{count} versamenti F24 non ancora saldati', '{count} F24 payments not yet settled', '{count} F24-Zahlungen noch nicht beglichen', '{count} paiements F24 pas encore réglés'],
            'italianVat.ui.taxCodeF24' => ['Codice tributo', 'Tax code', 'Steuercode', 'Code tributaire'],
            'italianVat.ui.dueDate' => ['Scadenza', 'Due date', 'Fälligkeit', 'Échéance'],
            'italianVat.ui.recordPayment' => ['Registra pagamento', 'Record payment', 'Zahlung erfassen', 'Enregistrer le paiement'],
            'italianVat.ui.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'italianVat.ui.paidAt' => ['Data di pagamento', 'Payment date', 'Zahlungsdatum', 'Date de paiement'],
            'italianVat.ui.f24Reference' => ['Riferimento F24', 'F24 reference', 'F24-Referenz', 'Référence F24'],
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
