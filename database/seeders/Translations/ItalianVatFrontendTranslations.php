<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the Italian VAT compliance module (Mod 029): VAT registers and their definitive print, daily receipts and
 * ventilation, goods on account of third parties, plafond of the habitual exporters, quarterly LIPE, annual VAT reconciliation,
 * Certificazione Unica and F24, plus the codes the API reports (italianVat.error.<code>, italianVat.diagnostic.<code>). Each
 * entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Placeholders are written {name}.
 */
final class ItalianVatFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.ITALIAN_VAT' => ['IVA italiana', 'Italian VAT', 'Italienische MwSt', 'TVA italienne'],
            'NAV.ITALIAN_VAT_REGISTERS' => ['Registri IVA', 'VAT registers', 'MwSt-Register', 'Registres TVA'],
            'NAV.ITALIAN_VAT_PLAFOND' => ['Plafond e lettere d’intento', 'Plafond and declarations of intent', 'Plafond und Absichtserklärungen', 'Plafond et déclarations d’intention'],
            'NAV.ITALIAN_VAT_LIPE' => ['LIPE trimestrale', 'Quarterly LIPE', 'Quartals-LIPE', 'LIPE trimestrielle'],
            'NAV.ITALIAN_VAT_ANNUAL' => ['IVA annuale', 'Annual VAT', 'Jahres-MwSt', 'TVA annuelle'],
            'NAV.ITALIAN_VAT_CU' => ['Certificazione Unica', 'Certificazione Unica', 'Certificazione Unica', 'Certificazione Unica'],
            'PAGE_TITLE.ITALIAN_VAT_REGISTERS' => ['Registri IVA, corrispettivi e merci c/terzi', 'VAT registers, daily receipts and third-party goods', 'MwSt-Register, Tageseinnahmen und Fremdwaren', 'Registres TVA, recettes journalières et marchandises de tiers'],
            'PAGE_TITLE.ITALIAN_VAT_PLAFOND' => ['Plafond esportatori abituali', 'Habitual exporters plafond', 'Plafond der Dauerexporteure', 'Plafond des exportateurs habituels'],
            'PAGE_TITLE.ITALIAN_VAT_LIPE' => ['Comunicazione liquidazioni periodiche IVA', 'Periodic VAT settlements communication', 'Mitteilung der periodischen MwSt-Abrechnungen', 'Communication des liquidations périodiques de TVA'],
            'PAGE_TITLE.ITALIAN_VAT_ANNUAL' => ['Riconciliazione IVA annuale', 'Annual VAT reconciliation', 'Jahresabstimmung der MwSt', 'Rapprochement annuel de la TVA'],
            'PAGE_TITLE.ITALIAN_VAT_CU' => ['Certificazione Unica e versamenti F24', 'Certificazione Unica and F24 payments', 'Certificazione Unica und F24-Zahlungen', 'Certificazione Unica et paiements F24'],

            'italianVat.registerType.sales' => ['Registro IVA vendite', 'Sales VAT register', 'MwSt-Verkaufsregister', 'Registre TVA des ventes'],
            'italianVat.registerType.purchases' => ['Registro IVA acquisti', 'Purchases VAT register', 'MwSt-Einkaufsregister', 'Registre TVA des achats'],
            'italianVat.registerType.receipts' => ['Registro dei corrispettivi', 'Daily receipts register', 'Tageseinnahmenregister', 'Registre des recettes journalières'],
            'italianVat.registerType.third_party_goods' => ['Merci c/conto terzi', 'Goods on account of third parties', 'Fremdwaren', 'Marchandises pour compte de tiers'],
            'italianVat.documentKind.ordinary' => ['Fattura ordinaria', 'Ordinary invoice', 'Ordentliche Rechnung', 'Facture ordinaire'],
            'italianVat.documentKind.deferred' => ['Fattura differita', 'Deferred invoice', 'Sammelrechnung', 'Facture différée'],
            'italianVat.documentKind.credit_note' => ['Nota di credito', 'Credit note', 'Gutschrift', 'Note de crédit'],
            'italianVat.documentKind.debit_note' => ['Nota di debito', 'Debit note', 'Belastungsanzeige', 'Note de débit'],
            'italianVat.documentKind.self_invoice' => ['Autofattura', 'Self-invoice', 'Eigenrechnung', 'Autofacture'],
            'italianVat.documentKind.reverse_charge' => ['Inversione contabile', 'Reverse charge', 'Reverse Charge', 'Autoliquidation'],
            'italianVat.register.protocol' => ['Protocollo', 'Protocol', 'Protokoll', 'Protocole'],
            'italianVat.register.taxable' => ['Imponibile', 'Taxable amount', 'Bemessungsgrundlage', 'Base imposable'],
            'italianVat.register.vat' => ['IVA', 'VAT', 'MwSt', 'TVA'],
            'italianVat.register.deductibleVat' => ['IVA detraibile', 'Deductible VAT', 'Abziehbare MwSt', 'TVA déductible'],
            'italianVat.register.nonDeductibleVat' => ['IVA indetraibile', 'Non-deductible VAT', 'Nicht abziehbare MwSt', 'TVA non déductible'],
            'italianVat.register.proRata' => ['Pro-rata di detrazione', 'Deduction pro-rata', 'Pro-rata-Abzug', 'Prorata de déduction'],
            'italianVat.register.byRate' => ['Totali per aliquota', 'Totals by rate', 'Summen je Satz', 'Totaux par taux'],
            'italianVat.register.byNature' => ['Totali per natura', 'Totals by nature', 'Summen je Art', 'Totaux par nature'],
            'italianVat.register.print' => ['Stampa definitiva', 'Definitive print', 'Endgültiger Druck', 'Impression définitive'],
            'italianVat.register.printConfirm' => ['Dopo la stampa definitiva le righe non sono più modificabili. Procedere?', 'After the definitive print the lines can no longer be changed. Continue?', 'Nach dem endgültigen Druck können die Zeilen nicht mehr geändert werden. Fortfahren?', 'Après l’impression définitive les lignes ne sont plus modifiables. Continuer ?'],
            'italianVat.register.printed' => ['Registro stampato', 'Register printed', 'Register gedruckt', 'Registre imprimé'],
            'italianVat.register.stamp' => ['Bollatura virtuale', 'Virtual stamp', 'Virtuelle Stempelung', 'Cachet virtuel'],
            'italianVat.register.pages' => ['Pagine {from}-{to}', 'Pages {from}-{to}', 'Seiten {from}-{to}', 'Pages {from}-{to}'],
            'italianVat.register.verify' => ['Verifica integrità', 'Verify integrity', 'Integrität prüfen', 'Vérifier l’intégrité'],
            'italianVat.register.intact' => ['Le righe stampate non sono state alterate', 'The printed lines were not altered', 'Die gedruckten Zeilen wurden nicht verändert', 'Les lignes imprimées n’ont pas été altérées'],
            'italianVat.register.altered' => ['Le righe stampate non corrispondono più alla stampa', 'The printed lines no longer match the print', 'Die gedruckten Zeilen stimmen nicht mehr mit dem Druck überein', 'Les lignes imprimées ne correspondent plus à l’impression'],
            'italianVat.register.reverse' => ['Storna', 'Reverse', 'Stornieren', 'Contrepasser'],
            'italianVat.register.locked' => ['Bloccato dalla stampa definitiva', 'Locked by the definitive print', 'Durch den endgültigen Druck gesperrt', 'Verrouillé par l’impression définitive'],
            'italianVat.receipts.ventilation' => ['Ventilazione dei corrispettivi', 'Ventilation of the takings', 'Ventilation der Einnahmen', 'Ventilation des recettes'],
            'italianVat.receipts.grossTakings' => ['Incasso lordo', 'Gross takings', 'Bruttoeinnahmen', 'Recettes brutes'],
            'italianVat.receipts.purchaseBasis' => ['Acquisti per aliquota', 'Purchases by rate', 'Einkäufe je Satz', 'Achats par taux'],
            'italianVat.goods.balance' => ['Giacenza', 'Held', 'Bestand', 'Stock détenu'],
            'italianVat.goods.received' => ['Ricevuta', 'Received', 'Erhalten', 'Reçu'],
            'italianVat.goods.returned' => ['Restituita', 'Returned', 'Zurückgegeben', 'Restitué'],
            'italianVat.goods.processed' => ['Lavorata', 'Processed', 'Verarbeitet', 'Transformé'],
            'italianVat.goods.sold' => ['Venduta', 'Sold', 'Verkauft', 'Vendu'],

            'italianVat.plafond.title' => ['Erosione del plafond', 'Plafond erosion', 'Plafond-Verbrauch', 'Érosion du plafond'],
            'italianVat.plafond.consumed' => ['Utilizzato', 'Consumed', 'Verbraucht', 'Consommé'],
            'italianVat.plafond.residual' => ['Residuo', 'Residual', 'Rest', 'Reste'],
            'italianVat.plafond.revoke' => ['Revoca', 'Revoke', 'Widerrufen', 'Révoquer'],
            'italianVat.plafond.status.active' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'italianVat.plafond.status.exhausted' => ['Esaurita', 'Exhausted', 'Erschöpft', 'Épuisée'],
            'italianVat.plafond.status.expired' => ['Scaduta', 'Expired', 'Abgelaufen', 'Expirée'],
            'italianVat.plafond.status.revoked' => ['Revocata', 'Revoked', 'Widerrufen', 'Révoquée'],
            'italianVat.plafond.status.not_yet_valid' => ['Non ancora valida', 'Not valid yet', 'Noch nicht gültig', 'Pas encore valable'],
            'italianVat.plafond.alert.ok' => ['Plafond disponibile', 'Plafond available', 'Plafond verfügbar', 'Plafond disponible'],
            'italianVat.plafond.alert.warning' => ['Oltre l’80 % del plafond utilizzato', 'Over 80 % of the plafond used', 'Über 80 % des Plafonds verbraucht', 'Plus de 80 % du plafond utilisé'],
            'italianVat.plafond.alert.critical' => ['Plafond non più utilizzabile', 'Plafond no longer usable', 'Plafond nicht mehr nutzbar', 'Plafond inutilisable'],

            'italianVat.lipe.generate' => ['Genera LIPE', 'Generate LIPE', 'LIPE erzeugen', 'Générer la LIPE'],
            'italianVat.lipe.quarter' => ['Trimestre {quarter} del {year}', 'Quarter {quarter} of {year}', 'Quartal {quarter} von {year}', 'Trimestre {quarter} de {year}'],
            'italianVat.lipe.reconcile' => ['Riconcilia con Erario c/IVA', 'Reconcile with Erario c/IVA', 'Mit Erario c/IVA abstimmen', 'Rapprocher avec Erario c/IVA'],
            'italianVat.lipe.sign' => ['Allega firma', 'Attach signature', 'Signatur anhängen', 'Joindre la signature'],
            'italianVat.lipe.markReady' => ['Pronta per la trasmissione', 'Ready for transmission', 'Übermittlungsbereit', 'Prête pour la transmission'],
            'italianVat.lipe.download' => ['Scarica XML', 'Download XML', 'XML herunterladen', 'Télécharger le XML'],
            'italianVat.lipe.status.draft' => ['Bozza (errori nel file)', 'Draft (errors in the file)', 'Entwurf (Fehler in der Datei)', 'Brouillon (erreurs dans le fichier)'],
            'italianVat.lipe.status.validated' => ['Validata', 'Validated', 'Validiert', 'Validée'],
            'italianVat.lipe.status.signed' => ['Firmata', 'Signed', 'Signiert', 'Signée'],
            'italianVat.lipe.status.ready' => ['Pronta', 'Ready', 'Bereit', 'Prête'],
            'italianVat.lipe.status.transmitted' => ['Trasmessa', 'Transmitted', 'Übermittelt', 'Transmise'],
            'italianVat.lipe.status.rejected' => ['Scartata', 'Rejected', 'Zurückgewiesen', 'Rejetée'],
            'italianVat.lipe.reconciliation.balanced' => ['Quadra con Erario c/IVA', 'Reconciled with Erario c/IVA', 'Mit Erario c/IVA abgestimmt', 'Concorde avec Erario c/IVA'],
            'italianVat.lipe.reconciliation.unbalanced' => ['Non quadra con Erario c/IVA', 'Does not reconcile with Erario c/IVA', 'Stimmt nicht mit Erario c/IVA überein', 'Ne concorde pas avec Erario c/IVA'],

            'italianVat.annual.calculate' => ['Calcola', 'Calculate', 'Berechnen', 'Calculer'],
            'italianVat.annual.finalize' => ['Rendi definitiva', 'Finalize', 'Abschließen', 'Clôturer'],
            'italianVat.annual.debit' => ['IVA a debito dell’anno', 'Annual VAT due', 'Jahres-MwSt Schuld', 'TVA annuelle due'],
            'italianVat.annual.credit' => ['IVA a credito dell’anno', 'Annual VAT credit', 'Jahres-MwSt Guthaben', 'Crédit de TVA annuel'],
            'italianVat.annual.proRataAdjustment' => ['Rettifica pro-rata', 'Pro-rata adjustment', 'Pro-rata-Berichtigung', 'Ajustement du prorata'],
            'italianVat.annual.priorYearCredit' => ['Credito dell’anno precedente', 'Credit of the previous year', 'Guthaben des Vorjahres', 'Crédit de l’année précédente'],
            'italianVat.annual.ledgerBalance' => ['Saldo Erario c/IVA', 'Erario c/IVA balance', 'Saldo Erario c/IVA', 'Solde Erario c/IVA'],
            'italianVat.annual.reconciled' => ['Riconciliata', 'Reconciled', 'Abgestimmt', 'Rapprochée'],

            'italianVat.cu.build' => ['Prepara le certificazioni', 'Build the certifications', 'Bescheinigungen vorbereiten', 'Préparer les attestations'],
            'italianVat.cu.issue' => ['Emetti', 'Issue', 'Ausstellen', 'Émettre'],
            'italianVat.cu.file' => ['File telematico', 'Telematic file', 'Telematische Datei', 'Fichier télématique'],
            'italianVat.cu.pdf' => ['PDF', 'PDF', 'PDF', 'PDF'],
            'italianVat.cu.gross' => ['Ammontare lordo', 'Gross amount', 'Bruttobetrag', 'Montant brut'],
            'italianVat.cu.withheld' => ['Ritenute', 'Withheld tax', 'Einbehaltene Steuer', 'Retenues'],
            'italianVat.cu.contributions' => ['Contributi', 'Contributions', 'Beiträge', 'Cotisations'],
            'italianVat.cu.status.draft' => ['Da correggere', 'To correct', 'Zu korrigieren', 'À corriger'],
            'italianVat.cu.status.validated' => ['Valida', 'Valid', 'Gültig', 'Valide'],
            'italianVat.cu.status.issued' => ['Emessa', 'Issued', 'Ausgestellt', 'Émise'],
            'italianVat.f24.title' => ['Versamenti F24 delle ritenute', 'F24 payments of the withholdings', 'F24-Zahlungen der Quellensteuern', 'Paiements F24 des retenues'],
            'italianVat.f24.due' => ['Dovuto', 'Due', 'Fällig', 'Dû'],
            'italianVat.f24.paid' => ['Versato', 'Paid', 'Bezahlt', 'Payé'],
            'italianVat.f24.status.due' => ['Da versare', 'To pay', 'Zu zahlen', 'À payer'],
            'italianVat.f24.status.partial' => ['Versato in parte', 'Partly paid', 'Teilweise bezahlt', 'Partiellement payé'],
            'italianVat.f24.status.paid' => ['Versato', 'Paid', 'Bezahlt', 'Payé'],
            'italianVat.f24.status.overpaid' => ['Versato in eccesso', 'Overpaid', 'Zu viel bezahlt', 'Payé en trop'],

            'italianVat.diagnostic.settlements_not_posted' => ['{count} liquidazioni dell’anno non sono ancora contabilizzate', '{count} settlements of the year are not posted yet', '{count} Abrechnungen des Jahres sind noch nicht gebucht', '{count} liquidations de l’année ne sont pas encore comptabilisées'],
            'italianVat.diagnostic.periods_missing' => ['Mesi senza liquidazione definitiva: {periods}', 'Months without a final settlement: {periods}', 'Monate ohne endgültige Abrechnung: {periods}', 'Mois sans liquidation définitive : {periods}'],
            'italianVat.diagnostic.pro_rata_not_definitive' => ['Il pro-rata del {year} non è definitivo', 'The pro-rata of {year} is not definitive', 'Der Pro-rata-Satz von {year} ist nicht endgültig', 'Le prorata de {year} n’est pas définitif'],
            'italianVat.diagnostic.ledger_difference' => ['Differenza con Erario c/IVA: {difference}', 'Difference with Erario c/IVA: {difference}', 'Differenz zu Erario c/IVA: {difference}', 'Écart avec Erario c/IVA : {difference}'],

            'italianVat.error.register_entry_locked' => ['Il protocollo {protocol} è bloccato: registrare uno storno', 'Protocol {protocol} is locked: register a reversal', 'Das Protokoll {protocol} ist gesperrt: Stornobuchung erfassen', 'Le protocole {protocol} est verrouillé : enregistrez une contrepassation'],
            'italianVat.error.period_locked' => ['Periodo chiuso da una stampa definitiva fino al {until}', 'Period closed by a definitive print until {until}', 'Zeitraum durch einen endgültigen Druck bis {until} geschlossen', 'Période clôturée par une impression définitive jusqu’au {until}'],
            'italianVat.error.print_protocol_gap' => ['Numeri di protocollo non consecutivi: atteso {expected}, trovato {found}', 'Protocol numbers not contiguous: {expected} expected, {found} found', 'Protokollnummern nicht lückenlos: erwartet {expected}, gefunden {found}', 'Numéros de protocole non consécutifs : {expected} attendu, {found} trouvé'],
            'italianVat.error.print_nothing_to_print' => ['Nessuna riga da stampare', 'No lines to print', 'Keine Zeilen zu drucken', 'Aucune ligne à imprimer'],
            'italianVat.error.vat_nature_required' => ['Indicare la natura IVA (da N1 a N7)', 'Enter the VAT nature (N1 to N7)', 'MwSt-Art angeben (N1 bis N7)', 'Indiquer la nature de TVA (N1 à N7)'],
            'italianVat.error.goods_insufficient_balance' => ['Giacenza insufficiente: {held} disponibili, richiesti {requested}', 'Insufficient balance: {held} held, {requested} requested', 'Ungenügender Bestand: {held} vorrätig, {requested} angefordert', 'Stock insuffisant : {held} détenus, {requested} demandés'],
            'italianVat.error.plafond_no_declaration' => ['Il cliente non ha una lettera d’intento', 'The customer has no declaration of intent', 'Der Kunde hat keine Absichtserklärung', 'Le client n’a pas de déclaration d’intention'],
            'italianVat.error.declaration_revoked' => ['Lettera d’intento {protocol} revocata', 'Declaration of intent {protocol} revoked', 'Absichtserklärung {protocol} widerrufen', 'Déclaration d’intention {protocol} révoquée'],
            'italianVat.error.declaration_expired' => ['Lettera d’intento scaduta prima del {date}', 'Declaration of intent expired before {date}', 'Absichtserklärung vor dem {date} abgelaufen', 'Déclaration d’intention expirée avant le {date}'],
            'italianVat.error.declaration_not_yet_valid' => ['Lettera d’intento non ancora valida il {date}', 'Declaration of intent not valid yet on {date}', 'Absichtserklärung am {date} noch nicht gültig', 'Déclaration d’intention pas encore valable le {date}'],
            'italianVat.error.plafond_exceeded' => ['Importo {requested} oltre il plafond della lettera {protocol} (residuo {residual})', 'Amount {requested} exceeds the plafond of declaration {protocol} ({residual} left)', 'Betrag {requested} übersteigt das Plafond der Erklärung {protocol} (Rest {residual})', 'Montant {requested} au-delà du plafond de la déclaration {protocol} (reste {residual})'],
            'italianVat.error.lipe_incomplete_periods' => ['Liquidazioni incomplete del trimestre {quarter} {year} (mesi mancanti: {missing})', 'Incomplete settlements of quarter {quarter} {year} (missing months: {missing})', 'Unvollständige Abrechnungen des Quartals {quarter} {year} (fehlende Monate: {missing})', 'Liquidations incomplètes du trimestre {quarter} {year} (mois manquants : {missing})'],
            'italianVat.error.lipe_signature_invalid' => ['La firma non corrisponde al file o al certificato', 'The signature does not match the file or the certificate', 'Die Signatur passt nicht zur Datei oder zum Zertifikat', 'La signature ne correspond pas au fichier ou au certificat'],
            'italianVat.error.lipe_not_ready' => ['Comunicazione non pronta: {problems}', 'Communication not ready: {problems}', 'Mitteilung nicht bereit: {problems}', 'Communication non prête : {problems}'],
            'italianVat.error.lipe_already_transmitted' => ['La LIPE è già stata trasmessa', 'The LIPE was already transmitted', 'Die LIPE wurde bereits übermittelt', 'La LIPE a déjà été transmise'],
            'italianVat.error.annual_not_reconciled' => ['L’IVA annuale non quadra con la contabilità (differenza {difference})', 'The annual VAT does not reconcile with the ledger (difference {difference})', 'Die Jahres-MwSt stimmt nicht mit der Buchhaltung überein (Differenz {difference})', 'La TVA annuelle ne concorde pas avec la comptabilité (écart {difference})'],
            'italianVat.error.cu_validation_failed' => ['{count} certificazioni non superano i controlli', '{count} certifications do not validate', '{count} Bescheinigungen bestehen die Prüfung nicht', '{count} attestations ne passent pas les contrôles'],
            'italianVat.error.cu_export_invalid' => ['File CU non producibile: {count} errori, il primo è {first}', 'CU file cannot be produced: {count} errors, the first is {first}', 'CU-Datei nicht erzeugbar: {count} Fehler, der erste ist {first}', 'Fichier CU non productible : {count} erreurs, la première est {first}'],
            'italianVat.error.cu_tax_code_missing' => ['Codice fiscale del percipiente mancante', 'Recipient tax code missing', 'Steuernummer des Empfängers fehlt', 'Code fiscal du bénéficiaire manquant'],
            'italianVat.error.cu_tax_code_invalid' => ['Codice fiscale {tax_code} non valido', 'Tax code {tax_code} is not valid', 'Steuernummer {tax_code} ist ungültig', 'Code fiscal {tax_code} invalide'],
            'italianVat.error.cu_reason_invalid' => ['Causale {reason} non valida', 'Reason code {reason} is not valid', 'Grundcode {reason} ist ungültig', 'Code motif {reason} invalide'],
            'italianVat.error.cu_gross_not_positive' => ['L’ammontare lordo deve essere positivo', 'The gross amount must be positive', 'Der Bruttobetrag muss positiv sein', 'Le montant brut doit être positif'],
            'italianVat.error.cu_withheld_exceeds_taxable' => ['La ritenuta supera l’imponibile', 'The withheld tax exceeds the taxable amount', 'Die einbehaltene Steuer übersteigt die Bemessungsgrundlage', 'La retenue dépasse la base imposable'],
            'italianVat.error.cu_taxable_exceeds_gross' => ['L’imponibile supera l’ammontare lordo', 'The taxable amount exceeds the gross amount', 'Die Bemessungsgrundlage übersteigt den Bruttobetrag', 'La base imposable dépasse le montant brut'],
            'italianVat.error.cu_name_missing' => ['Nome del percipiente mancante', 'Recipient name missing', 'Name des Empfängers fehlt', 'Nom du bénéficiaire manquant'],
            'italianVat.error.cu_company_tax_code_invalid' => ['Codice fiscale della società mancante o non valido', 'Company tax code missing or invalid', 'Steuernummer des Unternehmens fehlt oder ist ungültig', 'Code fiscal de la société manquant ou invalide'],
            'italianVat.error.cu_company_address_invalid' => ['CAP o provincia della società non validi', 'Company postal code or province not valid', 'Postleitzahl oder Provinz des Unternehmens ungültig', 'Code postal ou province de la société invalide'],
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
