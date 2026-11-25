<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the statutory financial statements module (Mod 167): the Italian OIC / XBRL dossier (civil-code annual
 * accounts with the Nota Integrativa) and the Swiss CO Art. 959 / Swiss GAAP FER annual accounts (with the Anhang),
 * the creation wizard, the statements inspector, the notes workspace, the ratios and DSCR simulator, the TEBENI preview,
 * the deposit package and the codes the API reports (statutoryFinancials.error.<code>).
 * The statement lines, the narrative templates, the legal wording, the ratio descriptions and the validation messages are
 * NOT here: the API seeds and localises them (StatutoryFinancials reference seeders).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal and technical acronyms (SDI, PEC, XBRL, TEBENI, OIC, FER, DSCR, PFN, EBITDA, ROE, ROI, ROS, PDF/A) stay untranslated.
 */
final class StatutoryFinancialsFrontendTranslations
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
            self::statements(),
            self::notes(),
            self::indicators(),
            self::package(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'statutoryFinancials.title' => ['Bilancio d’esercizio e fascicolo di deposito', 'Annual accounts and filing dossier', 'Jahresrechnung und Hinterlegungsdossier', 'Comptes annuels et dossier de dépôt'],
            'statutoryFinancials.nav.title' => ['Bilancio civilistico', 'Statutory accounts', 'Jahresrechnung', 'Comptes annuels'],
            'statutoryFinancials.nav.dossiers' => ['Fascicoli di bilancio', 'Accounts dossiers', 'Abschlussdossiers', 'Dossiers de comptes'],
            'statutoryFinancials.nav.new' => ['Nuovo fascicolo', 'New dossier', 'Neues Dossier', 'Nouveau dossier'],
            'statutoryFinancials.nav.dossier' => ['Fascicolo', 'Dossier', 'Dossier', 'Dossier'],
            'statutoryFinancials.workspace.title' => ['Fascicolo di bilancio', 'Accounts dossier', 'Abschlussdossier', 'Dossier de comptes'],
            'statutoryFinancials.workspace.readOnly' => ['Il fascicolo è bloccato o depositato: è in sola lettura. Riaprilo per modificarlo.', 'The dossier is locked or filed and is read-only. Reopen it to change it.', 'Das Dossier ist gesperrt oder hinterlegt und schreibgeschützt. Öffnen Sie es erneut, um es zu ändern.', 'Le dossier est verrouillé ou déposé et en lecture seule. Rouvrez-le pour le modifier.'],
            'statutoryFinancials.wizard.createTitle' => ['Nuovo fascicolo di bilancio', 'New accounts dossier', 'Neues Abschlussdossier', 'Nouveau dossier de comptes'],
            'statutoryFinancials.wizard.readOnly' => ['Il fascicolo non è modificabile (bloccato, depositato o permessi di sola lettura).', 'The dossier cannot be edited (locked, filed or read-only access).', 'Das Dossier kann nicht bearbeitet werden (gesperrt, hinterlegt oder nur Leserechte).', 'Le dossier ne peut pas être modifié (verrouillé, déposé ou accès en lecture seule).'],
            'statutoryFinancials.wizard.step.years' => ['Esercizi', 'Fiscal years', 'Geschäftsjahre', 'Exercices'],
            'statutoryFinancials.wizard.step.standard' => ['Schema e metodi', 'Standard and methods', 'Standard und Methoden', 'Référentiel et méthodes'],
            'statutoryFinancials.wizard.step.eligibility' => ['Verifica soglie', 'Threshold check', 'Schwellenwertprüfung', 'Contrôle des seuils'],
            'statutoryFinancials.wizard.step.options' => ['Opzioni', 'Options', 'Optionen', 'Options'],
            'statutoryFinancials.tab.statements' => ['Prospetti', 'Statements', 'Abschlussrechnungen', 'États financiers'],
            'statutoryFinancials.tab.notes' => ['Nota integrativa / Anhang', 'Notes / Anhang', 'Anhang / Nota integrativa', 'Annexe / Nota integrativa'],
            'statutoryFinancials.tab.indicators' => ['Indici e crisi', 'Ratios and crisis', 'Kennzahlen und Krise', 'Ratios et crise'],
            'statutoryFinancials.tab.preview' => ['Anteprima TEBENI', 'TEBENI preview', 'TEBENI-Vorschau', 'Aperçu TEBENI'],
            'statutoryFinancials.tab.package' => ['Pacchetto di deposito', 'Filing package', 'Hinterlegungspaket', 'Dossier de dépôt'],
            'statutoryFinancials.tab.settings' => ['Impostazioni', 'Settings', 'Einstellungen', 'Paramètres'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'statutoryFinancials.status.draft' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'statutoryFinancials.status.reclassified' => ['Riclassificato', 'Reclassified', 'Umgegliedert', 'Reclassé'],
            'statutoryFinancials.status.notes_populated' => ['Nota compilata', 'Notes populated', 'Anhang befüllt', 'Annexe renseignée'],
            'statutoryFinancials.status.validated' => ['Validato', 'Validated', 'Validiert', 'Validé'],
            'statutoryFinancials.status.locked' => ['Bloccato', 'Locked', 'Gesperrt', 'Verrouillé'],
            'statutoryFinancials.status.filed' => ['Depositato', 'Filed', 'Hinterlegt', 'Déposé'],
            'statutoryFinancials.standard.IT_OIC_Ordinario' => ['Italia OIC ordinario', 'Italy OIC ordinary', 'Italien OIC ordentlich', 'Italie OIC ordinaire'],
            'statutoryFinancials.standard.IT_OIC_Abbreviato' => ['Italia OIC abbreviato', 'Italy OIC abridged', 'Italien OIC verkürzt', 'Italie OIC abrégé'],
            'statutoryFinancials.standard.IT_OIC_Micro' => ['Italia OIC micro-imprese', 'Italy OIC micro-entities', 'Italien OIC Kleinstunternehmen', 'Italie OIC micro-entreprises'],
            'statutoryFinancials.standard.CH_CO_Art959' => ['Svizzera CO art. 959', 'Switzerland CO Art. 959', 'Schweiz OR Art. 959', 'Suisse CO art. 959'],
            'statutoryFinancials.standard.CH_SwissGaapFer' => ['Svizzera Swiss GAAP FER', 'Switzerland Swiss GAAP FER', 'Schweiz Swiss GAAP FER', 'Suisse Swiss GAAP FER'],
            'statutoryFinancials.roundingMode.ExactCents' => ['Centesimi esatti', 'Exact cents', 'Exakte Rappen/Cent', 'Centimes exacts'],
            'statutoryFinancials.roundingMode.IntegerUnits_Euro_Chf' => ['Unità intere (euro / franchi)', 'Whole units (euro / francs)', 'Ganze Einheiten (Euro / Franken)', 'Unités entières (euros / francs)'],
            'statutoryFinancials.incomeMethod.total_cost' => ['A costi per natura (Gesamtkostenverfahren)', 'By nature of expense (Gesamtkostenverfahren)', 'Gesamtkostenverfahren (nach Aufwandsarten)', 'Par nature de charges (Gesamtkostenverfahren)'],
            'statutoryFinancials.incomeMethod.cost_of_sales' => ['A costo del venduto (Umsatzkostenverfahren)', 'By function, cost of sales (Umsatzkostenverfahren)', 'Umsatzkostenverfahren (nach Funktionen)', 'Par fonction, coût des ventes (Umsatzkostenverfahren)'],
            'statutoryFinancials.cashFlowMethod.indirect' => ['Metodo indiretto', 'Indirect method', 'Indirekte Methode', 'Méthode indirecte'],
            'statutoryFinancials.cashFlowMethod.direct' => ['Metodo diretto', 'Direct method', 'Direkte Methode', 'Méthode directe'],
            'statutoryFinancials.validation.not_run' => ['Non eseguita', 'Not run', 'Nicht ausgeführt', 'Non exécutée'],
            'statutoryFinancials.validation.valid' => ['Valida', 'Valid', 'Gültig', 'Valide'],
            'statutoryFinancials.validation.warnings' => ['Con avvisi', 'With warnings', 'Mit Warnungen', 'Avec avertissements'],
            'statutoryFinancials.validation.invalid' => ['Non valida', 'Invalid', 'Ungültig', 'Invalide'],
            'statutoryFinancials.validation.count.errors' => ['Errori', 'Errors', 'Fehler', 'Erreurs'],
            'statutoryFinancials.validation.count.warnings' => ['Avvisi', 'Warnings', 'Warnungen', 'Avertissements'],
            'statutoryFinancials.validation.count.facts' => ['Fatti XBRL', 'XBRL facts', 'XBRL-Fakten', 'Faits XBRL'],
            'statutoryFinancials.validation.code' => ['Codice', 'Code', 'Code', 'Code'],
            'statutoryFinancials.issueCategory.schema' => ['Schema', 'Schema', 'Schema', 'Schéma'],
            'statutoryFinancials.issueCategory.taxonomy' => ['Tassonomia', 'Taxonomy', 'Taxonomie', 'Taxonomie'],
            'statutoryFinancials.issueCategory.cross_statement' => ['Coerenza tra prospetti', 'Cross-statement consistency', 'Konsistenz zwischen Abschlussteilen', 'Cohérence entre états'],
            'statutoryFinancials.issueCategory.notes' => ['Nota integrativa / Anhang', 'Notes / Anhang', 'Anhang / Nota integrativa', 'Annexe / Nota integrativa'],
            'statutoryFinancials.issueCategory.reclassification' => ['Riclassificazione', 'Reclassification', 'Umgliederung', 'Reclassement'],
            'statutoryFinancials.pdfaKind.management_report' => ['Relazione sulla gestione', 'Management report', 'Lagebericht', 'Rapport de gestion'],
            'statutoryFinancials.pdfaKind.board_minutes' => ['Verbale del CdA', 'Board minutes', 'Verwaltungsratsprotokoll', 'Procès-verbal du conseil'],
            'statutoryFinancials.pdfaKind.shareholders_minutes' => ['Verbale dell’assemblea', 'Shareholders’ minutes', 'Generalversammlungsprotokoll', 'Procès-verbal de l’assemblée'],
            'statutoryFinancials.pdfaKind.dossier' => ['Fascicolo completo', 'Complete dossier', 'Gesamtdossier', 'Dossier complet'],
            'statutoryFinancials.artifactKind.xbrl_instance' => ['Istanza XBRL', 'XBRL instance', 'XBRL-Instanz', 'Instance XBRL'],
            'statutoryFinancials.artifactKind.pdfa_management_report' => ['Relazione sulla gestione PDF/A', 'Management report PDF/A', 'Lagebericht PDF/A', 'Rapport de gestion PDF/A'],
            'statutoryFinancials.artifactKind.pdfa_board_minutes' => ['Verbale del CdA PDF/A', 'Board minutes PDF/A', 'Verwaltungsratsprotokoll PDF/A', 'Procès-verbal du conseil PDF/A'],
            'statutoryFinancials.artifactKind.pdfa_shareholders_minutes' => ['Verbale dell’assemblea PDF/A', 'Shareholders’ minutes PDF/A', 'Generalversammlungsprotokoll PDF/A', 'Procès-verbal de l’assemblée PDF/A'],
            'statutoryFinancials.artifactKind.pdfa_dossier' => ['Fascicolo completo PDF/A', 'Complete dossier PDF/A', 'Gesamtdossier PDF/A', 'Dossier complet PDF/A'],
            'statutoryFinancials.check.pdfa_identification' => ['Identificazione PDF/A nei metadati', 'PDF/A identification in the metadata', 'PDF/A-Kennzeichnung in den Metadaten', 'Identification PDF/A dans les métadonnées'],
            'statutoryFinancials.check.xmp_metadata' => ['Metadati XMP presenti', 'XMP metadata present', 'XMP-Metadaten vorhanden', 'Métadonnées XMP présentes'],
            'statutoryFinancials.check.fonts_embedded' => ['Font incorporati', 'Fonts embedded', 'Schriften eingebettet', 'Polices intégrées'],
            'statutoryFinancials.check.output_intent' => ['Profilo colore di output (OutputIntent)', 'Output colour profile (OutputIntent)', 'Ausgabe-Farbprofil (OutputIntent)', 'Profil colorimétrique de sortie (OutputIntent)'],
            'statutoryFinancials.check.encryption' => ['Nessuna cifratura', 'No encryption', 'Keine Verschlüsselung', 'Aucun chiffrement'],
            'statutoryFinancials.check.javascript' => ['Nessun JavaScript', 'No JavaScript', 'Kein JavaScript', 'Aucun JavaScript'],
            'statutoryFinancials.check.transparency' => ['Trasparenze non ammesse assenti', 'No disallowed transparency', 'Keine unzulässige Transparenz', 'Aucune transparence interdite'],
            'statutoryFinancials.check.external_content' => ['Nessun contenuto esterno', 'No external content', 'Keine externen Inhalte', 'Aucun contenu externe'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'statutoryFinancials.error.generic' => ['Operazione non riuscita. Riprova più tardi.', 'The operation failed. Try again later.', 'Der Vorgang ist fehlgeschlagen. Versuchen Sie es später erneut.', 'L’opération a échoué. Réessayez plus tard.'],
            'statutoryFinancials.error.unsupported_standard' => ['Lo schema contabile scelto non è supportato.', 'The chosen accounting standard is not supported.', 'Der gewählte Rechnungslegungsstandard wird nicht unterstützt.', 'Le référentiel comptable choisi n’est pas pris en charge.'],
            'statutoryFinancials.error.fiscal_year_not_found' => ['L’esercizio indicato non esiste.', 'The fiscal year does not exist.', 'Das angegebene Geschäftsjahr existiert nicht.', 'L’exercice indiqué n’existe pas.'],
            'statutoryFinancials.error.comparative_same' => ['L’esercizio comparativo deve essere diverso da quello del bilancio.', 'The comparative year must differ from the reporting year.', 'Das Vergleichsjahr muss sich vom Berichtsjahr unterscheiden.', 'L’exercice comparatif doit être différent de l’exercice présenté.'],
            'statutoryFinancials.error.comparative_year_same' => ['L’esercizio comparativo deve essere diverso da quello del bilancio.', 'The comparative year must differ from the reporting year.', 'Das Vergleichsjahr muss sich vom Berichtsjahr unterscheiden.', 'L’exercice comparatif doit être différent de l’exercice présenté.'],
            'statutoryFinancials.error.income_statement_method_not_allowed' => ['Lo schema scelto non ammette questo metodo di conto economico.', 'The chosen standard does not allow this income statement method.', 'Der gewählte Standard lässt diese Erfolgsrechnungsmethode nicht zu.', 'Le référentiel choisi n’autorise pas cette méthode de compte de résultat.'],
            'statutoryFinancials.error.transition_not_allowed' => ['Questo passaggio di stato non è consentito dallo stato attuale del fascicolo.', 'This status change is not allowed from the dossier’s current status.', 'Dieser Statuswechsel ist im aktuellen Status des Dossiers nicht erlaubt.', 'Ce changement de statut n’est pas autorisé depuis le statut actuel du dossier.'],
            'statutoryFinancials.error.filing_requires_valid_xbrl' => ['Per il deposito serve un’istanza XBRL valida: esporta e valida il pacchetto.', 'Filing requires a valid XBRL instance: export and validate the package.', 'Für die Hinterlegung ist eine gültige XBRL-Instanz nötig: Paket exportieren und validieren.', 'Le dépôt exige une instance XBRL valide : exportez et validez le dossier.'],
            'statutoryFinancials.error.dossier_locked' => ['Il fascicolo è bloccato o depositato e non può essere modificato.', 'The dossier is locked or filed and cannot be changed.', 'Das Dossier ist gesperrt oder hinterlegt und kann nicht geändert werden.', 'Le dossier est verrouillé ou déposé et ne peut pas être modifié.'],
            'statutoryFinancials.error.statements_not_generated' => ['Genera prima i prospetti di bilancio.', 'Generate the statements first.', 'Erstellen Sie zuerst die Abschlussrechnungen.', 'Générez d’abord les états financiers.'],
            'statutoryFinancials.error.notes_not_populated' => ['Compila prima la nota integrativa (Anhang).', 'Populate the notes (Anhang) first.', 'Befüllen Sie zuerst den Anhang.', 'Renseignez d’abord l’annexe.'],
            'statutoryFinancials.error.artifact_missing' => ['Il documento richiesto non è stato trovato.', 'The requested document was not found.', 'Das angeforderte Dokument wurde nicht gefunden.', 'Le document demandé est introuvable.'],
            'statutoryFinancials.error.artifact_not_pdfa' => ['Il documento non è un PDF/A e non può essere verificato.', 'The document is not a PDF/A and cannot be verified.', 'Das Dokument ist kein PDF/A und kann nicht geprüft werden.', 'Le document n’est pas un PDF/A et ne peut pas être vérifié.'],
            'statutoryFinancials.error.pdfa_kind_unknown' => ['Tipo di documento PDF/A sconosciuto.', 'Unknown PDF/A document type.', 'Unbekannter PDF/A-Dokumenttyp.', 'Type de document PDF/A inconnu.'],
            'statutoryFinancials.error.pdfa_not_compliant' => ['Il PDF generato non è conforme al profilo PDF/A.', 'The generated PDF does not comply with the PDF/A profile.', 'Das erzeugte PDF entspricht nicht dem PDF/A-Profil.', 'Le PDF généré n’est pas conforme au profil PDF/A.'],
            'statutoryFinancials.error.pdfa_font_missing' => ['Manca il font necessario per il PDF/A.', 'The font required for the PDF/A is missing.', 'Die für das PDF/A nötige Schrift fehlt.', 'La police nécessaire au PDF/A est absente.'],
            'statutoryFinancials.error.pdfa_font_invalid' => ['Il font per il PDF/A non è valido.', 'The font for the PDF/A is not valid.', 'Die Schrift für das PDF/A ist ungültig.', 'La police pour le PDF/A n’est pas valide.'],
            'statutoryFinancials.error.pdfa_profile_missing' => ['Manca il profilo colore per il PDF/A.', 'The colour profile for the PDF/A is missing.', 'Das Farbprofil für das PDF/A fehlt.', 'Le profil colorimétrique pour le PDF/A est absent.'],
            'statutoryFinancials.error.pdfa_profile_unsupported' => ['Il profilo PDF/A richiesto non è supportato.', 'The requested PDF/A profile is not supported.', 'Das angeforderte PDF/A-Profil wird nicht unterstützt.', 'Le profil PDF/A demandé n’est pas pris en charge.'],
            'statutoryFinancials.problem.fiscal_year_required' => ['Scegli l’esercizio del bilancio.', 'Choose the fiscal year of the accounts.', 'Wählen Sie das Geschäftsjahr der Jahresrechnung.', 'Choisissez l’exercice des comptes.'],
            'statutoryFinancials.problem.comparative_same' => ['L’esercizio comparativo deve essere diverso da quello del bilancio.', 'The comparative year must differ from the reporting year.', 'Das Vergleichsjahr muss sich vom Berichtsjahr unterscheiden.', 'L’exercice comparatif doit être différent de l’exercice présenté.'],
            'statutoryFinancials.problem.standard_required' => ['Scegli lo schema contabile.', 'Choose the accounting standard.', 'Wählen Sie den Rechnungslegungsstandard.', 'Choisissez le référentiel comptable.'],
            'statutoryFinancials.problem.cogs_allocation_invalid' => ['Le percentuali di ripartizione devono essere comprese tra 0 e 100.', 'The allocation percentages must be between 0 and 100.', 'Die Aufteilungsprozente müssen zwischen 0 und 100 liegen.', 'Les pourcentages de répartition doivent être compris entre 0 et 100.'],
            'statutoryFinancials.problem.hidden_item_invalid' => ['Ogni voce delle riserve latenti richiede descrizione, valore contabile e valore FER.', 'Every hidden reserve item needs a description, a book value and a FER value.', 'Jede Position der stillen Reserven braucht eine Bezeichnung, einen Buchwert und einen FER-Wert.', 'Chaque poste de réserves latentes exige un libellé, une valeur comptable et une valeur FER.'],
            'statutoryFinancials.minutes.problem.kind_required' => ['Scegli almeno un verbale.', 'Choose at least one set of minutes.', 'Wählen Sie mindestens ein Protokoll.', 'Choisissez au moins un procès-verbal.'],
            'statutoryFinancials.minutes.problem.date_required' => ['Indica la data della riunione.', 'Enter the meeting date.', 'Geben Sie das Sitzungsdatum an.', 'Indiquez la date de la réunion.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function fields(): array
    {
        return [
            'statutoryFinancials.field.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'statutoryFinancials.field.fiscalYear' => ['Esercizio', 'Fiscal year', 'Geschäftsjahr', 'Exercice'],
            'statutoryFinancials.field.comparativeYear' => ['Esercizio comparativo', 'Comparative year', 'Vergleichsjahr', 'Exercice comparatif'],
            'statutoryFinancials.field.standard' => ['Schema contabile', 'Accounting standard', 'Rechnungslegungsstandard', 'Référentiel comptable'],
            'statutoryFinancials.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'statutoryFinancials.field.validation' => ['Validazione', 'Validation', 'Validierung', 'Validation'],
            'statutoryFinancials.field.netResult' => ['Risultato netto', 'Net result', 'Jahresergebnis', 'Résultat net'],
            'statutoryFinancials.field.generatedAt' => ['Generato il', 'Generated on', 'Erstellt am', 'Généré le'],
            'statutoryFinancials.field.actions' => ['Azioni', 'Actions', 'Aktionen', 'Actions'],
            'statutoryFinancials.field.roundingMode' => ['Arrotondamento', 'Rounding', 'Rundung', 'Arrondi'],
            'statutoryFinancials.field.incomeStatementMethod' => ['Metodo del conto economico', 'Income statement method', 'Methode der Erfolgsrechnung', 'Méthode du compte de résultat'],
            'statutoryFinancials.field.cashFlowMethod' => ['Metodo del rendiconto finanziario', 'Cash flow method', 'Methode der Geldflussrechnung', 'Méthode du tableau de flux de trésorerie'],
            'statutoryFinancials.field.cogsPersonnel' => ['Personale a costo del venduto (%)', 'Personnel in cost of sales (%)', 'Personal im Umsatzkosten (%)', 'Personnel dans le coût des ventes (%)'],
            'statutoryFinancials.field.cogsOtherOpex' => ['Altri costi operativi a costo del venduto (%)', 'Other operating costs in cost of sales (%)', 'Übrige Betriebskosten in den Umsatzkosten (%)', 'Autres charges d’exploitation dans le coût des ventes (%)'],
            'statutoryFinancials.field.cogsDepr' => ['Ammortamenti a costo del venduto (%)', 'Depreciation in cost of sales (%)', 'Abschreibungen in den Umsatzkosten (%)', 'Amortissements dans le coût des ventes (%)'],
            'statutoryFinancials.field.shareCapital' => ['Capitale sociale', 'Share capital', 'Aktien-/Stammkapital', 'Capital social'],
            'statutoryFinancials.field.dividends' => ['Dividendi proposti', 'Proposed dividends', 'Vorgeschlagene Dividenden', 'Dividendes proposés'],
            'statutoryFinancials.field.otherReserves' => ['Altre riserve', 'Other reserves', 'Andere Reserven', 'Autres réserves'],
            'statutoryFinancials.field.carriedForward' => ['Utile a nuovo', 'Carried forward', 'Gewinnvortrag', 'Report à nouveau'],
            'statutoryFinancials.field.hiddenEnabled' => ['Calcola le riserve latenti', 'Calculate the hidden reserves', 'Stille Reserven berechnen', 'Calculer les réserves latentes'],
            'statutoryFinancials.field.taxRatePercent' => ['Aliquota fiscale (%)', 'Tax rate (%)', 'Steuersatz (%)', 'Taux d’impôt (%)'],
            'statutoryFinancials.field.reservesCreated' => ['Riserve costituite nell’esercizio', 'Reserves created in the year', 'Im Jahr gebildete Reserven', 'Réserves constituées dans l’exercice'],
            'statutoryFinancials.field.reservesReleased' => ['Riserve sciolte nell’esercizio', 'Reserves released in the year', 'Im Jahr aufgelöste Reserven', 'Réserves dissoutes dans l’exercice'],
            'statutoryFinancials.field.itemLabel' => ['Descrizione', 'Description', 'Bezeichnung', 'Libellé'],
            'statutoryFinancials.field.bookValue' => ['Valore contabile', 'Book value', 'Buchwert', 'Valeur comptable'],
            'statutoryFinancials.field.ferValue' => ['Valore FER', 'FER value', 'FER-Wert', 'Valeur FER'],
            'statutoryFinancials.field.filingReference' => ['Riferimento del deposito', 'Filing reference', 'Hinterlegungsreferenz', 'Référence du dépôt'],
            'statutoryFinancials.field.account' => ['Conto', 'Account', 'Konto', 'Compte'],
            'statutoryFinancials.field.balance' => ['Saldo', 'Balance', 'Saldo', 'Solde'],
            'statutoryFinancials.field.landedOn' => ['Voce di destinazione', 'Line it landed on', 'Zielposition', 'Poste de destination'],
            'statutoryFinancials.field.side' => ['Lato', 'Side', 'Seite', 'Côté'],
            'statutoryFinancials.field.overwriteNarrative' => ['Sovrascrivi le sezioni narrative modificate', 'Overwrite the edited narrative sections', 'Bearbeitete Textabschnitte überschreiben', 'Écraser les sections narratives modifiées'],
            'statutoryFinancials.hint.title' => ['Facoltativo: se vuoto il titolo è generato dall’esercizio e dallo schema.', 'Optional: when empty the title is built from the fiscal year and the standard.', 'Optional: Leer wird der Titel aus Geschäftsjahr und Standard gebildet.', 'Facultatif : s’il est vide, le titre est construit à partir de l’exercice et du référentiel.'],
            'statutoryFinancials.hint.noComparative' => ['Nessun comparativo', 'No comparative', 'Kein Vergleich', 'Aucun comparatif'],
            'statutoryFinancials.hint.cogsAllocation' => ['Quota di ciascun costo per natura attribuita al costo del venduto; il resto va alle spese di vendita e amministrative.', 'Share of each cost by nature allocated to cost of sales; the rest goes to selling and administrative expenses.', 'Anteil jeder Aufwandsart, der den Umsatzkosten zugeordnet wird; der Rest geht in Vertriebs- und Verwaltungsaufwand.', 'Part de chaque charge par nature affectée au coût des ventes ; le solde va aux frais de vente et d’administration.'],
            'statutoryFinancials.hint.profitAllocation' => ['Proposta di destinazione dell’utile: la riserva legale è calcolata dal sistema.', 'Profit allocation proposal: the legal reserve is calculated by the system.', 'Antrag zur Gewinnverwendung: Die gesetzliche Reserve berechnet das System.', 'Proposition d’affectation du bénéfice : la réserve légale est calculée par le système.'],
            'statutoryFinancials.hint.hiddenReserves' => ['Riserve latenti per la lettura Swiss GAAP FER: confronto tra valori contabili e valori FER, con imposte differite.', 'Hidden reserves for the Swiss GAAP FER view: book values against FER values, with deferred taxes.', 'Stille Reserven für die Swiss-GAAP-FER-Sicht: Buchwerte gegenüber FER-Werten, mit latenten Steuern.', 'Réserves latentes pour la lecture Swiss GAAP FER : valeurs comptables contre valeurs FER, avec impôts différés.'],
            'statutoryFinancials.hint.simulator' => ['Modifica i parametri per vedere l’effetto sul DSCR a 6 mesi e sugli allarmi, senza salvare nulla.', 'Change the parameters to see the effect on the 6-month DSCR and the alerts, without saving anything.', 'Ändern Sie die Parameter, um die Wirkung auf den 6-Monats-DSCR und die Warnungen zu sehen, ohne etwas zu speichern.', 'Modifiez les paramètres pour voir l’effet sur le DSCR à 6 mois et les alertes, sans rien enregistrer.'],
            'statutoryFinancials.hint.filingReference' => ['Numero di protocollo o riferimento del registro (Registro Imprese / Registro di commercio).', 'Protocol number or register reference (Registro Imprese / commercial register).', 'Protokollnummer oder Registerreferenz (Registro Imprese / Handelsregister).', 'Numéro de protocole ou référence du registre (Registro Imprese / registre du commerce).'],
            'statutoryFinancials.hint.lockRequiresValidated' => ['Si può bloccare solo un fascicolo validato.', 'Only a validated dossier can be locked.', 'Nur ein validiertes Dossier kann gesperrt werden.', 'Seul un dossier validé peut être verrouillé.'],
            'statutoryFinancials.hint.reopenRequiresLocked' => ['Si può riaprire solo un fascicolo bloccato.', 'Only a locked dossier can be reopened.', 'Nur ein gesperrtes Dossier kann wieder geöffnet werden.', 'Seul un dossier verrouillé peut être rouvert.'],
            'statutoryFinancials.hint.fileRequiresLocked' => ['Si può depositare solo un fascicolo bloccato.', 'Only a locked dossier can be filed.', 'Nur ein gesperrtes Dossier kann hinterlegt werden.', 'Seul un dossier verrouillé peut être déposé.'],
            'statutoryFinancials.section.cogsAllocation' => ['Ripartizione sul costo del venduto', 'Allocation to cost of sales', 'Aufteilung auf die Umsatzkosten', 'Répartition sur le coût des ventes'],
            'statutoryFinancials.section.profitAllocation' => ['Destinazione dell’utile', 'Profit allocation', 'Gewinnverwendung', 'Affectation du bénéfice'],
            'statutoryFinancials.section.hiddenReserves' => ['Riserve latenti (Swiss GAAP FER)', 'Hidden reserves (Swiss GAAP FER)', 'Stille Reserven (Swiss GAAP FER)', 'Réserves latentes (Swiss GAAP FER)'],
            'statutoryFinancials.section.hiddenItems' => ['Voci con riserve latenti', 'Items with hidden reserves', 'Positionen mit stillen Reserven', 'Postes avec réserves latentes'],
            'statutoryFinancials.section.quadrature' => ['Quadratura degli arrotondamenti', 'Rounding quadrature', 'Rundungsabstimmung', 'Équilibrage des arrondis'],
            'statutoryFinancials.section.bifront' => ['Conti bifronti', 'Bifront accounts', 'Beidseitige Konten', 'Comptes bifrons'],
            'statutoryFinancials.section.unmapped' => ['Conti non mappati', 'Unmapped accounts', 'Nicht zugeordnete Konten', 'Comptes non mappés'],
            'statutoryFinancials.section.ratios' => ['Indici di bilancio', 'Financial ratios', 'Finanzkennzahlen', 'Ratios financiers'],
            'statutoryFinancials.section.simulator' => ['Simulatore what-if del DSCR', 'DSCR what-if simulator', 'DSCR-What-if-Simulator', 'Simulateur what-if du DSCR'],
            'statutoryFinancials.section.validation' => ['Esito della validazione', 'Validation result', 'Validierungsergebnis', 'Résultat de la validation'],
            'statutoryFinancials.section.xbrl' => ['Esportazione e validazione XBRL', 'XBRL export and validation', 'XBRL-Export und Validierung', 'Export et validation XBRL'],
            'statutoryFinancials.section.pdfa' => ['Documenti PDF/A', 'PDF/A documents', 'PDF/A-Dokumente', 'Documents PDF/A'],
            'statutoryFinancials.eligibility.recommended' => ['Schema consigliato', 'Recommended standard', 'Empfohlener Standard', 'Référentiel recommandé'],
            'statutoryFinancials.eligibility.totalAssets' => ['Totale attivo', 'Total assets', 'Bilanzsumme', 'Total de l’actif'],
            'statutoryFinancials.eligibility.revenue' => ['Ricavi', 'Revenue', 'Umsatzerlöse', 'Chiffre d’affaires'],
            'statutoryFinancials.eligibility.employees' => ['Dipendenti medi', 'Average employees', 'Durchschnittliche Mitarbeitende', 'Effectif moyen'],
            'statutoryFinancials.eligibility.limit' => ['limite', 'limit', 'Grenze', 'limite'],
            'statutoryFinancials.eligibility.exceeded' => ['Soglie superate', 'Exceeded thresholds', 'Überschrittene Schwellen', 'Seuils dépassés'],
            'statutoryFinancials.eligibility.required' => ['Prospetti obbligatori', 'Required statements', 'Erforderliche Abschlussteile', 'États obligatoires'],
            'statutoryFinancials.eligibility.pending' => ['La verifica delle soglie non è ancora disponibile.', 'The threshold check is not available yet.', 'Die Schwellenwertprüfung ist noch nicht verfügbar.', 'Le contrôle des seuils n’est pas encore disponible.'],
            'statutoryFinancials.eligibility.criterion.assets' => ['Totale attivo', 'Total assets', 'Bilanzsumme', 'Total de l’actif'],
            'statutoryFinancials.eligibility.criterion.revenue' => ['Ricavi', 'Revenue', 'Umsatzerlöse', 'Chiffre d’affaires'],
            'statutoryFinancials.eligibility.criterion.employees' => ['Dipendenti', 'Employees', 'Mitarbeitende', 'Effectif'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'statutoryFinancials.action.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'statutoryFinancials.action.newDossier' => ['Nuovo fascicolo', 'New dossier', 'Neues Dossier', 'Nouveau dossier'],
            'statutoryFinancials.action.createDossier' => ['Crea il fascicolo', 'Create the dossier', 'Dossier erstellen', 'Créer le dossier'],
            'statutoryFinancials.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'statutoryFinancials.action.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'statutoryFinancials.action.back' => ['Indietro', 'Back', 'Zurück', 'Retour'],
            'statutoryFinancials.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'statutoryFinancials.action.confirm' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'statutoryFinancials.action.next' => ['Avanti', 'Next', 'Weiter', 'Suivant'],
            'statutoryFinancials.action.previous' => ['Indietro', 'Previous', 'Zurück', 'Précédent'],
            'statutoryFinancials.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'statutoryFinancials.action.discard' => ['Annulla le modifiche', 'Discard changes', 'Änderungen verwerfen', 'Annuler les modifications'],
            'statutoryFinancials.action.reset' => ['Azzera', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'statutoryFinancials.action.addRow' => ['Aggiungi riga', 'Add row', 'Zeile hinzufügen', 'Ajouter une ligne'],
            'statutoryFinancials.action.removeRow' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'statutoryFinancials.action.switchToRecommended' => ['Passa allo schema consigliato', 'Switch to the recommended standard', 'Zum empfohlenen Standard wechseln', 'Passer au référentiel recommandé'],
            'statutoryFinancials.action.generate' => ['Genera i prospetti', 'Generate the statements', 'Abschlussrechnungen erstellen', 'Générer les états'],
            'statutoryFinancials.action.regenerate' => ['Rigenera i prospetti', 'Regenerate the statements', 'Abschlussrechnungen neu erstellen', 'Régénérer les états'],
            'statutoryFinancials.action.generateCashFlow' => ['(Ri)genera il rendiconto finanziario', '(Re)generate the cash flow statement', 'Geldflussrechnung (neu) erstellen', '(Re)générer le tableau de flux'],
            'statutoryFinancials.action.toggleLine' => ['Espandi o comprimi', 'Expand or collapse', 'Ein- oder ausklappen', 'Développer ou réduire'],
            'statutoryFinancials.action.populateNotes' => ['Compila dai sottosistemi', 'Populate from the subledgers', 'Aus den Nebenbüchern befüllen', 'Renseigner depuis les sous-systèmes'],
            'statutoryFinancials.action.saveToPackage' => ['Salva nel pacchetto', 'Save to the package', 'Im Paket speichern', 'Enregistrer dans le dossier'],
            'statutoryFinancials.action.exportXbrl' => ['Valida ed esporta XBRL', 'Validate and export XBRL', 'XBRL validieren und exportieren', 'Valider et exporter XBRL'],
            'statutoryFinancials.action.downloadXbrl' => ['Scarica l’istanza XBRL', 'Download the XBRL instance', 'XBRL-Instanz herunterladen', 'Télécharger l’instance XBRL'],
            'statutoryFinancials.action.minutesWizard' => ['Generatore di verbali', 'Minutes generator', 'Protokollgenerator', 'Générateur de procès-verbaux'],
            'statutoryFinancials.action.generateMinutes' => ['Genera i verbali', 'Generate the minutes', 'Protokolle erstellen', 'Générer les procès-verbaux'],
            'statutoryFinancials.action.verify' => ['Verifica', 'Verify', 'Prüfen', 'Vérifier'],
            'statutoryFinancials.action.download' => ['Scarica', 'Download', 'Herunterladen', 'Télécharger'],
            'statutoryFinancials.action.lock' => ['Blocca', 'Lock', 'Sperren', 'Verrouiller'],
            'statutoryFinancials.action.reopen' => ['Riapri', 'Reopen', 'Wieder öffnen', 'Rouvrir'],
            'statutoryFinancials.action.file' => ['Deposita', 'File', 'Hinterlegen', 'Déposer'],
            'statutoryFinancials.confirm.delete' => ['Eliminare il fascicolo? L’operazione non si può annullare.', 'Delete the dossier? This cannot be undone.', 'Das Dossier löschen? Dies kann nicht rückgängig gemacht werden.', 'Supprimer le dossier ? Cette action est irréversible.'],
            'statutoryFinancials.confirm.lock' => ['Bloccare il fascicolo? Non sarà più modificabile finché non lo riapri.', 'Lock the dossier? It cannot be edited until you reopen it.', 'Das Dossier sperren? Es kann erst nach dem Wiedereröffnen bearbeitet werden.', 'Verrouiller le dossier ? Il ne sera plus modifiable tant que vous ne le rouvrez pas.'],
            'statutoryFinancials.confirm.reopen' => ['Riaprire il fascicolo? Tornerà in bozza e dovrà essere validato di nuovo.', 'Reopen the dossier? It goes back to draft and must be validated again.', 'Das Dossier wieder öffnen? Es wird zum Entwurf und muss erneut validiert werden.', 'Rouvrir le dossier ? Il redevient brouillon et doit être validé à nouveau.'],
            'statutoryFinancials.filing.title' => ['Deposito del fascicolo', 'Filing the dossier', 'Hinterlegung des Dossiers', 'Dépôt du dossier'],
            'statutoryFinancials.minutes.title' => ['Generatore di verbali', 'Minutes generator', 'Protokollgenerator', 'Générateur de procès-verbaux'],
            'statutoryFinancials.minutes.step.kind' => ['Tipo di verbale', 'Type of minutes', 'Art des Protokolls', 'Type de procès-verbal'],
            'statutoryFinancials.minutes.step.meeting' => ['Riunione', 'Meeting', 'Sitzung', 'Réunion'],
            'statutoryFinancials.minutes.step.review' => ['Revisione', 'Review', 'Prüfung', 'Révision'],
            'statutoryFinancials.minutes.meetingDate' => ['Data della riunione', 'Meeting date', 'Sitzungsdatum', 'Date de la réunion'],
            'statutoryFinancials.minutes.meetingPlace' => ['Luogo', 'Place', 'Ort', 'Lieu'],
            'statutoryFinancials.minutes.chair' => ['Presidente', 'Chair', 'Vorsitz', 'Président'],
            'statutoryFinancials.minutes.secretary' => ['Segretario', 'Secretary', 'Sekretär', 'Secrétaire'],
            'statutoryFinancials.minutes.attendees' => ['Presenti', 'Attendees', 'Anwesende', 'Présents'],
            'statutoryFinancials.minutes.attendeesHint' => ['Scrivi un nome e premi Invio', 'Type a name and press Enter', 'Namen eingeben und Enter drücken', 'Saisissez un nom et appuyez sur Entrée'],
            'statutoryFinancials.minutes.reviewHint' => ['La destinazione dell’utile qui sotto sarà inclusa nel verbale così come l’ha formulata il sistema.', 'The profit allocation below will be included in the minutes as the system worded it.', 'Die untenstehende Gewinnverwendung wird so ins Protokoll übernommen, wie das System sie formuliert hat.', 'L’affectation du bénéfice ci-dessous sera reprise dans le procès-verbal telle que le système l’a rédigée.'],
            'statutoryFinancials.minutes.noAllocation' => ['Nessuna proposta di destinazione dell’utile disponibile: genera prima i prospetti.', 'No profit allocation proposal available: generate the statements first.', 'Kein Antrag zur Gewinnverwendung vorhanden: Erstellen Sie zuerst die Abschlussrechnungen.', 'Aucune proposition d’affectation du bénéfice disponible : générez d’abord les états.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function statements(): array
    {
        return [
            'statutoryFinancials.statement.line' => ['Voce', 'Line', 'Position', 'Poste'],
            'statutoryFinancials.statement.current' => ['Esercizio corrente', 'Current year', 'Berichtsjahr', 'Exercice courant'],
            'statutoryFinancials.statement.comparative' => ['Esercizio precedente', 'Prior year', 'Vorjahr', 'Exercice précédent'],
            'statutoryFinancials.statement.roundingTag' => ['Arrotondamento', 'Rounding', 'Rundung', 'Arrondi'],
            'statutoryFinancials.statement.totalAssets' => ['Totale attivo', 'Total assets', 'Total Aktiven', 'Total de l’actif'],
            'statutoryFinancials.statement.totalLiabilitiesEquity' => ['Totale passivo e patrimonio netto', 'Total liabilities and equity', 'Total Passiven', 'Total du passif et des capitaux propres'],
            'statutoryFinancials.statement.balance_sheet' => ['Stato patrimoniale', 'Balance sheet', 'Bilanz', 'Bilan'],
            'statutoryFinancials.statement.income_statement' => ['Conto economico', 'Income statement', 'Erfolgsrechnung', 'Compte de résultat'],
            'statutoryFinancials.statement.cash_flow' => ['Rendiconto finanziario', 'Cash flow statement', 'Geldflussrechnung', 'Tableau de flux de trésorerie'],
            'statutoryFinancials.statement.notes' => ['Nota integrativa / Anhang', 'Notes / Anhang', 'Anhang / Nota integrativa', 'Annexe / Nota integrativa'],
            'statutoryFinancials.statement.management_report' => ['Relazione sulla gestione', 'Management report', 'Lagebericht', 'Rapport de gestion'],
            'statutoryFinancials.statement.asset_side' => ['Lato attivo', 'Asset side', 'Aktivseite', 'Côté actif'],
            'statutoryFinancials.statement.liability_side' => ['Lato passivo', 'Liability side', 'Passivseite', 'Côté passif'],
            'statutoryFinancials.quadrature.reserve' => ['Riserva da arrotondamento', 'Rounding reserve', 'Rundungsreserve', 'Réserve d’arrondi'],
            'statutoryFinancials.quadrature.income' => ['Arrotondamenti attivi', 'Rounding income', 'Rundungsertrag', 'Produits d’arrondi'],
            'statutoryFinancials.quadrature.cost' => ['Arrotondamenti passivi', 'Rounding cost', 'Rundungsaufwand', 'Charges d’arrondi'],
            'statutoryFinancials.quadrature.balanceSheetResidual' => ['Residuo dello stato patrimoniale', 'Balance sheet residual', 'Restdifferenz der Bilanz', 'Écart résiduel du bilan'],
            'statutoryFinancials.quadrature.incomeStatementResidual' => ['Residuo del conto economico', 'Income statement residual', 'Restdifferenz der Erfolgsrechnung', 'Écart résiduel du compte de résultat'],
            'statutoryFinancials.cashFlow.reconciled' => ['Riconciliazione', 'Reconciliation', 'Abstimmung', 'Rapprochement'],
            'statutoryFinancials.cashFlow.reconciledYes' => ['Riconciliato con lo stato patrimoniale', 'Reconciled with the balance sheet', 'Mit der Bilanz abgestimmt', 'Rapproché avec le bilan'],
            'statutoryFinancials.cashFlow.reconciledNo' => ['Non riconciliato', 'Not reconciled', 'Nicht abgestimmt', 'Non rapproché'],
            'statutoryFinancials.cashFlow.openingCash' => ['Disponibilità iniziali', 'Opening cash', 'Anfangsbestand liquide Mittel', 'Trésorerie d’ouverture'],
            'statutoryFinancials.cashFlow.closingCash' => ['Disponibilità finali', 'Closing cash', 'Endbestand liquide Mittel', 'Trésorerie de clôture'],
            'statutoryFinancials.cashFlow.balanceSheetCash' => ['Disponibilità a stato patrimoniale', 'Cash on the balance sheet', 'Liquide Mittel laut Bilanz', 'Trésorerie au bilan'],
            'statutoryFinancials.cashFlow.unexplained' => ['Differenza non spiegata', 'Unexplained difference', 'Nicht erklärte Differenz', 'Écart non expliqué'],
            'statutoryFinancials.cashFlow.residual' => ['Residuo', 'Residual', 'Restdifferenz', 'Écart résiduel'],
            'statutoryFinancials.allocation.article' => ['Riferimento normativo', 'Legal reference', 'Rechtsgrundlage', 'Référence légale'],
            'statutoryFinancials.allocation.netProfit' => ['Utile d’esercizio', 'Net profit for the year', 'Jahresgewinn', 'Bénéfice de l’exercice'],
            'statutoryFinancials.allocation.carriedForwardIn' => ['Utili portati a nuovo', 'Profit brought forward', 'Gewinnvortrag Vorjahr', 'Bénéfice reporté'],
            'statutoryFinancials.allocation.balanceSheetProfit' => ['Utile a disposizione', 'Profit available', 'Bilanzgewinn', 'Bénéfice au bilan'],
            'statutoryFinancials.allocation.shareCapital' => ['Capitale sociale', 'Share capital', 'Aktien-/Stammkapital', 'Capital social'],
            'statutoryFinancials.allocation.legalReserveCap' => ['Tetto della riserva legale', 'Legal reserve cap', 'Obergrenze der gesetzlichen Reserve', 'Plafond de la réserve légale'],
            'statutoryFinancials.allocation.legalReserveExisting' => ['Riserva legale esistente', 'Existing legal reserve', 'Bestehende gesetzliche Reserve', 'Réserve légale existante'],
            'statutoryFinancials.allocation.legalReserveRoom' => ['Margine disponibile', 'Room left', 'Verbleibender Spielraum', 'Marge disponible'],
            'statutoryFinancials.allocation.legalReserveAllocation' => ['Accantonamento a riserva legale', 'Legal reserve allocation', 'Zuweisung an die gesetzliche Reserve', 'Dotation à la réserve légale'],
            'statutoryFinancials.allocation.capReached' => ['Tetto raggiunto', 'Cap reached', 'Obergrenze erreicht', 'Plafond atteint'],
            'statutoryFinancials.allocation.dividends' => ['Dividendi', 'Dividends', 'Dividenden', 'Dividendes'],
            'statutoryFinancials.allocation.otherReserves' => ['Altre riserve', 'Other reserves', 'Andere Reserven', 'Autres réserves'],
            'statutoryFinancials.allocation.carriedForwardOut' => ['Utile da riportare a nuovo', 'Profit carried forward', 'Gewinnvortrag auf neue Rechnung', 'Bénéfice à reporter'],
            'statutoryFinancials.hidden.totalReserves' => ['Riserve latenti totali', 'Total hidden reserves', 'Stille Reserven total', 'Réserves latentes totales'],
            'statutoryFinancials.hidden.deferredTax' => ['Imposte differite sulle riserve', 'Deferred tax on the reserves', 'Latente Steuern auf den Reserven', 'Impôts différés sur les réserves'],
            'statutoryFinancials.hidden.netRelease' => ['Scioglimento netto', 'Net release', 'Nettoauflösung', 'Dissolution nette'],
            'statutoryFinancials.hidden.statutoryView' => ['Lettura legale (CO)', 'Statutory view (CO)', 'Gesetzliche Sicht (OR)', 'Vue légale (CO)'],
            'statutoryFinancials.hidden.ferView' => ['Lettura Swiss GAAP FER', 'Swiss GAAP FER view', 'Swiss-GAAP-FER-Sicht', 'Vue Swiss GAAP FER'],
            'statutoryFinancials.hidden.anhangDisclosure' => ['Informativa nell’Anhang', 'Disclosure in the Anhang', 'Angabe im Anhang', 'Information dans l’annexe'],
            'statutoryFinancials.hidden.equity' => ['Patrimonio netto', 'Equity', 'Eigenkapital', 'Capitaux propres'],
            'statutoryFinancials.hidden.profit' => ['Utile', 'Profit', 'Gewinn', 'Bénéfice'],
            'statutoryFinancials.hidden.required' => ['Obbligatoria', 'Required', 'Erforderlich', 'Requise'],
            'statutoryFinancials.hidden.notRequired' => ['Non richiesta', 'Not required', 'Nicht erforderlich', 'Non requise'],
            'statutoryFinancials.hidden.reserve' => ['Riserva latente', 'Hidden reserve', 'Stille Reserve', 'Réserve latente'],
            'statutoryFinancials.empty.dossiers' => ['Nessun fascicolo di bilancio. Creane uno per iniziare.', 'No accounts dossier yet. Create one to start.', 'Noch kein Abschlussdossier. Erstellen Sie eines, um zu beginnen.', 'Aucun dossier de comptes. Créez-en un pour commencer.'],
            'statutoryFinancials.empty.statements' => ['I prospetti non sono ancora stati generati. Premi “Genera i prospetti”.', 'The statements have not been generated yet. Press “Generate the statements”.', 'Die Abschlussrechnungen wurden noch nicht erstellt. Klicken Sie auf „Abschlussrechnungen erstellen“.', 'Les états n’ont pas encore été générés. Cliquez sur « Générer les états ».'],
            'statutoryFinancials.empty.cashFlow' => ['Il rendiconto finanziario non è ancora stato generato.', 'The cash flow statement has not been generated yet.', 'Die Geldflussrechnung wurde noch nicht erstellt.', 'Le tableau de flux de trésorerie n’a pas encore été généré.'],
            'statutoryFinancials.empty.bifront' => ['Nessun conto bifronte.', 'No bifront accounts.', 'Keine beidseitigen Konten.', 'Aucun compte bifron.'],
            'statutoryFinancials.empty.lines' => ['Nessuna voce.', 'No lines.', 'Keine Positionen.', 'Aucun poste.'],
            'statutoryFinancials.empty.preview' => ['Genera i prospetti per vedere l’anteprima.', 'Generate the statements to see the preview.', 'Erstellen Sie die Abschlussrechnungen, um die Vorschau zu sehen.', 'Générez les états pour voir l’aperçu.'],
            'statutoryFinancials.preview.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'statutoryFinancials.preview.taxonomyVersion' => ['Versione della tassonomia', 'Taxonomy version', 'Taxonomie-Version', 'Version de la taxonomie'],
            'statutoryFinancials.preview.prefix' => ['Prefisso', 'Prefix', 'Präfix', 'Préfixe'],
            'statutoryFinancials.preview.namespace' => ['Namespace', 'Namespace', 'Namespace', 'Namespace'],
            'statutoryFinancials.preview.entryPoint' => ['Entry point', 'Entry point', 'Entry Point', 'Entry point'],
            'statutoryFinancials.preview.element' => ['Elemento della tassonomia', 'Taxonomy element', 'Taxonomie-Element', 'Élément de la taxonomie'],
            'statutoryFinancials.preview.xbrlExcerpt' => ['Estratto dell’istanza XBRL', 'XBRL instance excerpt', 'Auszug der XBRL-Instanz', 'Extrait de l’instance XBRL'],
            'statutoryFinancials.preview.truncated' => ['L’estratto è troncato: l’istanza completa si scarica dal pacchetto di deposito.', 'The excerpt is truncated: download the full instance from the filing package.', 'Der Auszug ist gekürzt: Die vollständige Instanz laden Sie aus dem Hinterlegungspaket herunter.', 'L’extrait est tronqué : téléchargez l’instance complète depuis le dossier de dépôt.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function notes(): array
    {
        return [
            'statutoryFinancials.empty.notes' => ['La nota integrativa non è ancora stata compilata. Premi “Compila dai sottosistemi”.', 'The notes have not been populated yet. Press “Populate from the subledgers”.', 'Der Anhang wurde noch nicht befüllt. Klicken Sie auf „Aus den Nebenbüchern befüllen“.', 'L’annexe n’a pas encore été renseignée. Cliquez sur « Renseigner depuis les sous-systèmes ».'],
            'statutoryFinancials.empty.rows' => ['Nessuna riga.', 'No rows.', 'Keine Zeilen.', 'Aucune ligne.'],
            'statutoryFinancials.notes.sources' => ['Fonti dei dati', 'Data sources', 'Datenquellen', 'Sources des données'],
            'statutoryFinancials.notes.narrative' => ['Sezioni descrittive', 'Narrative sections', 'Textabschnitte', 'Sections narratives'],
            'statutoryFinancials.notes.fallback' => ['Ricavato dallo stato patrimoniale', 'Derived from the balance sheet', 'Aus der Bilanz abgeleitet', 'Dérivé du bilan'],
            'statutoryFinancials.notes.manual' => ['Modificato a mano', 'Edited by hand', 'Manuell bearbeitet', 'Modifié manuellement'],
            'statutoryFinancials.notes.edited' => ['Modificato', 'Edited', 'Bearbeitet', 'Modifié'],
            'statutoryFinancials.notes.totals' => ['Totali', 'Totals', 'Total', 'Totaux'],
            'statutoryFinancials.notes.table.fixed_assets' => ['Movimenti delle immobilizzazioni', 'Movements of fixed assets', 'Anlagespiegel', 'Mouvements des immobilisations'],
            'statutoryFinancials.notes.table.equity' => ['Movimenti del patrimonio netto', 'Changes in equity', 'Eigenkapitalnachweis', 'Variations des capitaux propres'],
            'statutoryFinancials.notes.table.maturity' => ['Scadenze di crediti e debiti', 'Maturity of receivables and payables', 'Fälligkeiten von Forderungen und Verbindlichkeiten', 'Échéances des créances et des dettes'],
            'statutoryFinancials.notes.table.compensation' => ['Compensi di amministratori e revisori', 'Compensation of directors and auditors', 'Entschädigungen von Verwaltung und Revisionsstelle', 'Rémunérations des administrateurs et des réviseurs'],
            'statutoryFinancials.notes.table.leases' => ['Contratti di leasing', 'Leases', 'Leasingverträge', 'Contrats de leasing'],
            'statutoryFinancials.notes.table.off_balance' => ['Impegni e garanzie fuori bilancio', 'Off-balance-sheet commitments and guarantees', 'Ausserbilanzgeschäfte und Eventualverpflichtungen', 'Engagements et garanties hors bilan'],
            'statutoryFinancials.notes.table.workforce' => ['Organico', 'Workforce', 'Personalbestand', 'Effectif'],
            'statutoryFinancials.notes.table.related_parties' => ['Operazioni con parti correlate', 'Related-party transactions', 'Transaktionen mit nahestehenden Personen', 'Transactions avec des parties liées'],
            'statutoryFinancials.notes.table.taxes' => ['Imposte', 'Taxes', 'Steuern', 'Impôts'],
            'statutoryFinancials.notes.maturity.receivables' => ['Crediti', 'Receivables', 'Forderungen', 'Créances'],
            'statutoryFinancials.notes.maturity.payables' => ['Debiti', 'Payables', 'Verbindlichkeiten', 'Dettes'],
            'statutoryFinancials.notes.value.directors' => ['Amministratori', 'Directors', 'Verwaltungsrat', 'Administrateurs'],
            'statutoryFinancials.notes.value.auditors' => ['Revisori', 'Auditors', 'Revisionsstelle', 'Réviseurs'],
            'statutoryFinancials.notes.value.current' => ['Correnti', 'Current', 'Laufend', 'Courants'],
            'statutoryFinancials.notes.value.deferred' => ['Differite', 'Deferred', 'Latent', 'Différés'],
            'statutoryFinancials.notes.col.group' => ['Gruppo', 'Group', 'Gruppe', 'Groupe'],
            'statutoryFinancials.notes.col.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'statutoryFinancials.notes.col.opening_cost' => ['Costo iniziale', 'Opening cost', 'Anschaffungswert Anfang', 'Coût d’ouverture'],
            'statutoryFinancials.notes.col.additions' => ['Acquisizioni', 'Additions', 'Zugänge', 'Acquisitions'],
            'statutoryFinancials.notes.col.disposals_cost' => ['Cessioni (costo)', 'Disposals (cost)', 'Abgänge (Anschaffungswert)', 'Cessions (coût)'],
            'statutoryFinancials.notes.col.revaluations' => ['Rivalutazioni', 'Revaluations', 'Aufwertungen', 'Réévaluations'],
            'statutoryFinancials.notes.col.impairments' => ['Svalutazioni', 'Impairments', 'Wertbeeinträchtigungen', 'Dépréciations'],
            'statutoryFinancials.notes.col.closing_cost' => ['Costo finale', 'Closing cost', 'Anschaffungswert Ende', 'Coût de clôture'],
            'statutoryFinancials.notes.col.opening_accumulated' => ['Fondo iniziale', 'Opening accumulated', 'Kumuliert Anfang', 'Cumul d’ouverture'],
            'statutoryFinancials.notes.col.depreciation' => ['Ammortamento', 'Depreciation', 'Abschreibung', 'Amortissement'],
            'statutoryFinancials.notes.col.disposals_accumulated' => ['Cessioni (fondo)', 'Disposals (accumulated)', 'Abgänge (kumuliert)', 'Cessions (cumul)'],
            'statutoryFinancials.notes.col.closing_accumulated' => ['Fondo finale', 'Closing accumulated', 'Kumuliert Ende', 'Cumul de clôture'],
            'statutoryFinancials.notes.col.net_book_value' => ['Valore netto contabile', 'Net book value', 'Nettobuchwert', 'Valeur nette comptable'],
            'statutoryFinancials.notes.col.label' => ['Voce', 'Item', 'Position', 'Poste'],
            'statutoryFinancials.notes.col.opening' => ['Saldo iniziale', 'Opening balance', 'Anfangssaldo', 'Solde d’ouverture'],
            'statutoryFinancials.notes.col.movements' => ['Movimenti', 'Movements', 'Bewegungen', 'Mouvements'],
            'statutoryFinancials.notes.col.result_for_year' => ['Risultato dell’esercizio', 'Result for the year', 'Jahresergebnis', 'Résultat de l’exercice'],
            'statutoryFinancials.notes.col.allocation_of_prior_result' => ['Destinazione del risultato precedente', 'Allocation of the prior result', 'Verwendung des Vorjahresergebnisses', 'Affectation du résultat précédent'],
            'statutoryFinancials.notes.col.closing' => ['Saldo finale', 'Closing balance', 'Schlusssaldo', 'Solde de clôture'],
            'statutoryFinancials.notes.col.within_1y' => ['Entro 1 anno', 'Within 1 year', 'Innert 1 Jahr', 'À moins d’un an'],
            'statutoryFinancials.notes.col.between_1y_5y' => ['Da 1 a 5 anni', '1 to 5 years', '1 bis 5 Jahre', 'De 1 à 5 ans'],
            'statutoryFinancials.notes.col.beyond_5y' => ['Oltre 5 anni', 'Beyond 5 years', 'Über 5 Jahre', 'Au-delà de 5 ans'],
            'statutoryFinancials.notes.col.total' => ['Totale', 'Total', 'Total', 'Total'],
            'statutoryFinancials.notes.col.secured' => ['Di cui assistiti da garanzie reali', 'Of which secured', 'Davon besichert', 'Dont garanti par des sûretés'],
            'statutoryFinancials.notes.col.role' => ['Ruolo', 'Role', 'Funktion', 'Fonction'],
            'statutoryFinancials.notes.col.fees' => ['Compensi', 'Fees', 'Honorare', 'Honoraires'],
            'statutoryFinancials.notes.col.advances' => ['Anticipazioni', 'Advances', 'Vorschüsse', 'Avances'],
            'statutoryFinancials.notes.col.guarantees' => ['Garanzie prestate', 'Guarantees given', 'Gewährte Sicherheiten', 'Garanties données'],
            'statutoryFinancials.notes.col.number' => ['Numero', 'Number', 'Nummer', 'Numéro'],
            'statutoryFinancials.notes.col.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'statutoryFinancials.notes.col.lessor' => ['Locatore', 'Lessor', 'Leasinggeber', 'Bailleur'],
            'statutoryFinancials.notes.col.asset_class' => ['Classe di bene', 'Asset class', 'Anlageklasse', 'Catégorie de bien'],
            'statutoryFinancials.notes.col.total_commitment' => ['Impegno totale', 'Total commitment', 'Gesamtverpflichtung', 'Engagement total'],
            'statutoryFinancials.notes.col.closing_liability' => ['Debito residuo', 'Closing liability', 'Schlussverbindlichkeit', 'Dette de clôture'],
            'statutoryFinancials.notes.col.interest_year' => ['Interessi dell’esercizio', 'Interest for the year', 'Zinsen des Jahres', 'Intérêts de l’exercice'],
            'statutoryFinancials.notes.col.depreciation_year' => ['Ammortamento dell’esercizio', 'Depreciation for the year', 'Abschreibung des Jahres', 'Amortissement de l’exercice'],
            'statutoryFinancials.notes.col.redemption_value' => ['Valore di riscatto', 'Redemption value', 'Rückkaufswert', 'Valeur de rachat'],
            'statutoryFinancials.notes.col.type' => ['Tipo', 'Type', 'Art', 'Type'],
            'statutoryFinancials.notes.col.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'statutoryFinancials.notes.col.amount' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'statutoryFinancials.notes.col.average_headcount' => ['Organico medio', 'Average headcount', 'Durchschnittlicher Bestand', 'Effectif moyen'],
            'statutoryFinancials.notes.col.average_fte' => ['FTE medi', 'Average FTE', 'Durchschnittliche Vollzeitstellen', 'ETP moyens'],
            'statutoryFinancials.notes.col.party' => ['Parte correlata', 'Related party', 'Nahestehende Person', 'Partie liée'],
            'statutoryFinancials.notes.col.relationship' => ['Rapporto', 'Relationship', 'Beziehung', 'Relation'],
            'statutoryFinancials.notes.col.kind' => ['Natura', 'Kind', 'Art', 'Nature'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function indicators(): array
    {
        return [
            'statutoryFinancials.empty.indicators' => ['Gli indici non sono disponibili: genera prima i prospetti.', 'The ratios are not available: generate the statements first.', 'Die Kennzahlen sind nicht verfügbar: Erstellen Sie zuerst die Abschlussrechnungen.', 'Les ratios ne sont pas disponibles : générez d’abord les états.'],
            'statutoryFinancials.indicators.ratio' => ['Indice', 'Ratio', 'Kennzahl', 'Ratio'],
            'statutoryFinancials.indicators.value' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'statutoryFinancials.indicators.message' => ['Messaggio', 'Message', 'Meldung', 'Message'],
            'statutoryFinancials.indicators.thresholds' => ['Soglie (attenzione / critica)', 'Thresholds (warning / critical)', 'Schwellen (Warnung / kritisch)', 'Seuils (avertissement / critique)'],
            'statutoryFinancials.indicators.crisis' => ['Indicatore di crisi', 'Crisis indicator', 'Krisenindikator', 'Indicateur de crise'],
            'statutoryFinancials.indicators.simulating' => ['Simulazione in corso…', 'Simulating…', 'Simulation läuft…', 'Simulation en cours…'],
            'statutoryFinancials.indicators.alertLevel.ok' => ['Nessun allarme di crisi', 'No crisis alert', 'Kein Krisenalarm', 'Aucune alerte de crise'],
            'statutoryFinancials.indicators.alertLevel.warning' => ['Attenzione: indicatori in zona di avviso', 'Warning: indicators in the warning zone', 'Warnung: Kennzahlen im Warnbereich', 'Avertissement : indicateurs en zone d’alerte'],
            'statutoryFinancials.indicators.alertLevel.critical' => ['Allarme critico: indicatori di crisi superati', 'Critical alert: crisis indicators breached', 'Kritischer Alarm: Krisenindikatoren überschritten', 'Alerte critique : indicateurs de crise dépassés'],
            'statutoryFinancials.indicators.status.ok' => ['Regolare', 'OK', 'In Ordnung', 'Normal'],
            'statutoryFinancials.indicators.status.warning' => ['Attenzione', 'Warning', 'Warnung', 'Avertissement'],
            'statutoryFinancials.indicators.status.critical' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'statutoryFinancials.indicators.status.n/a' => ['n.d.', 'n/a', 'k. A.', 'n.d.'],
            'statutoryFinancials.indicators.direction.min' => ['valore minimo atteso', 'minimum expected', 'Mindestwert erwartet', 'minimum attendu'],
            'statutoryFinancials.indicators.direction.max' => ['valore massimo ammesso', 'maximum allowed', 'Höchstwert zulässig', 'maximum admis'],
            'statutoryFinancials.whatIf.ebitda_change_percent' => ['Variazione dell’EBITDA (%)', 'EBITDA change (%)', 'Veränderung des EBITDA (%)', 'Variation de l’EBITDA (%)'],
            'statutoryFinancials.whatIf.revenue_change_percent' => ['Variazione dei ricavi (%)', 'Revenue change (%)', 'Veränderung der Umsatzerlöse (%)', 'Variation du chiffre d’affaires (%)'],
            'statutoryFinancials.whatIf.debt_service_change_percent' => ['Variazione del servizio del debito (%)', 'Debt service change (%)', 'Veränderung des Schuldendienstes (%)', 'Variation du service de la dette (%)'],
            'statutoryFinancials.whatIf.extra_liquidity' => ['Liquidità aggiuntiva', 'Extra liquidity', 'Zusätzliche Liquidität', 'Liquidités supplémentaires'],
            'statutoryFinancials.whatIf.expected_cash_flow_6m' => ['Flusso di cassa atteso a 6 mesi (valore assoluto)', 'Expected 6-month cash flow (absolute)', 'Erwarteter Geldfluss 6 Monate (absolut)', 'Flux de trésorerie attendu à 6 mois (absolu)'],
            'statutoryFinancials.whatIf.debt_service_6m' => ['Servizio del debito a 6 mesi (valore assoluto)', '6-month debt service (absolute)', 'Schuldendienst 6 Monate (absolut)', 'Service de la dette à 6 mois (absolu)'],
            'statutoryFinancials.whatIf.opening_liquidity' => ['Liquidità iniziale (valore assoluto)', 'Opening liquidity (absolute)', 'Anfangsliquidität (absolut)', 'Liquidités d’ouverture (absolu)'],
            'statutoryFinancials.dscr.base' => ['DSCR attuale', 'Current DSCR', 'Aktueller DSCR', 'DSCR actuel'],
            'statutoryFinancials.dscr.simulated' => ['DSCR simulato', 'Simulated DSCR', 'Simulierter DSCR', 'DSCR simulé'],
            'statutoryFinancials.dscr.breakdown' => ['Composizione del DSCR a {months} mesi', 'Breakdown of the {months}-month DSCR', 'Zusammensetzung des {months}-Monats-DSCR', 'Composition du DSCR à {months} mois'],
            'statutoryFinancials.dscr.expectedCashFlow' => ['Flusso di cassa atteso', 'Expected cash flow', 'Erwarteter Geldfluss', 'Flux de trésorerie attendu'],
            'statutoryFinancials.dscr.openingLiquidity' => ['Liquidità iniziale', 'Opening liquidity', 'Anfangsliquidität', 'Liquidités d’ouverture'],
            'statutoryFinancials.dscr.debtService' => ['Servizio del debito', 'Debt service', 'Schuldendienst', 'Service de la dette'],
            'statutoryFinancials.dscr.value' => ['DSCR', 'DSCR', 'DSCR', 'DSCR'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function package(): array
    {
        return [
            'statutoryFinancials.empty.validation' => ['Nessuna validazione eseguita. Premi “Valida ed esporta XBRL”.', 'No validation has been run. Press “Validate and export XBRL”.', 'Es wurde keine Validierung ausgeführt. Klicken Sie auf „XBRL validieren und exportieren“.', 'Aucune validation exécutée. Cliquez sur « Valider et exporter XBRL ».'],
            'statutoryFinancials.empty.artifacts' => ['Nessun documento PDF/A generato.', 'No PDF/A document generated.', 'Kein PDF/A-Dokument erstellt.', 'Aucun document PDF/A généré.'],
            'statutoryFinancials.artifact.file' => ['File', 'File', 'Datei', 'Fichier'],
            'statutoryFinancials.artifact.kind' => ['Documento', 'Document', 'Dokument', 'Document'],
            'statutoryFinancials.artifact.size' => ['Dimensione', 'Size', 'Grösse', 'Taille'],
            'statutoryFinancials.artifact.conformance' => ['Conformità', 'Conformance', 'Konformität', 'Conformité'],
            'statutoryFinancials.artifact.verification' => ['Verifica', 'Verification', 'Prüfung', 'Vérification'],
            'statutoryFinancials.artifact.compliant' => ['Conforme', 'Compliant', 'Konform', 'Conforme'],
            'statutoryFinancials.artifact.notCompliant' => ['Non conforme', 'Not compliant', 'Nicht konform', 'Non conforme'],
            'statutoryFinancials.artifact.notVerified' => ['Non verificato', 'Not verified', 'Nicht geprüft', 'Non vérifié'],
            'statutoryFinancials.artifact.checks' => ['Controlli PDF/A', 'PDF/A checks', 'PDF/A-Prüfungen', 'Contrôles PDF/A'],
            'statutoryFinancials.artifact.pass' => ['Superato', 'Passed', 'Bestanden', 'Réussi'],
            'statutoryFinancials.artifact.fail' => ['Non superato', 'Failed', 'Nicht bestanden', 'Échoué'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'statutoryFinancials.message.saved' => ['Salvato.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
            'statutoryFinancials.message.deleted' => ['Fascicolo eliminato.', 'Dossier deleted.', 'Dossier gelöscht.', 'Dossier supprimé.'],
            'statutoryFinancials.message.generated' => ['Prospetti generati.', 'Statements generated.', 'Abschlussrechnungen erstellt.', 'États générés.'],
            'statutoryFinancials.message.cashFlowGenerated' => ['Rendiconto finanziario generato.', 'Cash flow statement generated.', 'Geldflussrechnung erstellt.', 'Tableau de flux de trésorerie généré.'],
            'statutoryFinancials.message.populated' => ['Nota integrativa compilata.', 'Notes populated.', 'Anhang befüllt.', 'Annexe renseignée.'],
            'statutoryFinancials.message.indicatorsSaved' => ['Indici salvati nel pacchetto.', 'Ratios saved to the package.', 'Kennzahlen im Paket gespeichert.', 'Ratios enregistrés dans le dossier.'],
            'statutoryFinancials.message.exported' => ['Validazione ed esportazione XBRL completate.', 'XBRL validation and export completed.', 'XBRL-Validierung und -Export abgeschlossen.', 'Validation et export XBRL terminés.'],
            'statutoryFinancials.message.pdfaGenerated' => ['Documenti PDF/A generati.', 'PDF/A documents generated.', 'PDF/A-Dokumente erstellt.', 'Documents PDF/A générés.'],
            'statutoryFinancials.message.verified' => ['Verifica PDF/A completata.', 'PDF/A verification completed.', 'PDF/A-Prüfung abgeschlossen.', 'Vérification PDF/A terminée.'],
            'statutoryFinancials.message.transitioned' => ['Stato del fascicolo aggiornato.', 'Dossier status updated.', 'Status des Dossiers aktualisiert.', 'Statut du dossier mis à jour.'],
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
