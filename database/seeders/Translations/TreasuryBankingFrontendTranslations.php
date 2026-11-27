<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the treasury banking workspace (#268): payment files (Swiss pain.001.001.09, SEPA pain.001.001.03, CBI),
 * Modello F24 telematico, statement imports and PSD2 / SIX bLink synchronisation, and the smart reconciliation workbench.
 * Each entry is [it, en, de, fr]. Legal acronyms (SEPA, CBI, CUC, F24, IMU, INPS, IRAP, IRES, IVA, QR-IBAN) stay untranslated.
 */
final class TreasuryBankingFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return self::navigation() + self::paymentFlows() + self::f24() + self::statementHub() + self::smart() + self::f24Codes();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'tbk.nav.paymentFlows' => ['Flussi di pagamento', 'Payment files', 'Zahlungsdateien', 'Fichiers de paiement'],
            'tbk.nav.f24' => ['Modello F24', 'Modello F24', 'Modello F24', 'Modello F24'],
            'tbk.nav.statementHub' => ['Estratti conto e Open Banking', 'Statements and Open Banking', 'Kontoauszüge und Open Banking', 'Relevés et Open Banking'],
            'tbk.nav.smartReconciliation' => ['Riconciliazione intelligente', 'Smart reconciliation', 'Intelligenter Abgleich', 'Rapprochement intelligent'],
            'tbk.common.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'tbk.common.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'tbk.common.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'tbk.common.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'tbk.common.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'tbk.common.status' => ['Stato', 'Status', 'Status', 'Statut'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function paymentFlows(): array
    {
        return [
            'tbk.flows.title' => ['Genera un file di pagamento', 'Generate a payment file', 'Zahlungsdatei erzeugen', 'Générer un fichier de paiement'],
            'tbk.flows.step.setup' => ['Flusso e conto', 'Flow and account', 'Ablauf und Konto', 'Flux et compte'],
            'tbk.flows.step.payments' => ['Pagamenti', 'Payments', 'Zahlungen', 'Paiements'],
            'tbk.flows.step.validate' => ['Validazione', 'Validation', 'Validierung', 'Validation'],
            'tbk.flows.step.deliver' => ['Distinta', 'Batch', 'Stapel', 'Lot'],
            'tbk.flows.flow' => ['Flusso', 'Flow', 'Ablauf', 'Flux'],
            'tbk.flows.account' => ['Conto da addebitare', 'Account to debit', 'Zu belastendes Konto', 'Compte à débiter'],
            'tbk.flows.executionDate' => ['Data di esecuzione', 'Execution date', 'Ausführungsdatum', 'Date d\'exécution'],
            'tbk.flows.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'tbk.flows.cuc' => ['CUC (Codice Unico CBI)', 'CUC (Codice Unico CBI)', 'CUC (Codice Unico CBI)', 'CUC (Codice Unico CBI)'],
            'tbk.flows.payments' => ['Pagamenti', 'Payments', 'Zahlungen', 'Paiements'],
            'tbk.flows.creditor' => ['Beneficiario', 'Creditor', 'Begünstigter', 'Bénéficiaire'],
            'tbk.flows.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'tbk.flows.reference' => ['Riferimento QR / SCOR', 'QR / SCOR reference', 'QR-/SCOR-Referenz', 'Référence QR / SCOR'],
            'tbk.flows.remittance' => ['Causale', 'Remittance information', 'Verwendungszweck', 'Motif'],
            'tbk.flows.addPayment' => ['Aggiungi pagamento', 'Add payment', 'Zahlung hinzufügen', 'Ajouter un paiement'],
            'tbk.flows.total' => ['Totale', 'Total', 'Total', 'Total'],
            'tbk.flows.validate' => ['Valida e genera', 'Validate and generate', 'Validieren und erzeugen', 'Valider et générer'],
            'tbk.flows.createBatch' => ['Crea distinta', 'Create batch', 'Stapel erstellen', 'Créer le lot'],
            'tbk.flows.download' => ['Scarica XML', 'Download XML', 'XML herunterladen', 'Télécharger le XML'],
            'tbk.flows.invalid' => ['Il file non può essere generato', 'The file cannot be generated', 'Die Datei kann nicht erzeugt werden', 'Le fichier ne peut pas être généré'],
            'tbk.flows.generated' => ['File generato', 'Generated file', 'Erzeugte Datei', 'Fichier généré'],
            'tbk.flows.transactions' => ['Operazioni', 'Transactions', 'Transaktionen', 'Opérations'],
            'tbk.flows.controlSum' => ['Somma di controllo', 'Control sum', 'Kontrollsumme', 'Somme de contrôle'],
            'tbk.flows.version' => ['Schema', 'Schema', 'Schema', 'Schéma'],
            'tbk.flows.schemaValid' => ['Valido secondo lo schema XSD', 'Valid against the XSD schema', 'Gültig gemäss XSD-Schema', 'Valide selon le schéma XSD'],
            'tbk.flows.xml' => ['Anteprima XML', 'XML preview', 'XML-Vorschau', 'Aperçu XML'],
            'tbk.flows.batchCreated' => ['Distinta creata: segue approvazione, esportazione e conferma della banca.', 'Batch created: it follows approval, export and bank confirmation.', 'Stapel erstellt: es folgen Freigabe, Export und Bankbestätigung.', 'Lot créé : suivent approbation, export et confirmation de la banque.'],
            'tbk.flow.ch_pain001_09' => ['Svizzera · pain.001.001.09 (IBAN e QR-bill)', 'Switzerland · pain.001.001.09 (IBAN and QR-bill)', 'Schweiz · pain.001.001.09 (IBAN und QR-Rechnung)', 'Suisse · pain.001.001.09 (IBAN et QR-facture)'],
            'tbk.flow.ch_pain001_03' => ['Svizzera · pain.001.001.03 (IBAN e QR-bill)', 'Switzerland · pain.001.001.03 (IBAN and QR-bill)', 'Schweiz · pain.001.001.03 (IBAN und QR-Rechnung)', 'Suisse · pain.001.001.03 (IBAN et QR-facture)'],
            'tbk.flow.sepa_pain001_03' => ['SEPA · pain.001.001.03', 'SEPA · pain.001.001.03', 'SEPA · pain.001.001.03', 'SEPA · pain.001.001.03'],
            'tbk.flow.cbi_sepa_03' => ['Italia · bonifico CBI (SEPA)', 'Italy · CBI credit transfer (SEPA)', 'Italien · CBI-Überweisung (SEPA)', 'Italie · virement CBI (SEPA)'],
            'tbk.flowHint.ch_pain001_09' => ['Standard SIX Swiss Payment Standards: un QR-IBAN richiede un riferimento QR di 27 cifre (QRR), un IBAN ordinario un riferimento ISO 11649 (SCOR) o testo libero. Valute CHF ed EUR.', 'SIX Swiss Payment Standards: a QR-IBAN needs a 27 digit QR reference (QRR), a standard IBAN an ISO 11649 reference (SCOR) or free text. Currencies CHF and EUR.', 'SIX Swiss Payment Standards: eine QR-IBAN verlangt eine 27-stellige QR-Referenz (QRR), eine reguläre IBAN eine ISO-11649-Referenz (SCOR) oder Freitext. Währungen CHF und EUR.', 'SIX Swiss Payment Standards : un QR-IBAN exige une référence QR à 27 chiffres (QRR), un IBAN ordinaire une référence ISO 11649 (SCOR) ou un texte libre. Devises CHF et EUR.'],
            'tbk.flowHint.ch_pain001_03' => ['Generazione SIX pain.001.001.03 (ch.02) ancora richiesta da alcune banche: stesse regole QRR / SCOR del formato .09. Valute CHF ed EUR.', 'SIX pain.001.001.03 (ch.02) generation still required by some banks: same QRR / SCOR rules as the .09 format. Currencies CHF and EUR.', 'SIX-pain.001.001.03 (ch.02), von einigen Banken weiterhin verlangt: gleiche QRR- / SCOR-Regeln wie beim Format .09. Währungen CHF und EUR.', 'Génération SIX pain.001.001.03 (ch.02) encore exigée par certaines banques : mêmes règles QRR / SCOR que le format .09. Devises CHF et EUR.'],
            'tbk.flowHint.sepa_pain001_03' => ['Bonifico SEPA EPC: solo EUR, IBAN dell\'area SEPA, nome del beneficiario fino a 70 caratteri.', 'EPC SEPA credit transfer: EUR only, IBANs of the SEPA area, creditor name up to 70 characters.', 'EPC-SEPA-Überweisung: nur EUR, IBAN des SEPA-Raums, Name des Begünstigten bis 70 Zeichen.', 'Virement SEPA EPC : EUR uniquement, IBAN de la zone SEPA, nom du bénéficiaire jusqu\'à 70 caractères.'],
            'tbk.flowHint.cbi_sepa_03' => ['Bonifico interbancario CBI: file SEPA con il CUC dell\'azienda come identificativo dell\'ordinante; il conto addebitato deve essere italiano.', 'CBI interbank transfer: SEPA file carrying the company CUC as initiating party; the debited account must be Italian.', 'CBI-Interbankenüberweisung: SEPA-Datei mit dem CUC des Unternehmens als Auftraggeber; das belastete Konto muss italienisch sein.', 'Virement interbancaire CBI : fichier SEPA portant le CUC de l\'entreprise comme donneur d\'ordre ; le compte débité doit être italien.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function f24(): array
    {
        return [
            'tbk.f24.title' => ['Modello F24 telematico', 'Modello F24 (telematic)', 'Modello F24 (telematisch)', 'Modello F24 (télématique)'],
            'tbk.f24.new' => ['Nuovo F24', 'New F24', 'Neues F24', 'Nouveau F24'],
            'tbk.f24.editor' => ['Delega F24', 'F24 delegation', 'F24-Vollmacht', 'Délégation F24'],
            'tbk.f24.taxpayer' => ['Contribuente', 'Taxpayer', 'Steuerpflichtiger', 'Contribuable'],
            'tbk.f24.fiscalCode' => ['Codice fiscale', 'Fiscal code', 'Codice fiscale', 'Code fiscal'],
            'tbk.f24.surname' => ['Cognome o ragione sociale', 'Surname or company name', 'Name oder Firma', 'Nom ou raison sociale'],
            'tbk.f24.firstName' => ['Nome', 'First name', 'Vorname', 'Prénom'],
            'tbk.f24.birthDate' => ['Data di nascita', 'Birth date', 'Geburtsdatum', 'Date de naissance'],
            'tbk.f24.birthPlace' => ['Comune di nascita', 'Birth place', 'Geburtsort', 'Lieu de naissance'],
            'tbk.f24.province' => ['Provincia', 'Province', 'Provinz', 'Province'],
            'tbk.f24.paymentDate' => ['Data di pagamento', 'Payment date', 'Zahlungsdatum', 'Date de paiement'],
            'tbk.f24.section' => ['Sezione', 'Section', 'Abschnitt', 'Section'],
            'tbk.f24.taxCode' => ['Codice tributo / causale', 'Tax code / reason', 'Steuercode / Kausal', 'Code tribut / motif'],
            'tbk.f24.institution' => ['Codice ente / sede', 'Institution code', 'Amtscode', 'Code organisme'],
            'tbk.f24.reference' => ['Numero di riferimento', 'Reference number', 'Bezugsnummer', 'Numéro de référence'],
            'tbk.f24.period' => ['Periodo (MMAAAA)', 'Period (MMYYYY)', 'Zeitraum (MMJJJJ)', 'Période (MMAAAA)'],
            'tbk.f24.year' => ['Anno', 'Year', 'Jahr', 'Année'],
            'tbk.f24.debit' => ['Importi a debito versati', 'Debit amounts paid', 'Zu zahlende Beträge', 'Montants dus'],
            'tbk.f24.credit' => ['Importi a credito compensati', 'Credit amounts offset', 'Verrechnete Guthaben', 'Crédits compensés'],
            'tbk.f24.addLine' => ['Aggiungi riga', 'Add line', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'tbk.f24.totalDebit' => ['Totale a debito', 'Total debit', 'Total Soll', 'Total débit'],
            'tbk.f24.totalCredit' => ['Totale a credito', 'Total credit', 'Total Haben', 'Total crédit'],
            'tbk.f24.totalPayable' => ['Saldo da versare', 'Balance to pay', 'Zu zahlender Saldo', 'Solde à payer'],
            'tbk.f24.balanced' => ['Debiti e crediti compensati sono in equilibrio.', 'Debits and offset credits are balanced.', 'Soll und verrechnete Guthaben sind ausgeglichen.', 'Débits et crédits compensés sont équilibrés.'],
            'tbk.f24.zeroBalance' => ['F24 a saldo zero: i crediti compensano esattamente i debiti.', 'Zero balance F24: the credits offset the debits exactly.', 'F24 mit Saldo null: die Guthaben decken die Schulden genau.', 'F24 à solde nul : les crédits compensent exactement les débits.'],
            'tbk.f24.creditExceedsDebit' => ['I crediti compensati superano i debiti.', 'The offset credits exceed the debits.', 'Die verrechneten Guthaben übersteigen die Schulden.', 'Les crédits compensés dépassent les débits.'],
            'tbk.f24.invalid' => ['L\'F24 non è valido', 'The F24 is not valid', 'Das F24 ist ungültig', 'Le F24 n\'est pas valide'],
            'tbk.f24.validate' => ['Valida', 'Validate', 'Validieren', 'Valider'],
            'tbk.f24.generate' => ['Genera file telematico', 'Generate telematic file', 'Telematische Datei erzeugen', 'Générer le fichier télématique'],
            'tbk.f24.download' => ['Scarica file', 'Download file', 'Datei herunterladen', 'Télécharger le fichier'],
            'tbk.f24.markPaid' => ['Segna come pagato', 'Mark as paid', 'Als bezahlt markieren', 'Marquer comme payé'],
            'tbk.f24.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'tbk.f24.status.validated' => ['Validato', 'Validated', 'Validiert', 'Validé'],
            'tbk.f24.status.generated' => ['File generato', 'File generated', 'Datei erzeugt', 'Fichier généré'],
            'tbk.f24.status.paid' => ['Pagato', 'Paid', 'Bezahlt', 'Payé'],
            'tbk.f24.sectionName.erario' => ['Erario', 'Tax authority (Erario)', 'Steuerbehörde (Erario)', 'Trésor (Erario)'],
            'tbk.f24.sectionName.inps' => ['INPS', 'INPS', 'INPS', 'INPS'],
            'tbk.f24.sectionName.regioni' => ['Regioni ed enti locali', 'Regions and local bodies', 'Regionen und lokale Körperschaften', 'Régions et collectivités locales'],
            'tbk.f24.sectionName.imu' => ['IMU e tributi locali', 'IMU and local taxes', 'IMU und lokale Steuern', 'IMU et taxes locales'],
            'tbk.f24.sectionName.inail' => ['INAIL', 'INAIL', 'INAIL', 'INAIL'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function statementHub(): array
    {
        return [
            'tbk.hub.tab.upload' => ['Importa file', 'Import file', 'Datei importieren', 'Importer un fichier'],
            'tbk.hub.tab.openBanking' => ['PSD2 / SIX bLink', 'PSD2 / SIX bLink', 'PSD2 / SIX bLink', 'PSD2 / SIX bLink'],
            'tbk.hub.tab.batches' => ['Estratti importati', 'Imported statements', 'Importierte Kontoauszüge', 'Relevés importés'],
            'tbk.hub.upload.title' => ['Importa estratto conto o notifica di accredito', 'Import a statement or credit notification', 'Kontoauszug oder Gutschriftsanzeige importieren', 'Importer un relevé ou un avis de crédit'],
            'tbk.hub.upload.hint' => ['Trascina qui camt.053, camt.054, CBI o CSV (max 10 MB)', 'Drop camt.053, camt.054, CBI or CSV here (max 10 MB)', 'camt.053, camt.054, CBI oder CSV hier ablegen (max. 10 MB)', 'Déposez ici camt.053, camt.054, CBI ou CSV (max 10 Mo)'],
            'tbk.hub.format' => ['Formato', 'Format', 'Format', 'Format'],
            'tbk.hub.formatName.auto' => ['Riconoscimento automatico', 'Detect automatically', 'Automatisch erkennen', 'Détection automatique'],
            'tbk.hub.formatName.camt053' => ['ISO 20022 camt.053 (estratto conto)', 'ISO 20022 camt.053 (statement)', 'ISO 20022 camt.053 (Kontoauszug)', 'ISO 20022 camt.053 (relevé)'],
            'tbk.hub.formatName.camt054' => ['ISO 20022 camt.054 (notifica di accredito)', 'ISO 20022 camt.054 (credit notification)', 'ISO 20022 camt.054 (Gutschriftsanzeige)', 'ISO 20022 camt.054 (avis de crédit)'],
            'tbk.hub.formatName.cbi' => ['CBI (testo a 120 caratteri)', 'CBI (120 character text)', 'CBI (Text mit 120 Zeichen)', 'CBI (texte de 120 caractères)'],
            'tbk.hub.formatName.csv' => ['CSV della banca', 'Bank CSV', 'Bank-CSV', 'CSV de la banque'],
            'tbk.hub.autoReconcile' => ['Riconcilia subito i movimenti', 'Reconcile the movements right away', 'Bewegungen sofort abgleichen', 'Rapprocher aussitôt les mouvements'],
            'tbk.hub.importing' => ['Importazione in corso…', 'Importing…', 'Import läuft…', 'Import en cours…'],
            'tbk.hub.invalidFile' => ['File non valido: estensione non supportata o dimensione oltre 10 MB.', 'Invalid file: unsupported extension or larger than 10 MB.', 'Ungültige Datei: nicht unterstützte Endung oder grösser als 10 MB.', 'Fichier invalide : extension non prise en charge ou supérieur à 10 Mo.'],
            'tbk.hub.importFailed' => ['Importazione non riuscita', 'The import failed', 'Der Import ist fehlgeschlagen', 'L\'import a échoué'],
            'tbk.hub.csv.title' => ['Mappatura delle colonne CSV', 'CSV column mapping', 'Zuordnung der CSV-Spalten', 'Mappage des colonnes CSV'],
            'tbk.hub.csv.profile' => ['Profilo salvato', 'Saved profile', 'Gespeichertes Profil', 'Profil enregistré'],
            'tbk.hub.csv.iban' => ['IBAN del conto', 'Account IBAN', 'IBAN des Kontos', 'IBAN du compte'],
            'tbk.hub.csv.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'tbk.hub.csv.delimiter' => ['Separatore', 'Delimiter', 'Trennzeichen', 'Séparateur'],
            'tbk.hub.csv.dateFormat' => ['Formato data (PHP)', 'Date format (PHP)', 'Datumsformat (PHP)', 'Format de date (PHP)'],
            'tbk.hub.csv.decimal' => ['Decimali', 'Decimal', 'Dezimal', 'Décimale'],
            'tbk.hub.csv.thousands' => ['Migliaia', 'Thousands', 'Tausender', 'Milliers'],
            'tbk.hub.csv.hasHeader' => ['La prima riga è l\'intestazione', 'The first row is the header', 'Die erste Zeile ist die Kopfzeile', 'La première ligne est l\'en-tête'],
            'tbk.hub.csv.columnsHeaderHint' => ['Indica il nome della colonna come scritto nell\'intestazione.', 'Enter the column name as written in the header.', 'Spaltennamen wie in der Kopfzeile angeben.', 'Indiquez le nom de la colonne tel qu\'écrit dans l\'en-tête.'],
            'tbk.hub.csv.columnsPositionHint' => ['Indica la posizione della colonna (1 = prima colonna).', 'Enter the column position (1 = first column).', 'Spaltenposition angeben (1 = erste Spalte).', 'Indiquez la position de la colonne (1 = première colonne).'],
            'tbk.hub.csv.field.booking_date' => ['Data contabile (obbligatoria)', 'Booking date (required)', 'Buchungsdatum (erforderlich)', 'Date comptable (obligatoire)'],
            'tbk.hub.csv.field.value_date' => ['Data valuta', 'Value date', 'Valutadatum', 'Date de valeur'],
            'tbk.hub.csv.field.amount' => ['Importo con segno', 'Signed amount', 'Betrag mit Vorzeichen', 'Montant signé'],
            'tbk.hub.csv.field.debit' => ['Dare (uscite)', 'Debit (outflows)', 'Soll (Abgänge)', 'Débit (sorties)'],
            'tbk.hub.csv.field.credit' => ['Avere (entrate)', 'Credit (inflows)', 'Haben (Eingänge)', 'Crédit (entrées)'],
            'tbk.hub.csv.field.fee' => ['Commissioni', 'Fees', 'Gebühren', 'Frais'],
            'tbk.hub.csv.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'tbk.hub.csv.field.reference' => ['Riferimento', 'Reference', 'Referenz', 'Référence'],
            'tbk.hub.csv.field.qr_reference' => ['Riferimento QR / SCOR', 'QR / SCOR reference', 'QR-/SCOR-Referenz', 'Référence QR / SCOR'],
            'tbk.hub.csv.field.counterpart_name' => ['Controparte', 'Counterparty', 'Gegenpartei', 'Contrepartie'],
            'tbk.hub.csv.field.counterpart_iban' => ['IBAN controparte', 'Counterparty IBAN', 'IBAN der Gegenpartei', 'IBAN de la contrepartie'],
            'tbk.hub.csv.field.entry_reference' => ['Riferimento della banca', 'Bank reference', 'Bankreferenz', 'Référence de la banque'],
            'tbk.hub.csv.profileName' => ['Nome del profilo', 'Profile name', 'Profilname', 'Nom du profil'],
            'tbk.hub.csv.saveProfile' => ['Salva profilo', 'Save profile', 'Profil speichern', 'Enregistrer le profil'],
            'tbk.hub.csv.incomplete' => ['Mappatura incompleta: servono IBAN, data contabile e importo (o dare/avere).', 'Incomplete mapping: the IBAN, booking date and amount (or debit/credit) are required.', 'Unvollständige Zuordnung: IBAN, Buchungsdatum und Betrag (oder Soll/Haben) sind erforderlich.', 'Mappage incomplet : IBAN, date comptable et montant (ou débit/crédit) sont requis.'],
            'tbk.hub.summary.title' => ['Esito dell\'importazione', 'Import result', 'Importergebnis', 'Résultat de l\'import'],
            'tbk.hub.summary.entries' => ['Movimenti letti', 'Entries read', 'Gelesene Bewegungen', 'Mouvements lus'],
            'tbk.hub.summary.stored' => ['Salvati', 'Stored', 'Gespeichert', 'Enregistrés'],
            'tbk.hub.summary.duplicates' => ['Duplicati scartati', 'Duplicates skipped', 'Duplikate übersprungen', 'Doublons ignorés'],
            'tbk.hub.summary.reconciled' => ['Riconciliati', 'Reconciled', 'Abgeglichen', 'Rapprochés'],
            'tbk.hub.ob.driver.refused' => ['Nessun provider configurato', 'No provider configured', 'Kein Anbieter konfiguriert', 'Aucun fournisseur configuré'],
            'tbk.hub.ob.driver.local' => ['Simulatore locale', 'Local simulator', 'Lokaler Simulator', 'Simulateur local'],
            'tbk.hub.ob.driver.sandbox' => ['Banca di prova (sandbox)', 'Test bank (sandbox)', 'Testbank (Sandbox)', 'Banque de test (sandbox)'],
            'tbk.hub.ob.driver.psd2' => ['PSD2 (NextGenPSD2)', 'PSD2 (NextGenPSD2)', 'PSD2 (NextGenPSD2)', 'PSD2 (NextGenPSD2)'],
            'tbk.hub.ob.driver.blink' => ['SIX bLink', 'SIX bLink', 'SIX bLink', 'SIX bLink'],
            'tbk.hub.ob.available' => ['Il provider è disponibile.', 'The provider is available.', 'Der Anbieter ist verfügbar.', 'Le fournisseur est disponible.'],
            'tbk.hub.ob.unavailable' => ['Il provider non è utilizzabile: servono credenziali e URL nella configurazione del server.', 'The provider cannot be used: credentials and URLs are missing in the server configuration.', 'Der Anbieter ist nicht nutzbar: Zugangsdaten und URLs fehlen in der Serverkonfiguration.', 'Le fournisseur est inutilisable : identifiants et URL absents de la configuration du serveur.'],
            'tbk.hub.ob.connections' => ['Connessioni ai conti', 'Account connections', 'Kontoverbindungen', 'Connexions aux comptes'],
            'tbk.hub.ob.ibanPlaceholder' => ['IBAN del conto da collegare', 'IBAN of the account to connect', 'IBAN des zu verbindenden Kontos', 'IBAN du compte à connecter'],
            'tbk.hub.ob.connect' => ['Collega conto', 'Connect account', 'Konto verbinden', 'Connecter le compte'],
            'tbk.hub.ob.from' => ['Dal', 'From', 'Von', 'Du'],
            'tbk.hub.ob.to' => ['Al', 'To', 'Bis', 'Au'],
            'tbk.hub.ob.provider' => ['Provider', 'Provider', 'Anbieter', 'Fournisseur'],
            'tbk.hub.ob.lastSync' => ['Ultima sincronizzazione', 'Last sync', 'Letzte Synchronisierung', 'Dernière synchronisation'],
            'tbk.hub.ob.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'tbk.hub.ob.inactive' => ['Non attiva', 'Inactive', 'Inaktiv', 'Inactive'],
            'tbk.hub.ob.sync' => ['Sincronizza', 'Synchronise', 'Synchronisieren', 'Synchroniser'],
            'tbk.hub.ob.runs' => ['Cronologia sincronizzazioni', 'Synchronisation history', 'Synchronisierungsverlauf', 'Historique des synchronisations'],
            'tbk.hub.ob.period' => ['Periodo', 'Period', 'Zeitraum', 'Période'],
            'tbk.hub.ob.fetched' => ['Letti', 'Fetched', 'Abgerufen', 'Récupérés'],
            'tbk.hub.ob.imported' => ['Importati', 'Imported', 'Importiert', 'Importés'],
            'tbk.hub.ob.duplicates' => ['Duplicati', 'Duplicates', 'Duplikate', 'Doublons'],
            'tbk.hub.ob.run.running' => ['In corso', 'Running', 'Läuft', 'En cours'],
            'tbk.hub.ob.run.completed' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminée'],
            'tbk.hub.ob.run.failed' => ['Fallita', 'Failed', 'Fehlgeschlagen', 'Échouée'],
            'tbk.hub.batches.title' => ['Estratti conto importati', 'Imported statements', 'Importierte Kontoauszüge', 'Relevés importés'],
            'tbk.hub.batches.date' => ['Data', 'Date', 'Datum', 'Date'],
            'tbk.hub.batches.entries' => ['Movimenti', 'Entries', 'Bewegungen', 'Mouvements'],
            'tbk.hub.batches.inspect' => ['Vedi movimenti', 'View entries', 'Bewegungen anzeigen', 'Voir les mouvements'],
            'tbk.hub.batches.entriesTitle' => ['Movimenti dell\'estratto', 'Statement entries', 'Bewegungen des Auszugs', 'Mouvements du relevé'],
            'tbk.hub.batches.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'tbk.hub.batches.counterparty' => ['Controparte', 'Counterparty', 'Gegenpartei', 'Contrepartie'],
            'tbk.hub.batches.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function smart(): array
    {
        return [
            'tbk.smart.title' => ['Banco di lavoro della riconciliazione', 'Reconciliation workbench', 'Abgleich-Arbeitsplatz', 'Poste de rapprochement'],
            'tbk.smart.stat.open' => ['Movimenti aperti', 'Open entries', 'Offene Bewegungen', 'Mouvements ouverts'],
            'tbk.smart.stat.withProposals' => ['Con proposta', 'With a proposal', 'Mit Vorschlag', 'Avec proposition'],
            'tbk.smart.stat.highConfidence' => ['Alta confidenza (≥ 95%)', 'High confidence (≥ 95%)', 'Hohe Konfidenz (≥ 95 %)', 'Confiance élevée (≥ 95 %)'],
            'tbk.smart.stat.reconciled' => ['Riconciliati', 'Reconciled', 'Abgeglichen', 'Rapprochés'],
            'tbk.smart.stat.applied' => ['Proposte applicate', 'Applied proposals', 'Angewendete Vorschläge', 'Propositions appliquées'],
            'tbk.smart.filter.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'tbk.smart.filter.direction' => ['Direzione', 'Direction', 'Richtung', 'Sens'],
            'tbk.smart.filter.confidence' => ['Confidenza minima', 'Minimum confidence', 'Mindestkonfidenz', 'Confiance minimale'],
            'tbk.smart.filter.statement' => ['Estratto conto', 'Statement', 'Kontoauszug', 'Relevé'],
            'tbk.smart.filter.search' => ['Cerca descrizione, controparte o riferimento', 'Search description, counterparty or reference', 'Beschreibung, Gegenpartei oder Referenz suchen', 'Rechercher description, contrepartie ou référence'],
            'tbk.smart.open' => ['Aperti', 'Open', 'Offen', 'Ouverts'],
            'tbk.smart.reconciled' => ['Riconciliati', 'Reconciled', 'Abgeglichen', 'Rapprochés'],
            'tbk.smart.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'tbk.smart.credit' => ['Entrate', 'Inflows', 'Eingänge', 'Entrées'],
            'tbk.smart.debit' => ['Uscite', 'Outflows', 'Abgänge', 'Sorties'],
            'tbk.smart.run' => ['Calcola proposte', 'Compute proposals', 'Vorschläge berechnen', 'Calculer les propositions'],
            'tbk.smart.runApply' => ['Calcola e applica le sicure', 'Compute and apply the safe ones', 'Berechnen und sichere anwenden', 'Calculer et appliquer les sûres'],
            'tbk.smart.runDone' => ['Calcolo completato.', 'Computation completed.', 'Berechnung abgeschlossen.', 'Calcul terminé.'],
            'tbk.smart.applied' => ['Abbinamento applicato: pagamento, commissioni e prima nota registrati.', 'Match applied: payment, charges and journal entries posted.', 'Zuordnung angewendet: Zahlung, Gebühren und Buchungen verbucht.', 'Rapprochement appliqué : paiement, frais et écritures comptabilisés.'],
            'tbk.smart.unmatched' => ['Riconciliazione annullata.', 'Reconciliation undone.', 'Abgleich rückgängig gemacht.', 'Rapprochement annulé.'],
            'tbk.smart.col.date' => ['Data', 'Date', 'Datum', 'Date'],
            'tbk.smart.col.counterparty' => ['Controparte', 'Counterparty', 'Gegenpartei', 'Contrepartie'],
            'tbk.smart.col.description' => ['Descrizione / riferimento', 'Description / reference', 'Beschreibung / Referenz', 'Description / référence'],
            'tbk.smart.col.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'tbk.smart.col.match' => ['Migliore proposta', 'Best proposal', 'Bester Vorschlag', 'Meilleure proposition'],
            'tbk.smart.fee' => ['Commissioni', 'Charges', 'Gebühren', 'Frais'],
            'tbk.smart.fx' => ['Differenza cambio', 'Exchange difference', 'Kursdifferenz', 'Écart de change'],
            'tbk.smart.residual' => ['Residuo aperto', 'Open residual', 'Offener Rest', 'Reste ouvert'],
            'tbk.smart.settles' => ['Salda', 'Settles', 'Begleicht', 'Solde'],
            'tbk.smart.noProposal' => ['Nessuna proposta', 'No proposal', 'Kein Vorschlag', 'Aucune proposition'],
            'tbk.smart.apply' => ['Applica', 'Apply', 'Anwenden', 'Appliquer'],
            'tbk.smart.reject' => ['Scarta', 'Reject', 'Verwerfen', 'Rejeter'],
            'tbk.smart.refresh' => ['Ricalcola', 'Recompute', 'Neu berechnen', 'Recalculer'],
            'tbk.smart.unmatch' => ['Annulla abbinamento', 'Undo match', 'Zuordnung aufheben', 'Annuler le rapprochement'],
            'tbk.smart.unmatchTitle' => ['Annullare la riconciliazione?', 'Undo the reconciliation?', 'Abgleich rückgängig machen?', 'Annuler le rapprochement ?'],
            'tbk.smart.unmatchBody' => ['I pagamenti e le scritture di prima nota collegati saranno stornati e il movimento tornerà aperto.', 'The linked payments and journal entries will be reversed and the entry will be open again.', 'Die verknüpften Zahlungen und Buchungen werden storniert und die Bewegung wird wieder offen.', 'Les paiements et écritures liés seront extournés et le mouvement redeviendra ouvert.'],
            'tbk.rule.structured_reference' => ['Riferimento strutturato (QRR / SCOR)', 'Structured reference (QRR / SCOR)', 'Strukturierte Referenz (QRR / SCOR)', 'Référence structurée (QRR / SCOR)'],
            'tbk.rule.amount_counterparty' => ['Importo esatto e controparte', 'Exact amount and counterparty', 'Exakter Betrag und Gegenpartei', 'Montant exact et contrepartie'],
            'tbk.rule.fuzzy_invoice' => ['Numero fattura e descrizione', 'Invoice number and description', 'Rechnungsnummer und Beschreibung', 'Numéro de facture et description'],
            'tbk.kind.exact' => ['Importo corrispondente', 'Matching amount', 'Passender Betrag', 'Montant correspondant'],
            'tbk.kind.fee_tolerance' => ['Con commissioni bancarie', 'With bank charges', 'Mit Bankgebühren', 'Avec frais bancaires'],
            'tbk.kind.fx' => ['Con differenza cambio', 'With exchange difference', 'Mit Kursdifferenz', 'Avec écart de change'],
            'tbk.kind.partial' => ['Pagamento parziale', 'Partial payment', 'Teilzahlung', 'Paiement partiel'],
            'tbk.reason.structured_reference' => ['Riferimento identico', 'Identical reference', 'Identische Referenz', 'Référence identique'],
            'tbk.reason.exact_amount' => ['Importo esatto', 'Exact amount', 'Exakter Betrag', 'Montant exact'],
            'tbk.reason.tax_id' => ['P.IVA / CF nella causale', 'VAT / fiscal code in the description', 'USt-/Steuernummer im Text', 'N° TVA / code fiscal dans le motif'],
            'tbk.reason.iban' => ['IBAN della controparte', 'Counterparty IBAN', 'IBAN der Gegenpartei', 'IBAN de la contrepartie'],
            'tbk.reason.name' => ['Ragione sociale simile', 'Similar company name', 'Ähnliche Firmenbezeichnung', 'Raison sociale similaire'],
            'tbk.reason.ambiguous' => ['Più documenti possibili', 'Several possible documents', 'Mehrere mögliche Belege', 'Plusieurs documents possibles'],
            'tbk.reason.invoice_number' => ['Numero fattura nella causale', 'Invoice number in the description', 'Rechnungsnummer im Text', 'Numéro de facture dans le motif'],
            'tbk.reason.invoice_number_approximate' => ['Numero fattura quasi identico', 'Nearly identical invoice number', 'Fast identische Rechnungsnummer', 'Numéro de facture quasi identique'],
            'tbk.reason.bank_fee_tolerance' => ['Differenza entro la soglia commissioni', 'Difference within the fee tolerance', 'Differenz innerhalb der Gebührentoleranz', 'Écart dans la tolérance de frais'],
            'tbk.reason.fx_difference' => ['Differenza entro la soglia di cambio', 'Difference within the exchange tolerance', 'Differenz innerhalb der Kurstoleranz', 'Écart dans la tolérance de change'],
            'tbk.reason.partial_payment' => ['Importo inferiore al dovuto', 'Amount below the balance due', 'Betrag unter dem Fälligen', 'Montant inférieur au dû'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function f24Codes(): array
    {
        $months = [
            ['gennaio', 'January', 'Januar', 'janvier'], ['febbraio', 'February', 'Februar', 'février'], ['marzo', 'March', 'März', 'mars'],
            ['aprile', 'April', 'April', 'avril'], ['maggio', 'May', 'Mai', 'mai'], ['giugno', 'June', 'Juni', 'juin'],
            ['luglio', 'July', 'Juli', 'juillet'], ['agosto', 'August', 'August', 'août'], ['settembre', 'September', 'September', 'septembre'],
            ['ottobre', 'October', 'Oktober', 'octobre'], ['novembre', 'November', 'November', 'novembre'], ['dicembre', 'December', 'Dezember', 'décembre'],
        ];
        $codes = [
            'erario.1001' => ['Ritenute su redditi di lavoro dipendente e assimilati', 'Withholding on employment and similar income', 'Quellensteuer auf Löhne und ähnliche Einkünfte', 'Retenues sur revenus salariaux et assimilés'],
            'erario.1038' => ['Ritenute su provvigioni di agenti e rappresentanti', 'Withholding on agent and representative commissions', 'Quellensteuer auf Provisionen von Vertretern', 'Retenues sur commissions d\'agents et représentants'],
            'erario.1040' => ['Ritenute su redditi di lavoro autonomo', 'Withholding on self-employment income', 'Quellensteuer auf selbständige Einkünfte', 'Retenues sur revenus de travail indépendant'],
            'erario.1668' => ['Interessi sul pagamento tardivo o ravvedimento', 'Interest on late payment or voluntary correction', 'Zinsen auf verspätete Zahlung oder Selbstkorrektur', 'Intérêts sur paiement tardif ou régularisation'],
            'erario.2001' => ['IRES - acconto prima rata', 'IRES - first advance', 'IRES - erste Akontozahlung', 'IRES - premier acompte'],
            'erario.2002' => ['IRES - acconto seconda rata o unica soluzione', 'IRES - second or single advance', 'IRES - zweite oder einmalige Akontozahlung', 'IRES - second acompte ou unique'],
            'erario.2003' => ['IRES - saldo', 'IRES - balance', 'IRES - Saldo', 'IRES - solde'],
            'erario.4001' => ['IRPEF - saldo', 'IRPEF - balance', 'IRPEF - Saldo', 'IRPEF - solde'],
            'erario.4033' => ['IRPEF - acconto prima rata', 'IRPEF - first advance', 'IRPEF - erste Akontozahlung', 'IRPEF - premier acompte'],
            'erario.4034' => ['IRPEF - acconto seconda rata o unica soluzione', 'IRPEF - second or single advance', 'IRPEF - zweite oder einmalige Akontozahlung', 'IRPEF - second acompte ou unique'],
            'erario.6013' => ['IVA - acconto', 'IVA - advance', 'IVA - Akontozahlung', 'IVA - acompte'],
            'erario.6031' => ['IVA - primo trimestre', 'IVA - first quarter', 'IVA - erstes Quartal', 'IVA - premier trimestre'],
            'erario.6032' => ['IVA - secondo trimestre', 'IVA - second quarter', 'IVA - zweites Quartal', 'IVA - deuxième trimestre'],
            'erario.6033' => ['IVA - terzo trimestre', 'IVA - third quarter', 'IVA - drittes Quartal', 'IVA - troisième trimestre'],
            'erario.6034' => ['IVA - quarto trimestre', 'IVA - fourth quarter', 'IVA - viertes Quartal', 'IVA - quatrième trimestre'],
            'erario.6099' => ['IVA - saldo annuale', 'IVA - annual balance', 'IVA - Jahressaldo', 'IVA - solde annuel'],
            'regioni.3800' => ['IRAP - saldo', 'IRAP - balance', 'IRAP - Saldo', 'IRAP - solde'],
            'regioni.3801' => ['Addizionale regionale IRPEF - saldo', 'Regional IRPEF surcharge - balance', 'Regionaler IRPEF-Zuschlag - Saldo', 'Surtaxe régionale IRPEF - solde'],
            'regioni.3802' => ['Addizionale regionale IRPEF - acconto', 'Regional IRPEF surcharge - advance', 'Regionaler IRPEF-Zuschlag - Akonto', 'Surtaxe régionale IRPEF - acompte'],
            'regioni.3812' => ['IRAP - acconto prima rata', 'IRAP - first advance', 'IRAP - erste Akontozahlung', 'IRAP - premier acompte'],
            'regioni.3813' => ['IRAP - acconto seconda rata o unica soluzione', 'IRAP - second or single advance', 'IRAP - zweite oder einmalige Akontozahlung', 'IRAP - second acompte ou unique'],
            'regioni.3843' => ['Addizionale comunale IRPEF - acconto', 'Municipal IRPEF surcharge - advance', 'Kommunaler IRPEF-Zuschlag - Akonto', 'Surtaxe communale IRPEF - acompte'],
            'regioni.3844' => ['Addizionale comunale IRPEF - saldo', 'Municipal IRPEF surcharge - balance', 'Kommunaler IRPEF-Zuschlag - Saldo', 'Surtaxe communale IRPEF - solde'],
            'imu.3912' => ['IMU - abitazione principale e pertinenze', 'IMU - main home and annexes', 'IMU - Hauptwohnsitz und Nebengebäude', 'IMU - résidence principale et annexes'],
            'imu.3913' => ['IMU - fabbricati rurali ad uso strumentale', 'IMU - rural buildings for business use', 'IMU - landwirtschaftliche Betriebsgebäude', 'IMU - bâtiments ruraux à usage instrumental'],
            'imu.3914' => ['IMU - terreni', 'IMU - land', 'IMU - Grundstücke', 'IMU - terrains'],
            'imu.3916' => ['IMU - aree fabbricabili', 'IMU - building land', 'IMU - Bauland', 'IMU - terrains constructibles'],
            'imu.3918' => ['IMU - altri fabbricati', 'IMU - other buildings', 'IMU - übrige Gebäude', 'IMU - autres bâtiments'],
            'imu.3925' => ['IMU - immobili ad uso produttivo (quota Stato)', 'IMU - industrial property (State share)', 'IMU - Gewerbeimmobilien (Staatsanteil)', 'IMU - immeubles à usage productif (part de l\'État)'],
            'imu.3930' => ['IMU - immobili ad uso produttivo (quota comune)', 'IMU - industrial property (municipal share)', 'IMU - Gewerbeimmobilien (Gemeindeanteil)', 'IMU - immeubles à usage productif (part de la commune)'],
            'inps.DM10' => ['Contributi INPS dei lavoratori dipendenti', 'INPS contributions of employees', 'INPS-Beiträge der Arbeitnehmer', 'Cotisations INPS des salariés'],
            'inps.CXX' => ['Contributi INPS gestione separata', 'INPS separate management contributions', 'INPS-Beiträge der Gestione separata', 'Cotisations INPS gestion séparée'],
        ];

        $definitions = [];

        foreach ($codes as $key => $text) {
            $definitions['treasury.f24.code.'.$key] = $text;
        }

        foreach ($months as $index => $month) {
            $code = (string) (6001 + $index);
            $definitions['treasury.f24.code.erario.'.$code] = [
                "IVA - versamento mensile di {$month[0]}",
                "IVA - monthly payment for {$month[1]}",
                "IVA - Monatszahlung {$month[2]}",
                "IVA - versement mensuel de {$month[3]}",
            ];
        }

        return $definitions;
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
