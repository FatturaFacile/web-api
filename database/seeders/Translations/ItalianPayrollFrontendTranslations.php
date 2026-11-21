<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the Italian payroll closing hub (Mod 077): period picker, the five stages of the monthly closing,
 * attendance validation, consultant export, payslip PDF splitter, accounting reconciler, F24 summary and SEPA salaries.
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text
 * fr-CH. Placeholders are written {name}.
 */
final class ItalianPayrollFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'itPayroll.period.select' => ['Periodo paghe', 'Payroll period', 'Lohnperiode', 'Période de paie'],
            'itPayroll.period.none' => ['Nessun periodo paghe: aprine uno qui sopra', 'No payroll period yet: open one above', 'Noch keine Lohnperiode: oben eine eröffnen', 'Aucune période de paie : en ouvrir une ci-dessus'],
            'itPayroll.period.year' => ['Anno', 'Year', 'Jahr', 'Année'],
            'itPayroll.period.month' => ['Mese', 'Month', 'Monat', 'Mois'],
            'itPayroll.period.tredicesima' => ['Tredicesima', '13th month pay', '13. Monatslohn', '13e mois'],
            'itPayroll.period.quattordicesima' => ['Quattordicesima', '14th month pay', '14. Monatslohn', '14e mois'],
            'itPayroll.period.create' => ['Apri periodo', 'Open period', 'Periode eröffnen', 'Ouvrir la période'],

            'itPayroll.step.attendance' => ['Raccolta presenze', 'Attendance collection', 'Erfassung der Anwesenheit', 'Collecte des présences'],
            'itPayroll.step.validation' => ['Validazione', 'Validation', 'Validierung', 'Validation'],
            'itPayroll.step.export' => ['Export al consulente', 'CdL export', 'Export an den Berater', 'Export vers le consultant'],
            'itPayroll.step.import' => ['Cedolini e prospetto contabile', 'Payslips and accounting import', 'Lohnabrechnungen und Buchungsimport', 'Bulletins et import comptable'],
            'itPayroll.step.sepa' => ['Pagamento SEPA', 'SEPA disbursement', 'SEPA-Auszahlung', 'Paiement SEPA'],

            'itPayroll.status.attendance_open' => ['Presenze aperte', 'Attendance open', 'Anwesenheit offen', 'Présences ouvertes'],
            'itPayroll.status.validated' => ['Validato', 'Validated', 'Validiert', 'Validé'],
            'itPayroll.status.exported_to_cdl' => ['Esportato al consulente', 'Exported to consultant', 'An Berater exportiert', 'Exporté au consultant'],
            'itPayroll.status.cedolini_imported' => ['Cedolini importati', 'Payslips imported', 'Lohnabrechnungen importiert', 'Bulletins importés'],
            'itPayroll.status.paid' => ['Pagato', 'Paid', 'Bezahlt', 'Payé'],

            'itPayroll.attendance.title' => ['Raccolta presenze', 'Attendance collection', 'Erfassung der Anwesenheit', 'Collecte des présences'],
            'itPayroll.attendance.help' => [
                'Le presenze del mese vengono raccolte da timbrature, assenze approvate, buoni pasto, rimborsi spese e fringe benefit. Finché il mese è aperto si possono ancora correggere nei moduli di origine.',
                'The month’s attendance is gathered from clock entries, approved leave, meal bookings, expense reimbursements and fringe benefits. While the month is open you can still correct it in the source modules.',
                'Die Anwesenheit des Monats wird aus Stempelungen, genehmigten Abwesenheiten, Essensbuchungen, Spesenrückerstattungen und Nebenleistungen gesammelt. Solange der Monat offen ist, kann er in den Quellmodulen noch korrigiert werden.',
                'Les présences du mois sont collectées à partir des pointages, des absences approuvées, des repas, des remboursements de frais et des avantages en nature. Tant que le mois est ouvert, il peut encore être corrigé dans les modules sources.',
            ],

            'itPayroll.validation.title' => ['Validazione', 'Validation', 'Validierung', 'Validation'],
            'itPayroll.validation.help' => [
                'Controlla coerenza e completezza (assenze sovrapposte, malattie senza protocollo INPS, codici fiscali mancanti). Senza errori il mese viene validato e le presenze non sono più modificabili.',
                'Checks consistency and completeness (overlapping leave, sickness without an INPS protocol, missing tax codes). With no error the month is validated and its attendance can no longer be changed.',
                'Prüft Konsistenz und Vollständigkeit (überlappende Abwesenheiten, Krankheit ohne INPS-Protokoll, fehlende Steuernummern). Ohne Fehler wird der Monat validiert und die Anwesenheit ist nicht mehr änderbar.',
                'Vérifie la cohérence et l’exhaustivité (absences qui se chevauchent, maladies sans protocole INPS, codes fiscaux manquants). Sans erreur, le mois est validé et les présences ne sont plus modifiables.',
            ],
            'itPayroll.validation.fringe' => ['Fringe benefit del mese (facoltativo)', 'Fringe benefits of the month (optional)', 'Nebenleistungen des Monats (optional)', 'Avantages en nature du mois (facultatif)'],
            'itPayroll.validation.fringeHint' => ['Una riga per dipendente: ID dipendente;importo', 'One line per employee: employee ID;amount', 'Eine Zeile pro Mitarbeiter: Mitarbeiter-ID;Betrag', 'Une ligne par employé : ID employé;montant'],
            'itPayroll.validation.fringeInvalid' => ['Riga {line} dei fringe benefit non valida', 'Fringe benefit line {line} is not valid', 'Zeile {line} der Nebenleistungen ist ungültig', 'La ligne {line} des avantages en nature n’est pas valide'],
            'itPayroll.validation.run' => ['Valida presenze', 'Validate attendance', 'Anwesenheit validieren', 'Valider les présences'],
            'itPayroll.validation.lines' => ['{count} righe di presenza generate', '{count} attendance lines generated', '{count} Anwesenheitszeilen erzeugt', '{count} lignes de présence générées'],
            'itPayroll.validation.ok' => ['Nessun errore né avviso', 'No errors or warnings', 'Keine Fehler oder Warnungen', 'Aucune erreur ni avertissement'],
            'itPayroll.validation.errors' => ['{count} errori da correggere prima della validazione', '{count} errors to fix before validation', '{count} Fehler vor der Validierung zu beheben', '{count} erreurs à corriger avant la validation'],
            'itPayroll.validation.warnings' => ['{count} avvisi da verificare', '{count} warnings to review', '{count} Warnungen zu prüfen', '{count} avertissements à vérifier'],

            'itPayroll.export.title' => ['Export al consulente del lavoro', 'Export to the labor consultant', 'Export an den Arbeitsberater', 'Export vers le consultant du travail'],
            'itPayroll.export.help' => [
                'Genera il file presenze nel tracciato del software del consulente (Zucchetti, TeamSystem, Inaz, GIS o CSV generico).',
                'Generates the attendance file in the layout of the consultant’s software (Zucchetti, TeamSystem, Inaz, GIS or generic CSV).',
                'Erzeugt die Anwesenheitsdatei im Layout der Beratersoftware (Zucchetti, TeamSystem, Inaz, GIS oder generisches CSV).',
                'Génère le fichier de présences au format du logiciel du consultant (Zucchetti, TeamSystem, Inaz, GIS ou CSV générique).',
            ],
            'itPayroll.export.format' => ['Software del consulente', 'Consultant software', 'Beratersoftware', 'Logiciel du consultant'],
            'itPayroll.export.company' => ['Codice azienda', 'Company code', 'Firmencode', 'Code société'],
            'itPayroll.export.companyHint' => [
                'Fino a 6 caratteri alfanumerici, come assegnato dal consulente.',
                'Up to 6 letters or digits, as assigned by the consultant.',
                'Bis zu 6 Buchstaben oder Ziffern, wie vom Berater vergeben.',
                'Jusqu’à 6 lettres ou chiffres, attribués par le consultant.',
            ],
            'itPayroll.export.download' => ['Scarica file presenze', 'Download attendance file', 'Anwesenheitsdatei herunterladen', 'Télécharger le fichier de présences'],
            'itPayroll.export.done' => ['File presenze scaricato', 'Attendance file downloaded', 'Anwesenheitsdatei heruntergeladen', 'Fichier de présences téléchargé'],

            'itPayroll.format.zucchetti' => ['Zucchetti Paghe Web', 'Zucchetti Paghe Web', 'Zucchetti Paghe Web', 'Zucchetti Paghe Web'],
            'itPayroll.format.teamsystem' => ['TeamSystem Polyedro / Alyante', 'TeamSystem Polyedro / Alyante', 'TeamSystem Polyedro / Alyante', 'TeamSystem Polyedro / Alyante'],
            'itPayroll.format.inaz' => ['Inaz Paghe', 'Inaz Paghe', 'Inaz Paghe', 'Inaz Paghe'],
            'itPayroll.format.gis' => ['Wolters Kluwer / GIS Ranocchi', 'Wolters Kluwer / GIS Ranocchi', 'Wolters Kluwer / GIS Ranocchi', 'Wolters Kluwer / GIS Ranocchi'],
            'itPayroll.format.generic_csv' => ['CSV generico', 'Generic CSV', 'Generisches CSV', 'CSV générique'],

            'itPayroll.import.title' => ['Cedolini e prospetto contabile', 'Payslips and accounting summary', 'Lohnabrechnungen und Buchungsübersicht', 'Bulletins de paie et récapitulatif comptable'],
            'itPayroll.import.help' => [
                'Carica il PDF cumulativo dei cedolini del consulente e registra il prospetto contabile: la scrittura in contabilità si genera solo se le quadrature tornano.',
                'Upload the consultant’s bulk payslip PDF and record the accounting summary: the ledger entry is posted only if the equations balance.',
                'Laden Sie das Sammel-PDF der Lohnabrechnungen des Beraters hoch und erfassen Sie die Buchungsübersicht: Die Buchung erfolgt nur, wenn die Abstimmungen aufgehen.',
                'Chargez le PDF groupé des bulletins du consultant et enregistrez le récapitulatif comptable : l’écriture n’est passée que si les équilibres sont respectés.',
            ],

            'itPayroll.splitter.hint' => [
                'Trascina qui il PDF cumulativo dei cedolini o clicca per sceglierlo (max 50 MB)',
                'Drag the bulk payslip PDF here or click to choose it (max 50 MB)',
                'PDF mit allen Lohnabrechnungen hierher ziehen oder klicken (max. 50 MB)',
                'Glissez ici le PDF groupé des bulletins ou cliquez pour le choisir (50 Mo max)',
            ],
            'itPayroll.splitter.phase.upload' => ['Caricamento', 'Uploading', 'Hochladen', 'Téléversement'],
            'itPayroll.splitter.phase.split' => ['Separazione pagine e lettura codici fiscali', 'Splitting pages and reading tax codes', 'Seiten trennen und Steuernummern lesen', 'Séparation des pages et lecture des codes fiscaux'],
            'itPayroll.splitter.phase.match' => ['Abbinamento ai dipendenti', 'Matching employees', 'Zuordnung zu Mitarbeitern', 'Rapprochement avec les employés'],
            'itPayroll.splitter.invalid' => ['Il file deve essere un PDF fino a 50 MB', 'The file must be a PDF of up to 50 MB', 'Die Datei muss ein PDF bis 50 MB sein', 'Le fichier doit être un PDF de 50 Mo maximum'],
            'itPayroll.splitter.failure' => ['Importazione dei cedolini non riuscita', 'The payslip import failed', 'Import der Lohnabrechnungen fehlgeschlagen', 'L’import des bulletins a échoué'],
            'itPayroll.splitter.done' => [
                '{matched} cedolini abbinati, {unmatched} gruppi di pagine senza dipendente',
                '{matched} payslips matched, {unmatched} page groups without an employee',
                '{matched} Lohnabrechnungen zugeordnet, {unmatched} Seitengruppen ohne Mitarbeiter',
                '{matched} bulletins rapprochés, {unmatched} groupes de pages sans employé',
            ],
            'itPayroll.splitter.employee' => ['Dipendente', 'Employee', 'Mitarbeiter', 'Employé'],
            'itPayroll.splitter.codeEnd' => ['Codice fiscale (ultimi 4)', 'Tax code (last 4)', 'Steuernummer (letzte 4)', 'Code fiscal (4 derniers)'],
            'itPayroll.splitter.pages' => ['Pagine', 'Pages', 'Seiten', 'Pages'],
            'itPayroll.splitter.unmatched' => ['Pagine non abbinate', 'Unmatched pages', 'Nicht zugeordnete Seiten', 'Pages non rapprochées'],
            'itPayroll.splitter.noCode' => ['nessun codice fiscale riconosciuto', 'no tax code recognized', 'keine Steuernummer erkannt', 'aucun code fiscal reconnu'],

            'itPayroll.reconciler.title' => ['Anteprima di quadratura', 'Reconciliation preview', 'Abstimmungsvorschau', 'Aperçu de rapprochement'],
            'itPayroll.reconciler.amounts' => ['Importi del prospetto contabile', 'Accounting summary amounts', 'Beträge der Buchungsübersicht', 'Montants du récapitulatif comptable'],
            'itPayroll.reconciler.nets' => ['Netti dei dipendenti', 'Employee net amounts', 'Nettobeträge der Mitarbeiter', 'Nets des employés'],
            'itPayroll.reconciler.netsHint' => [
                'Una riga per cedolino: ID dipendente;netto (separatore ; oppure tab)',
                'One line per payslip: employee ID;net (separator ; or tab)',
                'Eine Zeile pro Abrechnung: Mitarbeiter-ID;Netto (Trennzeichen ; oder Tab)',
                'Une ligne par bulletin : ID employé;net (séparateur ; ou tabulation)',
            ],
            'itPayroll.reconciler.netsRows' => ['{count} netti letti', '{count} nets read', '{count} Nettobeträge gelesen', '{count} nets lus'],
            'itPayroll.reconciler.netsError.format' => ['Riga {line}: usa ID dipendente;netto', 'Line {line}: use employee ID;net', 'Zeile {line}: Mitarbeiter-ID;Netto verwenden', 'Ligne {line} : utilisez ID employé;net'],
            'itPayroll.reconciler.netsError.employee' => ['Riga {line}: ID dipendente non valido', 'Line {line}: employee ID is not valid', 'Zeile {line}: Mitarbeiter-ID ungültig', 'Ligne {line} : ID employé invalide'],
            'itPayroll.reconciler.netsError.amount' => ['Riga {line}: importo non valido o non positivo', 'Line {line}: amount is invalid or not positive', 'Zeile {line}: Betrag ungültig oder nicht positiv', 'Ligne {line} : montant invalide ou non positif'],
            'itPayroll.reconciler.netsError.duplicate' => ['Riga {line}: dipendente già presente', 'Line {line}: employee already listed', 'Zeile {line}: Mitarbeiter bereits aufgeführt', 'Ligne {line} : employé déjà présent'],
            'itPayroll.reconciler.checks' => ['Quadrature', 'Equations', 'Abstimmungen', 'Équilibres'],
            'itPayroll.reconciler.check' => ['Controllo', 'Check', 'Prüfung', 'Contrôle'],
            'itPayroll.reconciler.stated' => ['Dichiarato', 'Stated', 'Angegeben', 'Déclaré'],
            'itPayroll.reconciler.calculated' => ['Calcolato', 'Calculated', 'Berechnet', 'Calculé'],
            'itPayroll.reconciler.difference' => ['Differenza', 'Difference', 'Differenz', 'Écart'],
            'itPayroll.reconciler.result' => ['Esito', 'Result', 'Ergebnis', 'Résultat'],
            'itPayroll.reconciler.ok' => ['Quadra', 'Balanced', 'Stimmt', 'Équilibré'],
            'itPayroll.reconciler.warning' => ['Da verificare', 'Review', 'Prüfen', 'À vérifier'],
            'itPayroll.reconciler.mismatch' => ['Non quadra', 'Mismatch', 'Abweichung', 'Écart'],
            'itPayroll.reconciler.missing' => [
                'Compila tutti gli importi obbligatori (*) per abilitare l’importazione.',
                'Fill in every required amount (*) to enable the import.',
                'Füllen Sie alle Pflichtbeträge (*) aus, um den Import zu aktivieren.',
                'Renseignez tous les montants obligatoires (*) pour activer l’import.',
            ],
            'itPayroll.reconciler.import' => ['Importa e contabilizza', 'Import and post', 'Importieren und buchen', 'Importer et comptabiliser'],
            'itPayroll.reconciler.imported' => ['Prospetto contabile registrato e contabilizzato', 'Accounting summary recorded and posted', 'Buchungsübersicht erfasst und gebucht', 'Récapitulatif comptable enregistré et comptabilisé'],

            'itPayroll.field.total_gross_wages' => ['Retribuzioni lorde', 'Gross wages', 'Bruttolöhne', 'Salaires bruts'],
            'itPayroll.field.inps_employee_withholding' => ['Trattenuta INPS dipendente', 'Employee INPS withholding', 'INPS-Abzug Arbeitnehmer', 'Retenue INPS salarié'],
            'itPayroll.field.inps_employer_contribution' => ['Contributi INPS ditta', 'Employer INPS contributions', 'INPS-Beiträge Arbeitgeber', 'Cotisations INPS employeur'],
            'itPayroll.field.irpef_withholding' => ['Ritenute IRPEF', 'IRPEF withholding', 'IRPEF-Quellensteuer', 'Retenues IRPEF'],
            'itPayroll.field.regional_municipal_addizionali' => ['Addizionali regionali e comunali', 'Regional and municipal surtaxes', 'Regionale und kommunale Zuschläge', 'Surtaxes régionales et communales'],
            'itPayroll.field.tfr_monthly_accrual' => ['Accantonamento TFR del mese', 'Monthly TFR accrual', 'Monatliche TFR-Rückstellung', 'Provision TFR du mois'],
            'itPayroll.field.tfr_fondo_tesoreria_or_previdenza' => ['Fondo TFR / tesoreria INPS / previdenza', 'TFR fund / INPS treasury / pension fund', 'TFR-Fonds / INPS-Schatzamt / Vorsorge', 'Fonds TFR / trésorerie INPS / prévoyance'],
            'itPayroll.field.inail_accrual' => ['Accantonamento INAIL', 'INAIL accrual', 'INAIL-Rückstellung', 'Provision INAIL'],
            'itPayroll.field.net_payable' => ['Netto da pagare', 'Net payable', 'Nettoauszahlung', 'Net à payer'],

            'itPayroll.check.net' => ['Lordo − trattenute = netto', 'Gross − deductions = net', 'Brutto − Abzüge = Netto', 'Brut − retenues = net'],
            'itPayroll.check.tfr' => ['Accantonamento TFR = fondo TFR', 'TFR accrual = TFR fund', 'TFR-Rückstellung = TFR-Fonds', 'Provision TFR = fonds TFR'],
            'itPayroll.check.employee_nets' => ['Somma dei netti = netto da pagare', 'Sum of nets = net payable', 'Summe der Nettobeträge = Nettoauszahlung', 'Somme des nets = net à payer'],
            'itPayroll.check.journal' => ['Scrittura: dare = avere', 'Journal entry: debit = credit', 'Buchung: Soll = Haben', 'Écriture : débit = crédit'],
            'itPayroll.check.tfr_formula' => ['TFR rispetto a lordo ÷ 13,5 − 0,50%', 'TFR against gross ÷ 13.5 − 0.50%', 'TFR im Vergleich zu Brutto ÷ 13,5 − 0,50 %', 'TFR par rapport au brut ÷ 13,5 − 0,50 %'],

            'itPayroll.f24.title' => ['Riepilogo F24', 'F24 summary', 'F24-Übersicht', 'Récapitulatif F24'],
            'itPayroll.f24.due' => ['Scadenza versamento: {date}', 'Payment due: {date}', 'Zahlungsfrist: {date}', 'Échéance de paiement : {date}'],
            'itPayroll.f24.section' => ['Sezione', 'Section', 'Abschnitt', 'Section'],
            'itPayroll.f24.code' => ['Codice tributo', 'Tax code', 'Steuercode', 'Code tribut'],
            'itPayroll.f24.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'itPayroll.f24.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'itPayroll.f24.total' => ['Totale F24', 'F24 total', 'F24-Total', 'Total F24'],
            'itPayroll.f24.sections.erario' => ['Erario', 'Treasury', 'Fiskus', 'Trésor'],
            'itPayroll.f24.sections.regioni' => ['Regioni', 'Regions', 'Regionen', 'Régions'],
            'itPayroll.f24.sections.enti_locali' => ['Enti locali', 'Local authorities', 'Lokale Behörden', 'Collectivités locales'],

            'itPayroll.sepa.title' => ['Pagamento stipendi SEPA', 'SEPA salary payment', 'SEPA-Gehaltszahlung', 'Paiement des salaires SEPA'],
            'itPayroll.sepa.help' => [
                'Genera il file pain.001.001.03 con causale SALA per gli importi netti contabilizzati. Poi segna il mese come pagato.',
                'Generates the pain.001.001.03 file with the SALA purpose for the posted net amounts. Then mark the month as paid.',
                'Erzeugt die Datei pain.001.001.03 mit Zweck SALA für die gebuchten Nettobeträge. Danach den Monat als bezahlt markieren.',
                'Génère le fichier pain.001.001.03 avec le motif SALA pour les nets comptabilisés. Marquez ensuite le mois comme payé.',
            ],
            'itPayroll.sepa.debtorName' => ['Intestatario del conto di addebito', 'Debtor account holder', 'Kontoinhaber des Belastungskontos', 'Titulaire du compte débiteur'],
            'itPayroll.sepa.iban' => ['IBAN di addebito', 'Debtor IBAN', 'IBAN des Belastungskontos', 'IBAN du compte débiteur'],
            'itPayroll.sepa.bic' => ['BIC (facoltativo)', 'BIC (optional)', 'BIC (optional)', 'BIC (facultatif)'],
            'itPayroll.sepa.date' => ['Data di esecuzione', 'Execution date', 'Ausführungsdatum', 'Date d’exécution'],
            'itPayroll.sepa.generate' => ['Genera file SEPA', 'Generate SEPA file', 'SEPA-Datei erzeugen', 'Générer le fichier SEPA'],
            'itPayroll.sepa.done' => ['File SEPA scaricato', 'SEPA file downloaded', 'SEPA-Datei heruntergeladen', 'Fichier SEPA téléchargé'],
            'itPayroll.sepa.markPaid' => ['Segna come pagato', 'Mark as paid', 'Als bezahlt markieren', 'Marquer comme payé'],
            'itPayroll.sepa.markPaidConfirm' => [
                'Confermi che gli stipendi sono stati pagati? Il mese verrà chiuso.',
                'Confirm that the salaries were paid? The month will be closed.',
                'Bestätigen Sie, dass die Gehälter bezahlt wurden? Der Monat wird abgeschlossen.',
                'Confirmez-vous que les salaires ont été payés ? Le mois sera clôturé.',
            ],
            'itPayroll.sepa.paid' => ['Mese pagato e chiuso', 'Month paid and closed', 'Monat bezahlt und abgeschlossen', 'Mois payé et clôturé'],
            'itPayroll.mine.audit' => [
                'Ogni apertura di un documento viene registrata in un registro di accesso non modificabile (L. 4/1953).',
                'Every time you open a document it is recorded in a tamper-proof access log (Law 4/1953).',
                'Jedes Öffnen eines Dokuments wird in einem unveränderlichen Zugriffsprotokoll festgehalten (Gesetz 4/1953).',
                'Chaque ouverture d\'un document est enregistrée dans un journal d\'accès inaltérable (loi 4/1953).',
            ],
            'itPayroll.mine.loadFailed' => ['Impossibile caricare i tuoi cedolini.', 'Your payslips could not be loaded.', 'Ihre Lohnabrechnungen konnten nicht geladen werden.', 'Impossible de charger vos bulletins de paie.'],
            'itPayroll.mine.retry' => ['Riprova', 'Retry', 'Erneut versuchen', 'Réessayer'],
            'itPayroll.mine.empty' => ['Nessun cedolino disponibile', 'No payslips available', 'Keine Lohnabrechnungen verfügbar', 'Aucun bulletin de paie disponible'],
            'itPayroll.mine.net' => ['Netto', 'Net', 'Netto', 'Net'],
            'itPayroll.mine.viewedOn' => ['Visto il {date}', 'Viewed on {date}', 'Angesehen am {date}', 'Consulté le {date}'],
            'itPayroll.mine.new' => ['Nuovo', 'New', 'Neu', 'Nouveau'],
            'itPayroll.mine.cedolino' => ['Cedolino', 'Payslip', 'Lohnabrechnung', 'Bulletin de paie'],
            'itPayroll.mine.cu' => ['CU', 'CU tax certificate', 'CU-Steuerbescheinigung', 'Certificat fiscal CU'],
            'itPayroll.mine.preview' => ['Anteprima documento', 'Document preview', 'Dokumentvorschau', 'Aperçu du document'],
            'itPayroll.mine.download' => ['Scarica PDF', 'Download PDF', 'PDF herunterladen', 'Télécharger le PDF'],
            'itPayroll.mine.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
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
