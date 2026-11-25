<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the tender management module (Mod 169): the pipeline, the qualification vault, the Go/No-Go matrix, the
 * three envelopes, the economic offer simulator, the award conversion, the competitor archive, and the codes the API reports
 * (tenderManagement.error.<code>, tenderManagement.compliance.<code>, tenderManagement.alert.<type>).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Legal acronyms (SDI, PEC, AVS, LPP, SOA, DURC, CIG, CUP, RUP, eDGUE, FVOE, ANAC, CPV, OEPV, RTI, SUVA, CCL, Logib) stay untranslated.
 */
final class TenderManagementFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::navigation(),
            self::enumerations(),
            self::vaultTypes(),
            self::errors(),
            self::compliance(),
            self::alerts(),
            self::labels(),
            self::envelopeItems(),
            self::actions(),
            self::messages(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function navigation(): array
    {
        return [
            'NAV.TENDER_MANAGEMENT' => ['Gare d’appalto', 'Tenders', 'Ausschreibungen', 'Appels d’offres'],
            'NAV.TENDER_MANAGEMENT_PIPELINE' => ['Pipeline gare', 'Tender pipeline', 'Ausschreibungs-Pipeline', 'Pipeline des appels d’offres'],
            'NAV.TENDER_MANAGEMENT_DETAIL' => ['Dossier di gara', 'Tender dossier', 'Ausschreibungsdossier', 'Dossier d’appel d’offres'],
            'NAV.TENDER_MANAGEMENT_VAULT' => ['Cassaforte documentale', 'Qualification vault', 'Nachweis-Tresor', 'Coffre des qualifications'],
            'NAV.TENDER_MANAGEMENT_BENCHMARKS' => ['Storico concorrenti', 'Competitor history', 'Konkurrenzhistorie', 'Historique des concurrents'],
            'PAGE_TITLE.TENDER_MANAGEMENT_PIPELINE' => ['Gare d’appalto pubbliche e private', 'Public and private tenders', 'Öffentliche und private Ausschreibungen', 'Appels d’offres publics et privés'],
            'PAGE_TITLE.TENDER_MANAGEMENT_DETAIL' => ['Dossier di partecipazione', 'Participation dossier', 'Teilnahmedossier', 'Dossier de participation'],
            'PAGE_TITLE.TENDER_MANAGEMENT_VAULT' => ['Cassaforte documentale amministrativa', 'Administrative qualification vault', 'Administrativer Nachweis-Tresor', 'Coffre administratif des qualifications'],
            'PAGE_TITLE.TENDER_MANAGEMENT_BENCHMARKS' => ['Storico aggiudicazioni e concorrenti', 'Award and competitor history', 'Zuschlags- und Konkurrenzhistorie', 'Historique des adjudications et des concurrents'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'tenderManagement.enum.jurisdiction.CH_SIMAP_LAPub' => ['Svizzera (simap.ch, LAPub / CIAP)', 'Switzerland (simap.ch, BöB / IVöB)', 'Schweiz (simap.ch, BöB / IVöB)', 'Suisse (simap.ch, LMP / AIMP)'],
            'tenderManagement.enum.jurisdiction.IT_Dlgs36_2023_ANAC' => ['Italia (ANAC / MePA, D.Lgs. 36/2023)', 'Italy (ANAC / MePA, D.Lgs. 36/2023)', 'Italien (ANAC / MePA, D.Lgs. 36/2023)', 'Italie (ANAC / MePA, D.Lgs. 36/2023)'],
            'tenderManagement.enum.jurisdiction.EU_TED' => ['Unione europea (TED)', 'European Union (TED)', 'Europäische Union (TED)', 'Union européenne (TED)'],
            'tenderManagement.enum.jurisdiction.PrivateRfp' => ['Richiesta di offerta privata', 'Private request for proposal', 'Private Offertanfrage', 'Demande d’offre privée'],
            'tenderManagement.enum.procedureType.open' => ['Procedura aperta', 'Open procedure', 'Offenes Verfahren', 'Procédure ouverte'],
            'tenderManagement.enum.procedureType.selective' => ['Procedura selettiva', 'Selective procedure', 'Selektives Verfahren', 'Procédure sélective'],
            'tenderManagement.enum.procedureType.invitation' => ['Procedura su invito', 'Invitation procedure', 'Einladungsverfahren', 'Procédure sur invitation'],
            'tenderManagement.enum.procedureType.direct_award' => ['Affidamento diretto', 'Direct award', 'Freihändiges Verfahren', 'Adjudication de gré à gré'],
            'tenderManagement.enum.awardCriterion.OEPV_MostEconomicallyAdvantageous' => ['OEPV (offerta economicamente più vantaggiosa)', 'OEPV (most economically advantageous tender)', 'OEPV (wirtschaftlich günstigstes Angebot)', 'OEPV (offre économiquement la plus avantageuse)'],
            'tenderManagement.enum.awardCriterion.LowestPrice' => ['Minor prezzo', 'Lowest price', 'Niedrigster Preis', 'Prix le plus bas'],
            'tenderManagement.enum.formulaType.Linear' => ['Lineare', 'Linear', 'Linear', 'Linéaire'],
            'tenderManagement.enum.formulaType.Bilinear' => ['Bilineare', 'Bilinear', 'Bilinear', 'Bilinéaire'],
            'tenderManagement.enum.formulaType.Interpolation' => ['Interpolazione (sul prezzo)', 'Interpolation (on price)', 'Interpolation (auf den Preis)', 'Interpolation (sur le prix)'],
            'tenderManagement.enum.noticeStatus.open' => ['Aperto', 'Open', 'Offen', 'Ouvert'],
            'tenderManagement.enum.noticeStatus.closed' => ['Chiuso', 'Closed', 'Geschlossen', 'Clos'],
            'tenderManagement.enum.noticeStatus.awarded' => ['Aggiudicato', 'Awarded', 'Zugeschlagen', 'Adjugé'],
            'tenderManagement.enum.noticeStatus.cancelled' => ['Annullato', 'Cancelled', 'Abgebrochen', 'Annulé'],
            'tenderManagement.enum.participationMode.SingleCompany' => ['Impresa singola', 'Single company', 'Einzelunternehmen', 'Entreprise seule'],
            'tenderManagement.enum.participationMode.RTI_Consorzio_Mandataria_Mandante' => ['RTI / Consorzio (mandataria e mandanti)', 'RTI / Consortium (lead and members)', 'RTI / Konsortium (Federführer und Mitglieder)', 'RTI / Consortium (mandataire et membres)'],
            'tenderManagement.enum.participationMode.Avvalimento' => ['Avvalimento', 'Avvalimento (reliance on a third party)', 'Avvalimento (Eignungsleihe)', 'Avvalimento (recours aux capacités d’un tiers)'],
            'tenderManagement.enum.participationStatus.Scouting' => ['Scouting', 'Scouting', 'Sondierung', 'Prospection'],
            'tenderManagement.enum.participationStatus.GoApproved' => ['Go approvato', 'Go approved', 'Go genehmigt', 'Go approuvé'],
            'tenderManagement.enum.participationStatus.PreparingEnvelopes' => ['Preparazione buste', 'Preparing envelopes', 'Umschläge in Vorbereitung', 'Préparation des enveloppes'],
            'tenderManagement.enum.participationStatus.Submitted' => ['Presentata', 'Submitted', 'Eingereicht', 'Déposée'],
            'tenderManagement.enum.participationStatus.AwardedWon' => ['Aggiudicata', 'Awarded (won)', 'Zuschlag erhalten', 'Adjugée (gagnée)'],
            'tenderManagement.enum.participationStatus.Lost' => ['Persa', 'Lost', 'Verloren', 'Perdue'],
            'tenderManagement.enum.participationStatus.LegalAppeal_Ricorso' => ['Ricorso', 'Legal appeal', 'Rechtsmittel', 'Recours'],
            'tenderManagement.enum.verificationStatus.Valid' => ['Valido', 'Valid', 'Gültig', 'Valide'],
            'tenderManagement.enum.verificationStatus.ExpiringSoon' => ['In scadenza', 'Expiring soon', 'Läuft bald ab', 'Bientôt échu'],
            'tenderManagement.enum.verificationStatus.Expired' => ['Scaduto', 'Expired', 'Abgelaufen', 'Échu'],
            'tenderManagement.enum.verificationStatus.PendingRenewal' => ['Rinnovo in corso', 'Pending renewal', 'Erneuerung ausstehend', 'Renouvellement en cours'],
            'tenderManagement.enum.envelope.administrative' => ['Busta amministrativa', 'Administrative envelope', 'Administrativer Umschlag', 'Enveloppe administrative'],
            'tenderManagement.enum.envelope.technical' => ['Busta tecnica', 'Technical envelope', 'Technischer Umschlag', 'Enveloppe technique'],
            'tenderManagement.enum.envelope.economic' => ['Busta economica', 'Economic envelope', 'Wirtschaftlicher Umschlag', 'Enveloppe économique'],
            'tenderManagement.enum.alertSeverity.info' => ['Informazione', 'Information', 'Information', 'Information'],
            'tenderManagement.enum.alertSeverity.warning' => ['Attenzione', 'Warning', 'Warnung', 'Avertissement'],
            'tenderManagement.enum.alertSeverity.critical' => ['Critico', 'Critical', 'Kritisch', 'Critique'],
            'tenderManagement.enum.alertType.submission_deadline' => ['Scadenza presentazione offerte', 'Submission deadline', 'Eingabefrist', 'Délai de dépôt'],
            'tenderManagement.enum.alertType.clarification_deadline' => ['Scadenza chiarimenti', 'Clarification deadline', 'Frist für Fragen', 'Délai pour les questions'],
            'tenderManagement.enum.alertType.vault_expiry' => ['Scadenza documento', 'Document expiry', 'Ablauf des Dokuments', 'Échéance du document'],
            'tenderManagement.enum.alertType.soa_milestone' => ['Verifica SOA', 'SOA check', 'SOA-Prüfung', 'Contrôle SOA'],
            'tenderManagement.enum.alertType.betreibungsauszug_age' => ['Età del Betreibungsauszug', 'Age of the Betreibungsauszug', 'Alter des Betreibungsauszugs', 'Âge de l’extrait du registre des poursuites'],
            'tenderManagement.enum.decision.go' => ['Go', 'Go', 'Go', 'Go'],
            'tenderManagement.enum.decision.conditional' => ['Go condizionato', 'Conditional go', 'Bedingtes Go', 'Go conditionnel'],
            'tenderManagement.enum.decision.no_go' => ['No-Go', 'No-Go', 'No-Go', 'No-Go'],
            'tenderManagement.enum.signatureStatus.valid' => ['Firma valida', 'Valid signature', 'Gültige Signatur', 'Signature valide'],
            'tenderManagement.enum.signatureStatus.invalid' => ['Firma non valida', 'Invalid signature', 'Ungültige Signatur', 'Signature invalide'],
            'tenderManagement.enum.signatureStatus.unsigned' => ['Non firmato', 'Unsigned', 'Nicht signiert', 'Non signé'],
            'tenderManagement.enum.signatureStatus.not_required' => ['Firma non richiesta', 'Signature not required', 'Signatur nicht erforderlich', 'Signature non requise'],
            'tenderManagement.enum.signatureFormat.p7m' => ['CAdES (.p7m)', 'CAdES (.p7m)', 'CAdES (.p7m)', 'CAdES (.p7m)'],
            'tenderManagement.enum.signatureFormat.pades' => ['PAdES', 'PAdES', 'PAdES', 'PAdES'],
            'tenderManagement.enum.signatureFormat.none' => ['Nessuna firma', 'No signature', 'Keine Signatur', 'Aucune signature'],
            'tenderManagement.enum.signatureFormat.unknown' => ['Formato sconosciuto', 'Unknown format', 'Unbekanntes Format', 'Format inconnu'],
            'tenderManagement.enum.itemStatus.missing' => ['Mancante', 'Missing', 'Fehlt', 'Manquant'],
            'tenderManagement.enum.itemStatus.uploaded' => ['Caricato', 'Uploaded', 'Hochgeladen', 'Téléversé'],
            'tenderManagement.enum.itemStatus.signature_invalid' => ['Firma non valida', 'Signature invalid', 'Signatur ungültig', 'Signature invalide'],
            'tenderManagement.enum.itemStatus.ok' => ['Conforme', 'Compliant', 'Konform', 'Conforme'],
            'tenderManagement.enum.partnerRole.mandataria' => ['Mandataria', 'Lead company', 'Federführer', 'Mandataire'],
            'tenderManagement.enum.partnerRole.mandante' => ['Mandante', 'Member', 'Mitglied', 'Membre'],
            'tenderManagement.enum.partnerRole.ausiliaria' => ['Ausiliaria (avvalimento)', 'Auxiliary company (avvalimento)', 'Hilfsunternehmen (Avvalimento)', 'Entreprise auxiliaire (avvalimento)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function vaultTypes(): array
    {
        return [
            'tenderManagement.enum.vaultDocType.IT_SOA_Attestation' => ['Attestazione SOA', 'SOA attestation', 'SOA-Bescheinigung', 'Attestation SOA'],
            'tenderManagement.enum.vaultDocType.IT_DURC' => ['DURC', 'DURC', 'DURC', 'DURC'],
            'tenderManagement.enum.vaultDocType.IT_VisuraCamerale' => ['Visura camerale', 'Chamber of commerce extract (visura)', 'Handelsregisterauszug (Visura)', 'Extrait du registre du commerce (visura)'],
            'tenderManagement.enum.vaultDocType.IT_CasellarioGiudiziale_CarichiPendenti' => ['Casellario giudiziale e carichi pendenti', 'Criminal record and pending charges', 'Strafregister und hängige Verfahren', 'Casier judiciaire et charges pendantes'],
            'tenderManagement.enum.vaultDocType.IT_ISO9001' => ['ISO 9001', 'ISO 9001', 'ISO 9001', 'ISO 9001'],
            'tenderManagement.enum.vaultDocType.IT_ISO14001' => ['ISO 14001', 'ISO 14001', 'ISO 14001', 'ISO 14001'],
            'tenderManagement.enum.vaultDocType.IT_ISO45001' => ['ISO 45001', 'ISO 45001', 'ISO 45001', 'ISO 45001'],
            'tenderManagement.enum.vaultDocType.IT_UNI_PdR_125_ParitaGenere' => ['UNI/PdR 125 (parità di genere)', 'UNI/PdR 125 (gender equality)', 'UNI/PdR 125 (Gleichstellung der Geschlechter)', 'UNI/PdR 125 (égalité des genres)'],
            'tenderManagement.enum.vaultDocType.IT_EMAS' => ['EMAS', 'EMAS', 'EMAS', 'EMAS'],
            'tenderManagement.enum.vaultDocType.IT_PMI_Status' => ['Status di PMI', 'SME status (PMI)', 'KMU-Status (PMI)', 'Statut de PME (PMI)'],
            'tenderManagement.enum.vaultDocType.CH_EstrattoEsecuzioniFallimenti_Betreibungsauszug' => ['Estratto del registro delle esecuzioni e dei fallimenti (Betreibungsauszug)', 'Debt enforcement and bankruptcy extract (Betreibungsauszug)', 'Betreibungsauszug', 'Extrait du registre des poursuites et faillites'],
            'tenderManagement.enum.vaultDocType.CH_AttestatoAVS_AI_IPG' => ['Attestato AVS / AI / IPG', 'AVS / AI / IPG certificate', 'AHV/IV/EO-Bescheinigung', 'Attestation AVS / AI / APG'],
            'tenderManagement.enum.vaultDocType.CH_AttestatoSUVA' => ['Attestato SUVA', 'SUVA certificate', 'SUVA-Bescheinigung', 'Attestation SUVA'],
            'tenderManagement.enum.vaultDocType.CH_AttestatoLPP' => ['Attestato LPP', 'LPP certificate', 'BVG-Bescheinigung (LPP)', 'Attestation LPP'],
            'tenderManagement.enum.vaultDocType.CH_AttestatoAFC_IVA' => ['Attestato AFC (IVA)', 'AFC certificate (VAT)', 'ESTV-Bescheinigung (MWST)', 'Attestation AFC (TVA)'],
            'tenderManagement.enum.vaultDocType.CH_RispettoCCL' => ['Rispetto del CCL', 'Compliance with the CCL', 'Einhaltung des GAV (CCL)', 'Respect de la CCT (CCL)'],
            'tenderManagement.enum.vaultDocType.CH_ParitaSalarialeLogib' => ['Parità salariale (Logib)', 'Equal pay (Logib)', 'Lohngleichheit (Logib)', 'Égalité salariale (Logib)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function errors(): array
    {
        return [
            'tenderManagement.error.generic' => ['Operazione non riuscita. Riprova.', 'The operation failed. Please try again.', 'Der Vorgang ist fehlgeschlagen. Bitte erneut versuchen.', 'L’opération a échoué. Veuillez réessayer.'],
            'tenderManagement.error.invalid_transition' => ['Questo cambio di stato non è consentito.', 'This status change is not allowed.', 'Dieser Statuswechsel ist nicht erlaubt.', 'Ce changement de statut n’est pas autorisé.'],
            'tenderManagement.error.participation_exists' => ['Esiste già un dossier di partecipazione per questa gara.', 'A participation dossier already exists for this tender.', 'Für diese Ausschreibung besteht bereits ein Teilnahmedossier.', 'Un dossier de participation existe déjà pour cet appel d’offres.'],
            'tenderManagement.error.notice_closed' => ['La gara è chiusa o annullata.', 'The tender is closed or cancelled.', 'Die Ausschreibung ist geschlossen oder abgebrochen.', 'L’appel d’offres est clos ou annulé.'],
            'tenderManagement.error.deadline_passed' => ['Il termine perentorio di presentazione è scaduto.', 'The peremptory submission deadline has passed.', 'Die zwingende Eingabefrist ist abgelaufen.', 'Le délai de dépôt péremptoire est échu.'],
            'tenderManagement.error.envelopes_incomplete' => ['Le buste non sono complete: carica i documenti richiesti.', 'The envelopes are incomplete: upload the required documents.', 'Die Umschläge sind unvollständig: Laden Sie die erforderlichen Dokumente hoch.', 'Les enveloppes sont incomplètes : téléversez les documents requis.'],
            'tenderManagement.error.readiness_blocked' => ['La presentazione è bloccata da rilievi di conformità.', 'The submission is blocked by compliance findings.', 'Die Einreichung wird durch Konformitätsbefunde blockiert.', 'Le dépôt est bloqué par des constats de conformité.'],
            'tenderManagement.error.safety_costs_required' => ['Indica gli oneri di sicurezza aziendali (art. 108 co. 9).', 'Enter the company safety costs (Art. 108 para. 9).', 'Geben Sie die betrieblichen Sicherheitskosten an (Art. 108 Abs. 9).', 'Indiquez les coûts de sécurité de l’entreprise (art. 108 al. 9).'],
            'tenderManagement.error.labor_costs_required' => ['Indica i costi della manodopera (art. 108 co. 9).', 'Enter the labor costs (Art. 108 para. 9).', 'Geben Sie die Lohnkosten an (Art. 108 Abs. 9).', 'Indiquez les coûts de la main-d’œuvre (art. 108 al. 9).'],
            'tenderManagement.error.bid_bond_percentage_invalid' => ['La percentuale della garanzia provvisoria deve essere 1% o 2%.', 'The provisional bid bond percentage must be 1% or 2%.', 'Der Prozentsatz der provisorischen Garantie muss 1 % oder 2 % betragen.', 'Le pourcentage de la garantie provisoire doit être de 1 % ou 2 %.'],
            'tenderManagement.error.discount_out_of_range' => ['Il ribasso deve essere compreso tra 0 e 100.', 'The discount must be between 0 and 100.', 'Der Rabatt muss zwischen 0 und 100 liegen.', 'Le rabais doit être compris entre 0 et 100.'],
            'tenderManagement.error.formula_required' => ['Indica la formula di calcolo del punteggio economico.', 'Select the formula for the economic score.', 'Wählen Sie die Formel für die wirtschaftliche Bewertung.', 'Indiquez la formule de calcul du score économique.'],
            'tenderManagement.error.not_awarded' => ['La gara non risulta aggiudicata.', 'The tender has not been awarded.', 'Die Ausschreibung wurde nicht zugeschlagen.', 'L’appel d’offres n’a pas été adjugé.'],
            'tenderManagement.error.already_converted' => ['L’aggiudicazione è già stata convertita in contratto e commessa.', 'The award has already been converted into a contract and project.', 'Der Zuschlag wurde bereits in Vertrag und Projekt umgewandelt.', 'L’adjudication a déjà été convertie en contrat et en projet.'],
            'tenderManagement.error.contracting_authority_contact_required' => ['Seleziona il cliente (stazione appaltante) da fatturare.', 'Select the customer (contracting authority) to invoice.', 'Wählen Sie den Kunden (Vergabestelle) für die Rechnungsstellung.', 'Sélectionnez le client (pouvoir adjudicateur) à facturer.'],
            'tenderManagement.error.project_manager_required' => ['Indica il responsabile della commessa.', 'Select the project manager.', 'Wählen Sie die Projektleitung.', 'Indiquez le responsable du projet.'],
            'tenderManagement.error.invalid_signature_format' => ['Formato di firma non supportato: usa .p7m o PDF firmato (PAdES).', 'Unsupported signature format: use .p7m or a signed PDF (PAdES).', 'Nicht unterstütztes Signaturformat: Verwenden Sie .p7m oder ein signiertes PDF (PAdES).', 'Format de signature non pris en charge : utilisez .p7m ou un PDF signé (PAdES).'],
            'tenderManagement.error.signature_invalid' => ['La firma digitale non è valida.', 'The digital signature is not valid.', 'Die digitale Signatur ist ungültig.', 'La signature numérique n’est pas valide.'],
            'tenderManagement.error.vault_file_required' => ['Allega il file del documento.', 'Attach the document file.', 'Fügen Sie die Dokumentdatei bei.', 'Joignez le fichier du document.'],
            'tenderManagement.error.document_type_jurisdiction_mismatch' => ['Il tipo di documento non corrisponde alla giurisdizione.', 'The document type does not match the jurisdiction.', 'Der Dokumenttyp passt nicht zur Rechtsordnung.', 'Le type de document ne correspond pas à la juridiction.'],
            'tenderManagement.error.unknown_envelope' => ['Busta sconosciuta.', 'Unknown envelope.', 'Unbekannter Umschlag.', 'Enveloppe inconnue.'],
            'tenderManagement.error.unknown_envelope_document' => ['Documento della busta sconosciuto.', 'Unknown envelope document.', 'Unbekanntes Umschlagdokument.', 'Document d’enveloppe inconnu.'],
            'tenderManagement.error.edgue_unsupported_jurisdiction' => ['L’eDGUE è disponibile solo per le gare italiane.', 'The eDGUE is only available for Italian tenders.', 'Das eDGUE ist nur für italienische Ausschreibungen verfügbar.', 'L’eDGUE n’est disponible que pour les appels d’offres italiens.'],
            'tenderManagement.error.simap_payload_invalid' => ['Il contenuto simap.ch non è valido.', 'The simap.ch payload is not valid.', 'Der simap.ch-Inhalt ist ungültig.', 'Le contenu simap.ch n’est pas valide.'],
            'tenderManagement.error.file_too_large' => ['Il file supera la dimensione massima consentita.', 'The file exceeds the maximum allowed size.', 'Die Datei überschreitet die maximal zulässige Grösse.', 'Le fichier dépasse la taille maximale autorisée.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function compliance(): array
    {
        return [
            'tenderManagement.compliance.art108_safety_costs_missing' => ['Oneri di sicurezza aziendali mancanti (art. 108 co. 9 D.Lgs. 36/2023): l’offerta non può essere presentata.', 'Company safety costs missing (Art. 108 para. 9 D.Lgs. 36/2023): the offer cannot be submitted.', 'Betriebliche Sicherheitskosten fehlen (Art. 108 Abs. 9 D.Lgs. 36/2023): Das Angebot kann nicht eingereicht werden.', 'Coûts de sécurité de l’entreprise manquants (art. 108 al. 9 D.Lgs. 36/2023) : l’offre ne peut pas être déposée.'],
            'tenderManagement.compliance.art108_labor_costs_missing' => ['Costi della manodopera mancanti (art. 108 co. 9 D.Lgs. 36/2023): l’offerta non può essere presentata.', 'Labor costs missing (Art. 108 para. 9 D.Lgs. 36/2023): the offer cannot be submitted.', 'Lohnkosten fehlen (Art. 108 Abs. 9 D.Lgs. 36/2023): Das Angebot kann nicht eingereicht werden.', 'Coûts de la main-d’œuvre manquants (art. 108 al. 9 D.Lgs. 36/2023) : l’offre ne peut pas être déposée.'],
            'tenderManagement.compliance.art108_labor_costs_below_estimate' => ['I costi della manodopera dichiarati sono inferiori alla stima della stazione appaltante: motiva lo scostamento.', 'The declared labor costs are below the contracting authority’s estimate: justify the difference.', 'Die deklarierten Lohnkosten liegen unter der Schätzung der Vergabestelle: Begründen Sie die Abweichung.', 'Les coûts de main-d’œuvre déclarés sont inférieurs à l’estimation du pouvoir adjudicateur : justifiez l’écart.'],
            'tenderManagement.compliance.cig_missing' => ['CIG mancante.', 'CIG missing.', 'CIG fehlt.', 'CIG manquant.'],
            'tenderManagement.compliance.cup_missing' => ['CUP mancante.', 'CUP missing.', 'CUP fehlt.', 'CUP manquant.'],
            'tenderManagement.compliance.rup_missing' => ['RUP mancante.', 'RUP missing.', 'RUP fehlt.', 'RUP manquant.'],
            'tenderManagement.compliance.dedicated_account_missing' => ['Conto corrente dedicato mancante (L. 136/2010).', 'Dedicated bank account missing (L. 136/2010).', 'Dediziertes Bankkonto fehlt (L. 136/2010).', 'Compte bancaire dédié manquant (L. 136/2010).'],
            'tenderManagement.compliance.soa_missing' => ['Attestazione SOA mancante.', 'SOA attestation missing.', 'SOA-Bescheinigung fehlt.', 'Attestation SOA manquante.'],
            'tenderManagement.compliance.soa_expired' => ['L’attestazione SOA è scaduta.', 'The SOA attestation has expired.', 'Die SOA-Bescheinigung ist abgelaufen.', 'L’attestation SOA est échue.'],
            'tenderManagement.compliance.soa_category_missing' => ['Categoria SOA {category} non posseduta.', 'SOA category {category} not held.', 'SOA-Kategorie {category} nicht vorhanden.', 'Catégorie SOA {category} non détenue.'],
            'tenderManagement.compliance.soa_classification_insufficient' => ['Classifica SOA insufficiente per {category}: richiesta {required}, posseduta {available}.', 'SOA classification insufficient for {category}: required {required}, held {available}.', 'SOA-Klassifikation für {category} ungenügend: verlangt {required}, vorhanden {available}.', 'Classification SOA insuffisante pour {category} : requise {required}, détenue {available}.'],
            'tenderManagement.compliance.soa_intermediate_check_due' => ['La verifica triennale dell’attestazione SOA è in scadenza.', 'The three-year check of the SOA attestation is due.', 'Die dreijährige Überprüfung der SOA-Bescheinigung ist fällig.', 'Le contrôle triennal de l’attestation SOA est dû.'],
            'tenderManagement.compliance.fvoe_not_synced' => ['I documenti non sono sincronizzati con FVOE 2.0 di ANAC.', 'The documents are not synchronised with ANAC FVOE 2.0.', 'Die Dokumente sind nicht mit dem ANAC-FVOE 2.0 synchronisiert.', 'Les documents ne sont pas synchronisés avec le FVOE 2.0 de l’ANAC.'],
            'tenderManagement.compliance.durc_missing' => ['DURC mancante.', 'DURC missing.', 'DURC fehlt.', 'DURC manquant.'],
            'tenderManagement.compliance.durc_expired' => ['Il DURC è scaduto.', 'The DURC has expired.', 'Das DURC ist abgelaufen.', 'Le DURC est échu.'],
            'tenderManagement.compliance.edgue_missing' => ['eDGUE non ancora generato.', 'The eDGUE has not been generated yet.', 'Das eDGUE wurde noch nicht erstellt.', 'L’eDGUE n’a pas encore été généré.'],
            'tenderManagement.compliance.bid_bond_missing' => ['Garanzia provvisoria non collegata.', 'Provisional bid bond not linked.', 'Provisorische Garantie nicht verknüpft.', 'Garantie provisoire non liée.'],
            'tenderManagement.compliance.bid_bond_percentage_invalid' => ['La percentuale della garanzia provvisoria deve essere 1% o 2%.', 'The provisional bid bond percentage must be 1% or 2%.', 'Der Prozentsatz der provisorischen Garantie muss 1 % oder 2 % betragen.', 'Le pourcentage de la garantie provisoire doit être de 1 % ou 2 %.'],
            'tenderManagement.compliance.anac_contribution_receipt_missing' => ['Ricevuta del contributo ANAC mancante.', 'ANAC contribution receipt missing.', 'Quittung des ANAC-Beitrags fehlt.', 'Reçu de la contribution ANAC manquant.'],
            'tenderManagement.compliance.document_missing' => ['Documento mancante: {type}.', 'Document missing: {type}.', 'Dokument fehlt: {type}.', 'Document manquant : {type}.'],
            'tenderManagement.compliance.document_expired' => ['Documento scaduto: {type}.', 'Document expired: {type}.', 'Dokument abgelaufen: {type}.', 'Document échu : {type}.'],
            'tenderManagement.compliance.document_expiring' => ['Documento in scadenza tra {days} giorni: {type}.', 'Document expiring in {days} days: {type}.', 'Dokument läuft in {days} Tagen ab: {type}.', 'Document échéant dans {days} jours : {type}.'],
            'tenderManagement.compliance.betreibungsauszug_missing' => ['Betreibungsauszug mancante.', 'Betreibungsauszug missing.', 'Betreibungsauszug fehlt.', 'Extrait du registre des poursuites manquant.'],
            'tenderManagement.compliance.betreibungsauszug_too_old' => ['Il Betreibungsauszug ha più di {months} mesi.', 'The Betreibungsauszug is older than {months} months.', 'Der Betreibungsauszug ist älter als {months} Monate.', 'L’extrait du registre des poursuites date de plus de {months} mois.'],
            'tenderManagement.compliance.logib_missing' => ['Analisi Logib sulla parità salariale mancante.', 'Logib equal pay analysis missing.', 'Logib-Lohngleichheitsanalyse fehlt.', 'Analyse Logib sur l’égalité salariale manquante.'],
            'tenderManagement.compliance.logib_deviation_exceeded' => ['Scostamento Logib {value}% superiore al limite del {limit}%.', 'Logib deviation of {value}% exceeds the {limit}% limit.', 'Logib-Abweichung von {value} % überschreitet die Grenze von {limit} %.', 'L’écart Logib de {value} % dépasse la limite de {limit} %.'],
            'tenderManagement.compliance.simap_number_missing' => ['Numero di progetto simap.ch mancante.', 'simap.ch project number missing.', 'simap.ch-Projektnummer fehlt.', 'Numéro de projet simap.ch manquant.'],
            'tenderManagement.compliance.clarification_deadline_passed' => ['Il termine per i chiarimenti è scaduto.', 'The clarification deadline has passed.', 'Die Frist für Fragen ist abgelaufen.', 'Le délai pour les questions est échu.'],
            'tenderManagement.compliance.submission_deadline_passed' => ['Il termine perentorio di presentazione è scaduto.', 'The peremptory submission deadline has passed.', 'Die zwingende Eingabefrist ist abgelaufen.', 'Le délai de dépôt péremptoire est échu.'],
            'tenderManagement.compliance.turnover_gap' => ['Fatturato specifico insufficiente: mancano {gap}.', 'Specific turnover insufficient: {gap} short.', 'Spezifischer Umsatz ungenügend: es fehlen {gap}.', 'Chiffre d’affaires spécifique insuffisant : il manque {gap}.'],
            'tenderManagement.compliance.similar_projects_gap' => ['Servizi analoghi insufficienti: richiesti {required}, disponibili {available}.', 'Similar projects insufficient: {required} required, {available} available.', 'Vergleichbare Referenzprojekte ungenügend: {required} verlangt, {available} vorhanden.', 'Projets similaires insuffisants : {required} requis, {available} disponibles.'],
            'tenderManagement.compliance.anomaly_threshold_exceeded' => ['L’offerta supera la soglia di anomalia e potrebbe essere sottoposta a verifica.', 'The offer exceeds the anomaly threshold and may be subject to verification.', 'Das Angebot überschreitet die Anomalieschwelle und könnte geprüft werden.', 'L’offre dépasse le seuil d’anomalie et pourrait faire l’objet d’une vérification.'],
            'tenderManagement.compliance.offer_below_cost' => ['L’offerta è inferiore ai costi stimati.', 'The offer is below the estimated costs.', 'Das Angebot liegt unter den geschätzten Kosten.', 'L’offre est inférieure aux coûts estimés.'],
            'tenderManagement.compliance.margin_negative' => ['Il margine netto è negativo.', 'The net margin is negative.', 'Die Nettomarge ist negativ.', 'La marge nette est négative.'],
            'tenderManagement.compliance.rti_recommended' => ['Partecipa in RTI o consorzio: quota consigliata della mandante {quota}%.', 'Bid as an RTI or consortium: suggested partner share {quota}%.', 'Als RTI oder Konsortium anbieten: empfohlener Partneranteil {quota} %.', 'Soumissionnez en RTI ou consortium : part recommandée du partenaire {quota} %.'],
            'tenderManagement.compliance.avvalimento_recommended' => ['Per colmare i requisiti mancanti è consigliato l’avvalimento di un’impresa ausiliaria.', 'To cover the missing requirements, rely on an auxiliary company (avvalimento).', 'Zur Deckung der fehlenden Anforderungen wird der Beizug eines Hilfsunternehmens (Avvalimento) empfohlen.', 'Pour couvrir les exigences manquantes, il est recommandé de recourir à une entreprise auxiliaire (avvalimento).'],
            'tenderManagement.compliance.solo_eligible' => ['L’impresa può partecipare da sola.', 'The company can bid alone.', 'Das Unternehmen kann allein anbieten.', 'L’entreprise peut soumissionner seule.'],
            'tenderManagement.compliance.signature_invalid' => ['Firma digitale non valida: {type}.', 'Digital signature invalid: {type}.', 'Digitale Signatur ungültig: {type}.', 'Signature numérique invalide : {type}.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function alerts(): array
    {
        return [
            'tenderManagement.alert.submission_deadline' => ['Scadenza presentazione per «{title}» tra {hours} ore ({due_at}).', 'Submission deadline for “{title}” in {hours} hours ({due_at}).', 'Eingabefrist für «{title}» in {hours} Stunden ({due_at}).', 'Délai de dépôt pour « {title} » dans {hours} heures ({due_at}).'],
            'tenderManagement.alert.clarification_deadline' => ['Termine chiarimenti per «{title}» tra {hours} ore ({due_at}).', 'Clarification deadline for “{title}” in {hours} hours ({due_at}).', 'Frist für Fragen zu «{title}» in {hours} Stunden ({due_at}).', 'Délai pour les questions sur « {title} » dans {hours} heures ({due_at}).'],
            'tenderManagement.alert.vault_expiry' => ['Il documento {type} scade tra {days} giorni ({due_at}).', 'The document {type} expires in {days} days ({due_at}).', 'Das Dokument {type} läuft in {days} Tagen ab ({due_at}).', 'Le document {type} échoit dans {days} jours ({due_at}).'],
            'tenderManagement.alert.soa_milestone' => ['Verifica dell’attestazione SOA tra {days} giorni ({due_at}).', 'SOA attestation check in {days} days ({due_at}).', 'SOA-Prüfung in {days} Tagen ({due_at}).', 'Contrôle de l’attestation SOA dans {days} jours ({due_at}).'],
            'tenderManagement.alert.betreibungsauszug_age' => ['Il Betreibungsauszug perde validità tra {days} giorni ({due_at}): richiedine uno nuovo.', 'The Betreibungsauszug loses validity in {days} days ({due_at}): request a new one.', 'Der Betreibungsauszug verliert in {days} Tagen seine Gültigkeit ({due_at}): Bestellen Sie einen neuen.', 'L’extrait du registre des poursuites perd sa validité dans {days} jours ({due_at}) : demandez-en un nouveau.'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function labels(): array
    {
        return [
            'tenderManagement.field.title' => ['Titolo', 'Title', 'Titel', 'Titre'],
            'tenderManagement.field.description' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'tenderManagement.field.jurisdiction' => ['Giurisdizione', 'Jurisdiction', 'Rechtsordnung', 'Juridiction'],
            'tenderManagement.field.procedureType' => ['Procedura', 'Procedure', 'Verfahren', 'Procédure'],
            'tenderManagement.field.contractingAuthority' => ['Stazione appaltante', 'Contracting authority', 'Vergabestelle', 'Pouvoir adjudicateur'],
            'tenderManagement.field.simapProjectNumber' => ['Numero progetto simap.ch', 'simap.ch project number', 'simap.ch-Projektnummer', 'Numéro de projet simap.ch'],
            'tenderManagement.field.dedicatedAccount' => ['Conto dedicato (L. 136/2010)', 'Dedicated account (L. 136/2010)', 'Dediziertes Konto (L. 136/2010)', 'Compte dédié (L. 136/2010)'],
            'tenderManagement.field.awardCriterion' => ['Criterio di aggiudicazione', 'Award criterion', 'Zuschlagskriterium', 'Critère d’adjudication'],
            'tenderManagement.field.formulaType' => ['Formula economica', 'Economic formula', 'Wirtschaftliche Formel', 'Formule économique'],
            'tenderManagement.field.technicalMaxScore' => ['Punteggio tecnico massimo', 'Maximum technical score', 'Maximale technische Punktzahl', 'Score technique maximal'],
            'tenderManagement.field.economicMaxScore' => ['Punteggio economico massimo', 'Maximum economic score', 'Maximale wirtschaftliche Punktzahl', 'Score économique maximal'],
            'tenderManagement.field.baseAuctionAmount' => ['Base d’asta', 'Base auction amount', 'Ausschreibungsbetrag', 'Montant de base'],
            'tenderManagement.field.currency' => ['Valuta', 'Currency', 'Währung', 'Devise'],
            'tenderManagement.field.nonSubjectSafetyCosts' => ['Oneri di sicurezza non soggetti a ribasso', 'Safety costs not subject to discount', 'Nicht rabattierbare Sicherheitskosten', 'Coûts de sécurité non soumis à rabais'],
            'tenderManagement.field.estimatedLaborCost' => ['Costi della manodopera stimati', 'Estimated labor costs', 'Geschätzte Lohnkosten', 'Coûts de main-d’œuvre estimés'],
            'tenderManagement.field.requiredMinTurnover' => ['Fatturato minimo richiesto', 'Minimum turnover required', 'Verlangter Mindestumsatz', 'Chiffre d’affaires minimal requis'],
            'tenderManagement.field.turnoverYears' => ['Anni di riferimento del fatturato', 'Turnover reference years', 'Referenzjahre des Umsatzes', 'Années de référence du chiffre d’affaires'],
            'tenderManagement.field.similarProjectsCount' => ['Servizi analoghi richiesti (numero)', 'Similar projects required (number)', 'Verlangte vergleichbare Projekte (Anzahl)', 'Projets similaires requis (nombre)'],
            'tenderManagement.field.similarProjectsAmount' => ['Importo dei servizi analoghi', 'Amount of similar projects', 'Betrag der vergleichbaren Projekte', 'Montant des projets similaires'],
            'tenderManagement.field.requiredSoa' => ['Categorie e classifiche SOA richieste', 'Required SOA categories and classifications', 'Verlangte SOA-Kategorien und -Klassifikationen', 'Catégories et classifications SOA requises'],
            'tenderManagement.field.requiredCertifications' => ['Certificazioni richieste', 'Required certifications', 'Verlangte Zertifizierungen', 'Certifications requises'],
            'tenderManagement.field.soaCategory' => ['Categoria SOA (OG/OS)', 'SOA category (OG/OS)', 'SOA-Kategorie (OG/OS)', 'Catégorie SOA (OG/OS)'],
            'tenderManagement.field.soaClassification' => ['Classifica', 'Classification', 'Klassifikation', 'Classification'],
            'tenderManagement.field.publicationDate' => ['Data di pubblicazione', 'Publication date', 'Publikationsdatum', 'Date de publication'],
            'tenderManagement.field.clarificationDeadline' => ['Termine per i chiarimenti', 'Clarification deadline', 'Frist für Fragen', 'Délai pour les questions'],
            'tenderManagement.field.submissionDeadline' => ['Termine perentorio di presentazione', 'Peremptory submission deadline', 'Zwingende Eingabefrist', 'Délai de dépôt péremptoire'],
            'tenderManagement.field.status' => ['Stato', 'Status', 'Status', 'Statut'],
            'tenderManagement.field.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'tenderManagement.field.participationMode' => ['Forma di partecipazione', 'Participation mode', 'Teilnahmeform', 'Forme de participation'],
            'tenderManagement.field.rtiQuota' => ['Quota dei partner (%)', 'Partners’ share (%)', 'Anteil der Partner (%)', 'Part des partenaires (%)'],
            'tenderManagement.field.partnerName' => ['Ragione sociale', 'Company name', 'Firma', 'Raison sociale'],
            'tenderManagement.field.vatNumber' => ['P/IVA', 'VAT number (P/IVA)', 'MWST-Nr. (P/IVA)', 'N° TVA (P/IVA)'],
            'tenderManagement.field.partnerRole' => ['Ruolo', 'Role', 'Rolle', 'Rôle'],
            'tenderManagement.field.partnerQuota' => ['Quota (%)', 'Share (%)', 'Anteil (%)', 'Part (%)'],
            'tenderManagement.field.partnerSoa' => ['Categorie SOA apportate', 'SOA categories contributed', 'Eingebrachte SOA-Kategorien', 'Catégories SOA apportées'],
            'tenderManagement.field.documentType' => ['Tipo di documento', 'Document type', 'Dokumenttyp', 'Type de document'],
            'tenderManagement.field.documentNumber' => ['Numero documento', 'Document number', 'Dokumentnummer', 'Numéro du document'],
            'tenderManagement.field.issuingBody' => ['Ente emittente', 'Issuing body', 'Ausstellende Stelle', 'Organisme émetteur'],
            'tenderManagement.field.issueDate' => ['Data di emissione', 'Issue date', 'Ausstellungsdatum', 'Date d’émission'],
            'tenderManagement.field.expiryDate' => ['Data di scadenza', 'Expiry date', 'Ablaufdatum', 'Date d’échéance'],
            'tenderManagement.field.triennialCheck' => ['Verifica triennale', 'Three-year check', 'Dreijahreskontrolle', 'Contrôle triennal'],
            'tenderManagement.field.logibDeviation' => ['Scostamento salariale Logib (%)', 'Logib pay deviation (%)', 'Logib-Lohnabweichung (%)', 'Écart salarial Logib (%)'],
            'tenderManagement.field.file' => ['File', 'File', 'Datei', 'Fichier'],
            'tenderManagement.field.notes' => ['Note', 'Notes', 'Notizen', 'Notes'],
            'tenderManagement.field.competitor' => ['Concorrente', 'Competitor', 'Konkurrent', 'Concurrent'],
            'tenderManagement.field.awardDate' => ['Data di aggiudicazione', 'Award date', 'Zuschlagsdatum', 'Date d’adjudication'],
            'tenderManagement.field.awardedAmount' => ['Importo aggiudicato', 'Awarded amount', 'Zuschlagsbetrag', 'Montant adjugé'],
            'tenderManagement.field.discount' => ['Ribasso (%)', 'Discount (%)', 'Rabatt (%)', 'Rabais (%)'],
            'tenderManagement.field.technicalScore' => ['Punteggio tecnico', 'Technical score', 'Technische Punktzahl', 'Score technique'],
            'tenderManagement.field.economicScore' => ['Punteggio economico', 'Economic score', 'Wirtschaftliche Punktzahl', 'Score économique'],
            'tenderManagement.field.totalScore' => ['Punteggio totale', 'Total score', 'Gesamtpunktzahl', 'Score total'],
            'tenderManagement.field.outcome' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'tenderManagement.field.source' => ['Fonte', 'Source', 'Quelle', 'Source'],
            'tenderManagement.tab.overview' => ['Requisiti', 'Requirements', 'Anforderungen', 'Exigences'],
            'tenderManagement.tab.goNoGo' => ['Go / No-Go', 'Go / No-Go', 'Go / No-Go', 'Go / No-Go'],
            'tenderManagement.tab.envelopes' => ['Buste', 'Envelopes', 'Umschläge', 'Enveloppes'],
            'tenderManagement.tab.offer' => ['Offerta economica', 'Economic offer', 'Wirtschaftliches Angebot', 'Offre économique'],
            'tenderManagement.view.kanban' => ['Kanban', 'Kanban', 'Kanban', 'Kanban'],
            'tenderManagement.view.list' => ['Elenco', 'List', 'Liste', 'Liste'],
            'tenderManagement.filter.deadline' => ['Scadenza', 'Deadline', 'Frist', 'Échéance'],
            'tenderManagement.filter.within24h' => ['Entro 24 ore', 'Within 24 hours', 'Innerhalb von 24 Stunden', 'Dans les 24 heures'],
            'tenderManagement.filter.within72h' => ['Entro 72 ore', 'Within 72 hours', 'Innerhalb von 72 Stunden', 'Dans les 72 heures'],
            'tenderManagement.filter.within7d' => ['Entro 7 giorni', 'Within 7 days', 'Innerhalb von 7 Tagen', 'Dans les 7 jours'],
            'tenderManagement.filter.winnersOnly' => ['Solo aggiudicatari', 'Winners only', 'Nur Zuschlagsempfänger', 'Adjudicataires uniquement'],
            'tenderManagement.kpi.title' => ['Indicatori della pipeline', 'Pipeline indicators', 'Pipeline-Kennzahlen', 'Indicateurs du pipeline'],
            'tenderManagement.kpi.open' => ['Gare in corso', 'Tenders in progress', 'Laufende Ausschreibungen', 'Appels d’offres en cours'],
            'tenderManagement.kpi.submittedValue' => ['Valore presentato', 'Value submitted', 'Eingereichter Wert', 'Valeur déposée'],
            'tenderManagement.kpi.wonValue' => ['Valore aggiudicato', 'Value won', 'Gewonnener Wert', 'Valeur gagnée'],
            'tenderManagement.kpi.vaultExpiring' => ['Documenti in scadenza o scaduti', 'Documents expiring or expired', 'Ablaufende oder abgelaufene Dokumente', 'Documents échéant ou échus'],
            'tenderManagement.kpi.openAlerts' => ['Avvisi aperti', 'Open alerts', 'Offene Hinweise', 'Alertes ouvertes'],
            'tenderManagement.alerts.title' => ['Avvisi di scadenza', 'Deadline alerts', 'Fristhinweise', 'Alertes d’échéance'],
            'tenderManagement.countdown.none' => ['Nessun termine', 'No deadline', 'Keine Frist', 'Aucun délai'],
            'tenderManagement.countdown.expired' => ['Scaduta', 'Expired', 'Abgelaufen', 'Échu'],
            'tenderManagement.countdown.daysHours' => ['{days} g {hours} h', '{days} d {hours} h', '{days} T {hours} Std.', '{days} j {hours} h'],
            'tenderManagement.countdown.hoursMinutes' => ['{hours} h {minutes} min {seconds} s', '{hours} h {minutes} min {seconds} s', '{hours} Std. {minutes} Min. {seconds} Sek.', '{hours} h {minutes} min {seconds} s'],
            'tenderManagement.empty.pipeline' => ['Nessuna gara in pipeline.', 'No tenders in the pipeline.', 'Keine Ausschreibungen in der Pipeline.', 'Aucun appel d’offres dans le pipeline.'],
            'tenderManagement.empty.eligibility' => ['Avvia la valutazione per vedere la matrice Go/No-Go.', 'Run the evaluation to see the Go/No-Go matrix.', 'Starten Sie die Bewertung, um die Go/No-Go-Matrix zu sehen.', 'Lancez l’évaluation pour voir la matrice Go/No-Go.'],
            'tenderManagement.empty.checklist' => ['Nessun documento in checklist: carica la checklist standard.', 'No documents in the checklist: load the standard checklist.', 'Keine Dokumente in der Checkliste: Laden Sie die Standard-Checkliste.', 'Aucun document dans la liste : chargez la liste standard.'],
            'tenderManagement.empty.vault' => ['Nessun documento in cassaforte.', 'No documents in the vault.', 'Keine Dokumente im Tresor.', 'Aucun document dans le coffre.'],
            'tenderManagement.empty.benchmarks' => ['Nessuna aggiudicazione registrata.', 'No awards recorded.', 'Keine Zuschläge erfasst.', 'Aucune adjudication enregistrée.'],
            'tenderManagement.create.existing' => ['Gara esistente', 'Existing tender', 'Bestehende Ausschreibung', 'Appel d’offres existant'],
            'tenderManagement.create.new' => ['Nuova gara', 'New tender', 'Neue Ausschreibung', 'Nouvel appel d’offres'],
            'tenderManagement.create.simap' => ['Importa da simap.ch', 'Import from simap.ch', 'Von simap.ch importieren', 'Importer depuis simap.ch'],
            'tenderManagement.create.pickNotice' => ['Seleziona la gara', 'Select the tender', 'Ausschreibung wählen', 'Sélectionnez l’appel d’offres'],
            'tenderManagement.create.simapPayload' => ['Contenuto JSON del bando simap.ch', 'simap.ch notice JSON content', 'JSON-Inhalt der simap.ch-Publikation', 'Contenu JSON de la publication simap.ch'],
            'tenderManagement.matrix.summary' => ['Riepilogo della valutazione', 'Evaluation summary', 'Zusammenfassung der Bewertung', 'Résumé de l’évaluation'],
            'tenderManagement.matrix.metShare' => ['Requisiti soddisfatti: {share}%', 'Requirements met: {share}%', 'Erfüllte Anforderungen: {share} %', 'Exigences remplies : {share} %'],
            'tenderManagement.matrix.criterion' => ['Requisito', 'Requirement', 'Anforderung', 'Exigence'],
            'tenderManagement.matrix.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'tenderManagement.matrix.required' => ['Richiesto', 'Required', 'Verlangt', 'Requis'],
            'tenderManagement.matrix.available' => ['Disponibile', 'Available', 'Vorhanden', 'Disponible'],
            'tenderManagement.matrix.gap' => ['Scostamento', 'Gap', 'Differenz', 'Écart'],
            'tenderManagement.matrix.score' => ['Punti', 'Points', 'Punkte', 'Points'],
            'tenderManagement.matrix.outcome' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'tenderManagement.matrix.met' => ['Soddisfatto', 'Met', 'Erfüllt', 'Rempli'],
            'tenderManagement.matrix.unmet' => ['Non soddisfatto', 'Not met', 'Nicht erfüllt', 'Non rempli'],
            'tenderManagement.matrix.gapsTitle' => ['Lacune da colmare', 'Gaps to cover', 'Zu schliessende Lücken', 'Écarts à combler'],
            'tenderManagement.matrix.similarProjectsGap' => ['Servizi analoghi mancanti: {gap}', 'Similar projects missing: {gap}', 'Fehlende vergleichbare Projekte: {gap}', 'Projets similaires manquants : {gap}'],
            'tenderManagement.matrix.suggestedQuota' => ['Quota suggerita per i partner: {quota}%', 'Suggested partners’ share: {quota}%', 'Empfohlener Anteil der Partner: {quota} %', 'Part suggérée des partenaires : {quota} %'],
            'tenderManagement.matrix.missingCertifications' => ['Certificazioni mancanti:', 'Missing certifications:', 'Fehlende Zertifizierungen:', 'Certifications manquantes :'],
            'tenderManagement.matrix.participationTitle' => ['Forma di partecipazione e partner', 'Participation mode and partners', 'Teilnahmeform und Partner', 'Forme de participation et partenaires'],
            'tenderManagement.matrix.categories.financial' => ['Economico-finanziario', 'Financial', 'Finanziell', 'Financier'],
            'tenderManagement.matrix.categories.technical' => ['Tecnico-professionale', 'Technical', 'Technisch', 'Technique'],
            'tenderManagement.matrix.categories.qualification' => ['Qualificazione (SOA)', 'Qualification (SOA)', 'Qualifikation (SOA)', 'Qualification (SOA)'],
            'tenderManagement.matrix.categories.certification' => ['Certificazioni', 'Certifications', 'Zertifizierungen', 'Certifications'],
            'tenderManagement.matrix.categories.compliance' => ['Conformità', 'Compliance', 'Konformität', 'Conformité'],
            'tenderManagement.criterion.turnover' => ['Fatturato specifico', 'Specific turnover', 'Spezifischer Umsatz', 'Chiffre d’affaires spécifique'],
            'tenderManagement.criterion.similar_projects' => ['Servizi analoghi eseguiti', 'Similar projects delivered', 'Ausgeführte vergleichbare Projekte', 'Projets similaires réalisés'],
            'tenderManagement.criterion.soa' => ['Categoria SOA {category}', 'SOA category {category}', 'SOA-Kategorie {category}', 'Catégorie SOA {category}'],
            'tenderManagement.criterion.certifications' => ['Certificazioni', 'Certifications', 'Zertifizierungen', 'Certifications'],
            'tenderManagement.criterion.references' => ['Referenze', 'References', 'Referenzen', 'Références'],
            'tenderManagement.criterion.compliance' => ['Conformità', 'Compliance', 'Konformität', 'Conformité'],
            'tenderManagement.envelopes.ready' => ['Le buste sono complete: la gara può essere presentata.', 'The envelopes are complete: the tender can be submitted.', 'Die Umschläge sind vollständig: Die Ausschreibung kann eingereicht werden.', 'Les enveloppes sont complètes : l’offre peut être déposée.'],
            'tenderManagement.envelopes.notReady' => ['Le buste non sono ancora pronte per la presentazione.', 'The envelopes are not yet ready for submission.', 'Die Umschläge sind noch nicht bereit zur Einreichung.', 'Les enveloppes ne sont pas encore prêtes pour le dépôt.'],
            'tenderManagement.envelopes.complete' => ['Completa', 'Complete', 'Vollständig', 'Complète'],
            'tenderManagement.envelopes.incomplete' => ['Incompleta', 'Incomplete', 'Unvollständig', 'Incomplète'],
            'tenderManagement.envelopes.required' => ['Obbligatorio', 'Required', 'Obligatorisch', 'Obligatoire'],
            'tenderManagement.envelopes.signatureRequired' => ['Firma digitale richiesta', 'Digital signature required', 'Digitale Signatur erforderlich', 'Signature numérique requise'],
            'tenderManagement.envelopes.edgueGenerated' => ['eDGUE generato (versione {version})', 'eDGUE generated (version {version})', 'eDGUE erstellt (Version {version})', 'eDGUE généré (version {version})'],
            'tenderManagement.envelopes.submitBlocked' => ['Completa le buste e risolvi i rilievi bloccanti per presentare.', 'Complete the envelopes and resolve the blocking findings to submit.', 'Vervollständigen Sie die Umschläge und beheben Sie die blockierenden Befunde, um einzureichen.', 'Complétez les enveloppes et résolvez les constats bloquants pour déposer.'],
            'tenderManagement.offer.discount' => ['Ribasso offerto (%)', 'Discount offered (%)', 'Angebotener Rabatt (%)', 'Rabais offert (%)'],
            'tenderManagement.offer.estimatedCost' => ['Costo totale stimato', 'Estimated total cost', 'Geschätzte Gesamtkosten', 'Coût total estimé'],
            'tenderManagement.offer.otherDirectCosts' => ['Altri costi diretti', 'Other direct costs', 'Übrige direkte Kosten', 'Autres coûts directs'],
            'tenderManagement.offer.companySafetyCosts' => ['Oneri di sicurezza aziendali (art. 108 co. 9)', 'Company safety costs (Art. 108 para. 9)', 'Betriebliche Sicherheitskosten (Art. 108 Abs. 9)', 'Coûts de sécurité de l’entreprise (art. 108 al. 9)'],
            'tenderManagement.offer.laborCosts' => ['Costi della manodopera (art. 108 co. 9)', 'Labor costs (Art. 108 para. 9)', 'Lohnkosten (Art. 108 Abs. 9)', 'Coûts de main-d’œuvre (art. 108 al. 9)'],
            'tenderManagement.offer.bidBasePercentage' => ['Garanzia provvisoria: percentuale base', 'Provisional bid bond: base percentage', 'Provisorische Garantie: Basisprozentsatz', 'Garantie provisoire : pourcentage de base'],
            'tenderManagement.offer.technicalEstimate' => ['Punteggio tecnico stimato (max {max})', 'Estimated technical score (max {max})', 'Geschätzte technische Punktzahl (max. {max})', 'Score technique estimé (max {max})'],
            'tenderManagement.offer.bestCompetitor' => ['Miglior ribasso dei concorrenti (%)', 'Best competitor discount (%)', 'Bester Rabatt der Konkurrenz (%)', 'Meilleur rabais des concurrents (%)'],
            'tenderManagement.offer.competitorDiscounts' => ['Ribassi dei concorrenti (%)', 'Competitor discounts (%)', 'Rabatte der Konkurrenz (%)', 'Rabais des concurrents (%)'],
            'tenderManagement.offer.useHistory' => ['Usa lo storico ({count} ribassi)', 'Use the history ({count} discounts)', 'Historie verwenden ({count} Rabatte)', 'Utiliser l’historique ({count} rabais)'],
            'tenderManagement.offer.offeredAmount' => ['Importo offerto', 'Offered amount', 'Angebotsbetrag', 'Montant offert'],
            'tenderManagement.offer.netMargin' => ['Margine netto', 'Net margin', 'Nettomarge', 'Marge nette'],
            'tenderManagement.offer.netMarginPercentage' => ['Margine netto %', 'Net margin %', 'Nettomarge %', 'Marge nette %'],
            'tenderManagement.offer.technicalScore' => ['Punteggio tecnico', 'Technical score', 'Technische Punktzahl', 'Score technique'],
            'tenderManagement.offer.economicScore' => ['Punteggio economico', 'Economic score', 'Wirtschaftliche Punktzahl', 'Score économique'],
            'tenderManagement.offer.totalScore' => ['Punteggio totale', 'Total score', 'Gesamtpunktzahl', 'Score total'],
            'tenderManagement.offer.formulaUsed' => ['Formula applicata', 'Formula applied', 'Angewandte Formel', 'Formule appliquée'],
            'tenderManagement.offer.bondTitle' => ['Garanzia provvisoria (art. 106)', 'Provisional bid bond (Art. 106)', 'Provisorische Garantie (Art. 106)', 'Garantie provisoire (art. 106)'],
            'tenderManagement.offer.bondBase' => ['Importo base ({percentage}%)', 'Base amount ({percentage}%)', 'Basisbetrag ({percentage} %)', 'Montant de base ({percentage} %)'],
            'tenderManagement.offer.bondFinal' => ['Importo finale ridotto', 'Final reduced amount', 'Reduzierter Endbetrag', 'Montant final réduit'],
            'tenderManagement.offer.noReductions' => ['Nessuna riduzione applicabile dai documenti validi in cassaforte.', 'No reduction applies from the valid documents in the vault.', 'Aus den gültigen Dokumenten im Tresor ergibt sich keine Reduktion.', 'Aucune réduction ne s’applique d’après les documents valides du coffre.'],
            'tenderManagement.offer.reduction.pmi' => ['PMI', 'SME (PMI)', 'KMU (PMI)', 'PME (PMI)'],
            'tenderManagement.offer.reduction.iso9001' => ['ISO 9001', 'ISO 9001', 'ISO 9001', 'ISO 9001'],
            'tenderManagement.offer.reduction.emas' => ['EMAS', 'EMAS', 'EMAS', 'EMAS'],
            'tenderManagement.offer.reduction.iso14001' => ['ISO 14001', 'ISO 14001', 'ISO 14001', 'ISO 14001'],
            'tenderManagement.offer.reduction.pdr125' => ['UNI/PdR 125', 'UNI/PdR 125', 'UNI/PdR 125', 'UNI/PdR 125'],
            'tenderManagement.offer.linkedBond' => ['Garanzia collegata (Mod 147)', 'Bond linked (Mod 147)', 'Garantie verknüpft (Mod 147)', 'Garantie liée (Mod 147)'],
            'tenderManagement.offer.serverResult' => ['Calcolo del server (vincolante)', 'Server calculation (authoritative)', 'Serverberechnung (verbindlich)', 'Calcul du serveur (faisant foi)'],
            'tenderManagement.offer.anacContribution' => ['Contributo ANAC', 'ANAC contribution', 'ANAC-Beitrag', 'Contribution ANAC'],
            'tenderManagement.offer.submissionBlocked' => ['Presentazione bloccata', 'Submission blocked', 'Einreichung blockiert', 'Dépôt bloqué'],
            'tenderManagement.convert.title' => ['Converti l’aggiudicazione in contratto e commessa', 'Convert the award into a contract and project', 'Zuschlag in Vertrag und Projekt umwandeln', 'Convertir l’adjudication en contrat et en projet'],
            'tenderManagement.convert.intro' => ['Verranno creati il contratto (Mod 108) e la commessa (Mod 065) con i riferimenti della gara.', 'The contract (Mod 108) and the project (Mod 065) will be created with the references of the tender.', 'Vertrag (Mod 108) und Projekt (Mod 065) werden mit den Referenzen der Ausschreibung erstellt.', 'Le contrat (Mod 108) et le projet (Mod 065) seront créés avec les références de l’appel d’offres.'],
            'tenderManagement.convert.inherited' => ['Riferimenti ereditati', 'Inherited references', 'Übernommene Referenzen', 'Références héritées'],
            'tenderManagement.convert.contact' => ['Cliente da fatturare', 'Customer to invoice', 'Rechnungsempfänger', 'Client à facturer'],
            'tenderManagement.convert.contractTitle' => ['Titolo del contratto', 'Contract title', 'Vertragstitel', 'Titre du contrat'],
            'tenderManagement.convert.plannedStart' => ['Inizio previsto', 'Planned start', 'Geplanter Beginn', 'Début prévu'],
            'tenderManagement.convert.plannedEnd' => ['Fine prevista', 'Planned end', 'Geplantes Ende', 'Fin prévue'],
            'tenderManagement.convert.done' => ['Conversione completata.', 'Conversion completed.', 'Umwandlung abgeschlossen.', 'Conversion terminée.'],
            'tenderManagement.convert.contract' => ['Contratto', 'Contract', 'Vertrag', 'Contrat'],
            'tenderManagement.convert.project' => ['Commessa', 'Project', 'Projekt', 'Projet'],
            'tenderManagement.convert.alreadyConverted' => ['Già convertita', 'Already converted', 'Bereits umgewandelt', 'Déjà convertie'],
            'tenderManagement.vault.IT' => ['Italia', 'Italy', 'Italien', 'Italie'],
            'tenderManagement.vault.CH' => ['Svizzera', 'Switzerland', 'Schweiz', 'Suisse'],
            'tenderManagement.vault.fvoe' => ['FVOE 2.0: {synced}/{total} sincronizzati', 'FVOE 2.0: {synced}/{total} synchronised', 'FVOE 2.0: {synced}/{total} synchronisiert', 'FVOE 2.0 : {synced}/{total} synchronisés'],
            'tenderManagement.vault.fvoeSynced' => ['FVOE 2.0 sincronizzato', 'FVOE 2.0 synchronised', 'FVOE 2.0 synchronisiert', 'FVOE 2.0 synchronisé'],
            'tenderManagement.vault.fvoeNotSynced' => ['FVOE 2.0 non sincronizzato', 'FVOE 2.0 not synchronised', 'FVOE 2.0 nicht synchronisiert', 'FVOE 2.0 non synchronisé'],
            'tenderManagement.vault.missingHint' => ['Mancano documenti tipici per partecipare alle gare di questo paese.', 'Documents usually needed to bid in this country are missing.', 'Für Angebote in diesem Land fehlen übliche Dokumente.', 'Des documents habituellement requis pour soumissionner dans ce pays manquent.'],
            'tenderManagement.vault.daysLeft' => ['{days} giorni', '{days} days', '{days} Tage', '{days} jours'],
            'tenderManagement.vault.rollingValidUntil' => ['Accettato fino al {date} ({days} giorni)', 'Accepted until {date} ({days} days)', 'Gültig bis {date} ({days} Tage)', 'Accepté jusqu’au {date} ({days} jours)'],
            'tenderManagement.vault.logibDeviation' => ['Scostamento Logib: {value}%', 'Logib deviation: {value}%', 'Logib-Abweichung: {value} %', 'Écart Logib : {value} %'],
            'tenderManagement.vault.soaMilestone.intermediate_check' => ['Prossima verifica triennale', 'Next three-year check', 'Nächste Dreijahreskontrolle', 'Prochain contrôle triennal'],
            'tenderManagement.vault.soaMilestone.expiry' => ['Scadenza attestazione', 'Attestation expiry', 'Ablauf der Bescheinigung', 'Échéance de l’attestation'],
            'tenderManagement.vault.modal.create' => ['Nuovo documento', 'New document', 'Neues Dokument', 'Nouveau document'],
            'tenderManagement.vault.modal.edit' => ['Modifica documento', 'Edit document', 'Dokument bearbeiten', 'Modifier le document'],
            'tenderManagement.vault.modal.renew' => ['Rinnova documento', 'Renew document', 'Dokument erneuern', 'Renouveler le document'],
            'tenderManagement.stats.title' => ['Statistiche di mercato', 'Market statistics', 'Marktstatistik', 'Statistiques du marché'],
            'tenderManagement.stats.count' => ['Aggiudicazioni', 'Awards', 'Zuschläge', 'Adjudications'],
            'tenderManagement.stats.averageDiscount' => ['Ribasso medio', 'Average discount', 'Durchschnittlicher Rabatt', 'Rabais moyen'],
            'tenderManagement.stats.medianDiscount' => ['Ribasso mediano', 'Median discount', 'Medianer Rabatt', 'Rabais médian'],
            'tenderManagement.stats.maxDiscount' => ['Ribasso massimo', 'Maximum discount', 'Maximaler Rabatt', 'Rabais maximal'],
            'tenderManagement.stats.averageTechnical' => ['Punteggio tecnico medio', 'Average technical score', 'Durchschnittliche technische Punktzahl', 'Score technique moyen'],
            'tenderManagement.stats.averageEconomic' => ['Punteggio economico medio', 'Average economic score', 'Durchschnittliche wirtschaftliche Punktzahl', 'Score économique moyen'],
            'tenderManagement.stats.averageTotal' => ['Punteggio totale medio', 'Average total score', 'Durchschnittliche Gesamtpunktzahl', 'Score total moyen'],
            'tenderManagement.stats.winner' => ['Aggiudicatario', 'Winner', 'Zuschlagsempfänger', 'Adjudicataire'],
            'tenderManagement.stats.participant' => ['Partecipante', 'Participant', 'Teilnehmer', 'Participant'],
            'tenderManagement.confirm.deleteDocument' => ['Eliminare il documento dalla cassaforte?', 'Delete the document from the vault?', 'Dokument aus dem Tresor löschen?', 'Supprimer le document du coffre ?'],
            'tenderManagement.confirm.deleteAward' => ['Eliminare questa aggiudicazione?', 'Delete this award?', 'Diesen Zuschlag löschen?', 'Supprimer cette adjudication ?'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'tenderManagement.action.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'tenderManagement.action.search' => ['Cerca', 'Search', 'Suchen', 'Rechercher'],
            'tenderManagement.action.open' => ['Apri', 'Open', 'Öffnen', 'Ouvrir'],
            'tenderManagement.action.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'tenderManagement.action.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'tenderManagement.action.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
            'tenderManagement.action.edit' => ['Modifica', 'Edit', 'Bearbeiten', 'Modifier'],
            'tenderManagement.action.remove' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],
            'tenderManagement.action.replace' => ['Sostituisci', 'Replace', 'Ersetzen', 'Remplacer'],
            'tenderManagement.action.upload' => ['Carica', 'Upload', 'Hochladen', 'Téléverser'],
            'tenderManagement.action.renew' => ['Rinnova', 'Renew', 'Erneuern', 'Renouveler'],
            'tenderManagement.action.newTender' => ['Nuova gara', 'New tender', 'Neue Ausschreibung', 'Nouvel appel d’offres'],
            'tenderManagement.action.createDossier' => ['Apri dossier di partecipazione', 'Open participation dossier', 'Teilnahmedossier eröffnen', 'Ouvrir le dossier de participation'],
            'tenderManagement.action.ingestSimap' => ['Importa il bando', 'Import the notice', 'Publikation importieren', 'Importer la publication'],
            'tenderManagement.action.moveTo' => ['Sposta in…', 'Move to…', 'Verschieben nach…', 'Déplacer vers…'],
            'tenderManagement.action.moveToStatus' => ['Porta a: {status}', 'Move to: {status}', 'Setzen auf: {status}', 'Passer à : {status}'],
            'tenderManagement.action.editRequirements' => ['Modifica requisiti', 'Edit requirements', 'Anforderungen bearbeiten', 'Modifier les exigences'],
            'tenderManagement.action.evaluate' => ['Valuta idoneità Go/No-Go', 'Evaluate Go/No-Go eligibility', 'Eignung Go/No-Go bewerten', 'Évaluer l’éligibilité Go/No-Go'],
            'tenderManagement.action.addPartner' => ['Aggiungi partner', 'Add partner', 'Partner hinzufügen', 'Ajouter un partenaire'],
            'tenderManagement.action.addSoa' => ['Aggiungi categoria SOA', 'Add SOA category', 'SOA-Kategorie hinzufügen', 'Ajouter une catégorie SOA'],
            'tenderManagement.action.seedChecklist' => ['Carica la checklist standard', 'Load the standard checklist', 'Standard-Checkliste laden', 'Charger la liste standard'],
            'tenderManagement.action.generateEdgue' => ['Genera eDGUE', 'Generate eDGUE', 'eDGUE erstellen', 'Générer l’eDGUE'],
            'tenderManagement.action.downloadEdgueXml' => ['Scarica eDGUE (XML)', 'Download eDGUE (XML)', 'eDGUE herunterladen (XML)', 'Télécharger l’eDGUE (XML)'],
            'tenderManagement.action.downloadEdguePdf' => ['Scarica eDGUE (PDF)', 'Download eDGUE (PDF)', 'eDGUE herunterladen (PDF)', 'Télécharger l’eDGUE (PDF)'],
            'tenderManagement.action.submitTender' => ['Presenta l’offerta', 'Submit the tender', 'Angebot einreichen', 'Déposer l’offre'],
            'tenderManagement.action.calculateServer' => ['Calcola sul server', 'Calculate on the server', 'Auf dem Server berechnen', 'Calculer sur le serveur'],
            'tenderManagement.action.saveOffer' => ['Salva offerta nel dossier', 'Save the offer in the dossier', 'Angebot im Dossier speichern', 'Enregistrer l’offre dans le dossier'],
            'tenderManagement.action.convertAward' => ['Converti in contratto e commessa', 'Convert to contract and project', 'In Vertrag und Projekt umwandeln', 'Convertir en contrat et projet'],
            'tenderManagement.action.convert' => ['Converti', 'Convert', 'Umwandeln', 'Convertir'],
            'tenderManagement.action.addDocument' => ['Aggiungi documento', 'Add document', 'Dokument hinzufügen', 'Ajouter un document'],
            'tenderManagement.action.syncFvoe' => ['Sincronizza FVOE 2.0', 'Sync FVOE 2.0', 'FVOE 2.0 synchronisieren', 'Synchroniser le FVOE 2.0'],
            'tenderManagement.action.addAward' => ['Aggiungi aggiudicazione', 'Add award', 'Zuschlag hinzufügen', 'Ajouter une adjudication'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function messages(): array
    {
        return [
            'tenderManagement.message.saved' => ['Salvataggio eseguito.', 'Saved.', 'Gespeichert.', 'Enregistré.'],
            'tenderManagement.message.deleted' => ['Eliminato.', 'Deleted.', 'Gelöscht.', 'Supprimé.'],
            'tenderManagement.message.ingested' => ['Bando importato.', 'Notice imported.', 'Publikation importiert.', 'Publication importée.'],
            'tenderManagement.message.statusChanged' => ['Stato aggiornato.', 'Status updated.', 'Status aktualisiert.', 'Statut mis à jour.'],
            'tenderManagement.message.evaluated' => ['Valutazione aggiornata.', 'Evaluation updated.', 'Bewertung aktualisiert.', 'Évaluation mise à jour.'],
            'tenderManagement.message.uploaded' => ['Documento caricato.', 'Document uploaded.', 'Dokument hochgeladen.', 'Document téléversé.'],
            'tenderManagement.message.edgueGenerated' => ['eDGUE generato.', 'eDGUE generated.', 'eDGUE erstellt.', 'eDGUE généré.'],
            'tenderManagement.message.offerSaved' => ['Offerta salvata nel dossier.', 'Offer saved in the dossier.', 'Angebot im Dossier gespeichert.', 'Offre enregistrée dans le dossier.'],
            'tenderManagement.message.submitted' => ['Offerta presentata.', 'Tender submitted.', 'Angebot eingereicht.', 'Offre déposée.'],
            'tenderManagement.message.converted' => ['Contratto e commessa creati.', 'Contract and project created.', 'Vertrag und Projekt erstellt.', 'Contrat et projet créés.'],
            'tenderManagement.message.fvoeSynced' => ['Sincronizzazione FVOE 2.0 eseguita.', 'FVOE 2.0 synchronisation done.', 'FVOE-2.0-Synchronisation ausgeführt.', 'Synchronisation FVOE 2.0 effectuée.'],
        ];
    }

    /**
     * The documents of the default envelope checklists (tenderManagement.envelopeItem.<key>) and the Go/No-Go criteria the
     * evaluator names besides the ones with their own key pattern.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function envelopeItems(): array
    {
        return [
            'tenderManagement.criterion.fvoe_sync' => ['Allineamento FVOE 2.0', 'FVOE 2.0 alignment', 'FVOE-2.0-Abgleich', 'Alignement FVOE 2.0'],
            'tenderManagement.envelopeItem.edgue' => ['eDGUE (documento di gara unico europeo)', 'eDGUE (European Single Procurement Document)', 'eDGUE (Einheitliche Europäische Eigenerklärung)', 'eDGUE (document unique de marché européen)'],
            'tenderManagement.envelopeItem.soa_attestation' => ['Attestazione SOA', 'SOA attestation', 'SOA-Bescheinigung', 'Attestation SOA'],
            'tenderManagement.envelopeItem.durc' => ['DURC in corso di validità', 'Valid DURC', 'Gültiges DURC', 'DURC en cours de validité'],
            'tenderManagement.envelopeItem.visura_camerale' => ['Visura camerale', 'Chamber of commerce extract', 'Handelsregisterauszug', 'Extrait du registre du commerce'],
            'tenderManagement.envelopeItem.casellario_giudiziale' => ['Casellario giudiziale e carichi pendenti', 'Criminal record and pending charges', 'Strafregister und hängige Verfahren', 'Casier judiciaire et charges pendantes'],
            'tenderManagement.envelopeItem.bid_bond' => ['Garanzia provvisoria', 'Provisional bid bond', 'Provisorische Sicherheit', 'Garantie provisoire'],
            'tenderManagement.envelopeItem.anac_contribution_receipt' => ['Ricevuta del contributo ANAC', 'ANAC contribution receipt', 'Beleg des ANAC-Beitrags', 'Reçu de la contribution ANAC'],
            'tenderManagement.envelopeItem.pass_oe' => ['PASSOE', 'PASSOE', 'PASSOE', 'PASSOE'],
            'tenderManagement.envelopeItem.rti_mandate' => ['Mandato del raggruppamento (RTI / consorzio)', 'Grouping mandate (RTI / consortium)', 'Mandat der Bietergemeinschaft (RTI / Konsortium)', 'Mandat du groupement (RTI / consortium)'],
            'tenderManagement.envelopeItem.avvalimento_contract' => ['Contratto di avvalimento', 'Avvalimento agreement', 'Avvalimento-Vertrag', 'Contrat d’avvalimento'],
            'tenderManagement.envelopeItem.financial_traceability_declaration' => ['Dichiarazione di tracciabilità dei flussi finanziari (L. 136/2010)', 'Financial traceability declaration (L. 136/2010)', 'Erklärung zur Rückverfolgbarkeit der Finanzflüsse (L. 136/2010)', 'Déclaration de traçabilité des flux financiers (L. 136/2010)'],
            'tenderManagement.envelopeItem.integrity_pact' => ['Patto di integrità', 'Integrity pact', 'Integritätspakt', 'Pacte d’intégrité'],
            'tenderManagement.envelopeItem.technical_offer' => ['Offerta tecnica', 'Technical offer', 'Technisches Angebot', 'Offre technique'],
            'tenderManagement.envelopeItem.work_plan' => ['Piano di lavoro', 'Work plan', 'Arbeitsplan', 'Plan de travail'],
            'tenderManagement.envelopeItem.quality_plan' => ['Piano della qualità', 'Quality plan', 'Qualitätsplan', 'Plan qualité'],
            'tenderManagement.envelopeItem.safety_plan' => ['Piano di sicurezza', 'Safety plan', 'Sicherheitsplan', 'Plan de sécurité'],
            'tenderManagement.envelopeItem.team_curricula' => ['Curricula del gruppo di lavoro', 'Team curricula', 'Lebensläufe des Teams', 'CV de l’équipe'],
            'tenderManagement.envelopeItem.economic_offer' => ['Offerta economica', 'Economic offer', 'Wirtschaftliches Angebot', 'Offre économique'],
            'tenderManagement.envelopeItem.labor_safety_costs_declaration' => ['Dichiarazione di costi della manodopera e oneri di sicurezza aziendali (Art. 108 co. 9)', 'Declaration of labour costs and company safety costs (Art. 108 para. 9)', 'Erklärung der Lohnkosten und betrieblichen Sicherheitskosten (Art. 108 Abs. 9)', 'Déclaration des coûts de main-d’œuvre et de sécurité de l’entreprise (art. 108 al. 9)'],
            'tenderManagement.envelopeItem.cost_justification' => ['Giustificativi dei costi', 'Cost justification', 'Kostennachweis', 'Justificatifs des coûts'],
            'tenderManagement.envelopeItem.bid_form' => ['Modulo di offerta', 'Bid form', 'Angebotsformular', 'Formulaire d’offre'],
            'tenderManagement.envelopeItem.betreibungsauszug' => ['Estratto del registro delle esecuzioni e dei fallimenti (max 3 mesi)', 'Debt-enforcement extract (max 3 months)', 'Betreibungsregisterauszug (max. 3 Monate)', 'Extrait du registre des poursuites (3 mois au plus)'],
            'tenderManagement.envelopeItem.avs_certificate' => ['Attestato AVS/AI/IPG', 'AVS/AI/IPG certificate', 'AHV/IV/EO-Bescheinigung', 'Attestation AVS/AI/APG'],
            'tenderManagement.envelopeItem.suva_certificate' => ['Attestato SUVA', 'SUVA certificate', 'SUVA-Bescheinigung', 'Attestation SUVA'],
            'tenderManagement.envelopeItem.lpp_certificate' => ['Attestato LPP', 'LPP certificate', 'BVG-Bescheinigung', 'Attestation LPP'],
            'tenderManagement.envelopeItem.afc_vat_certificate' => ['Attestato AFC (IVA)', 'AFC certificate (VAT)', 'ESTV-Bescheinigung (MWST)', 'Attestation AFC (TVA)'],
            'tenderManagement.envelopeItem.ccl_declaration' => ['Dichiarazione di rispetto del CCL', 'Declaration of compliance with the CCL', 'Erklärung zur Einhaltung des GAV', 'Déclaration de respect de la CCT'],
            'tenderManagement.envelopeItem.logib_analysis' => ['Analisi Logib della parità salariale', 'Logib equal-pay analysis', 'Logib-Lohngleichheitsanalyse', 'Analyse Logib de l’égalité salariale'],
            'tenderManagement.envelopeItem.subcontractor_declaration' => ['Dichiarazione sui subappaltatori', 'Subcontractor declaration', 'Erklärung zu Subunternehmern', 'Déclaration sur les sous-traitants'],
            'tenderManagement.envelopeItem.consortium_declaration' => ['Dichiarazione del consorzio o della comunità di offerenti', 'Consortium or bidding community declaration', 'Erklärung der Bietergemeinschaft', 'Déclaration du consortium ou de la communauté de soumissionnaires'],
            'tenderManagement.envelopeItem.methodology' => ['Metodologia', 'Methodology', 'Methodik', 'Méthodologie'],
            'tenderManagement.envelopeItem.references' => ['Referenze', 'References', 'Referenzen', 'Références'],
            'tenderManagement.envelopeItem.schedule' => ['Cronoprogramma', 'Schedule', 'Terminplan', 'Calendrier'],
            'tenderManagement.envelopeItem.price_sheet' => ['Scheda prezzi', 'Price sheet', 'Preisblatt', 'Bordereau des prix'],
            'tenderManagement.envelopeItem.price_breakdown' => ['Analisi dei prezzi', 'Price breakdown', 'Preisanalyse', 'Analyse des prix'],
            'tenderManagement.envelopeItem.company_profile' => ['Profilo aziendale', 'Company profile', 'Firmenprofil', 'Profil de l’entreprise'],
            'tenderManagement.envelopeItem.declarations' => ['Dichiarazioni', 'Declarations', 'Erklärungen', 'Déclarations'],
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
