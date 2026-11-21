<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the workplace health, safety and training module (Mod 099): the traffic-light compliance matrix, the
 * requirement configurator, the PPE issuance drawer, the incident and CAPA board, the audit workspace and the hard-block
 * dialog. Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French
 * text fr-CH. Placeholders are written {name}.
 */
final class HseFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'HSE.NAV' => ['Salute e sicurezza', 'Health and safety', 'Gesundheit und Sicherheit', 'Santé et sécurité'],
            'HSE.NAV_MATRIX' => ['Matrice di conformità', 'Compliance matrix', 'Compliance-Matrix', 'Matrice de conformité'],
            'HSE.NAV_REQUIREMENTS' => ['Requisiti per ruolo', 'Role requirements', 'Anforderungen je Rolle', 'Exigences par rôle'],
            'HSE.NAV_PPE' => ['DPI / PSA', 'PPE', 'PSA', 'EPI'],
            'HSE.NAV_INCIDENTS' => ['Infortuni e near miss', 'Incidents and near misses', 'Unfälle und Beinaheunfälle', 'Accidents et presque-accidents'],
            'HSE.NAV_AUDITS' => ['DVR e audit MSSL/ASA', 'DVR and MSSL/ASA audits', 'DVR und MSSL/ASA-Audits', 'DVR et audits MSST/ASA'],
            'HSE.LOAD_ERROR' => ['Impossibile caricare i dati di sicurezza', 'The safety data could not be loaded', 'Die Sicherheitsdaten konnten nicht geladen werden', 'Les données de sécurité n’ont pas pu être chargées'],
            'HSE.SAVE_ERROR' => ['Operazione non riuscita', 'The operation failed', 'Der Vorgang ist fehlgeschlagen', 'L’opération a échoué'],
            'HSE.EMPLOYEE' => ['Dipendente', 'Employee', 'Mitarbeitende', 'Collaborateur'],
            'HSE.OVERALL' => ['Stato complessivo', 'Overall status', 'Gesamtstatus', 'Statut global'],

            'HSE.MATRIX_TITLE' => ['Matrice di conformità RSPP / SiBe', 'RSPP / SiBe compliance matrix', 'Compliance-Matrix RSPP / SiBe', 'Matrice de conformité RSPP / SiBe'],
            'HSE.GROUP_TRAININGS' => ['Formazione obbligatoria', 'Mandatory trainings', 'Obligatorische Schulungen', 'Formations obligatoires'],
            'HSE.GROUP_MEDICAL' => ['Sorveglianza sanitaria', 'Medical surveillance', 'Arbeitsmedizinische Vorsorge', 'Surveillance médicale'],
            'HSE.GROUP_PPE' => ['DPI / PSA richiesti', 'Required PPE', 'Erforderliche PSA', 'EPI requis'],
            'HSE.KPI_COMPLIANCE' => ['Conformità complessiva', 'Overall compliance', 'Gesamt-Compliance', 'Conformité globale'],
            'HSE.KPI_BLOCKED' => ['Lavoratori bloccati', 'Hard-blocked workers', 'Gesperrte Mitarbeitende', 'Travailleurs bloqués'],
            'HSE.KPI_EXPIRING' => ['In scadenza entro 30 giorni', 'Expiring in 30 days', 'Ablaufend in 30 Tagen', 'Échéance sous 30 jours'],
            'HSE.KPI_OPEN_CAPA' => ['Near miss e azioni correttive aperti', 'Open near misses and CAPAs', 'Offene Beinaheunfälle und CAPAs', 'Presque-accidents et actions correctives ouverts'],
            'HSE.FILTER_DEPARTMENT' => ['Reparto', 'Department', 'Abteilung', 'Département'],
            'HSE.FILTER_WORK_CENTER' => ['Centro di lavoro', 'Work center', 'Arbeitsplatz', 'Centre de travail'],
            'HSE.FILTER_SITE' => ['Cantiere', 'Construction site', 'Baustelle', 'Chantier'],
            'HSE.FILTER_STATUS' => ['Semaforo', 'Traffic light', 'Ampel', 'Feu'],
            'HSE.FILTER_JURISDICTION' => ['Giurisdizione', 'Jurisdiction', 'Rechtsraum', 'Juridiction'],
            'HSE.LIGHT_GREEN' => ['Conforme', 'Compliant', 'Konform', 'Conforme'],
            'HSE.LIGHT_YELLOW' => ['In scadenza o con prescrizioni', 'Expiring or with prescriptions', 'Ablaufend oder mit Auflagen', 'Échéance proche ou avec prescriptions'],
            'HSE.LIGHT_RED' => ['Scaduto, mancante o non idoneo', 'Expired, missing or unfit', 'Abgelaufen, fehlend oder untauglich', 'Expiré, manquant ou inapte'],
            'HSE.EXPIRES_ON' => ['Scade il {date}', 'Expires on {date}', 'Läuft ab am {date}', 'Expire le {date}'],
            'HSE.MISSING' => ['Mancante', 'Missing', 'Fehlt', 'Manquant'],
            'HSE.NO_REQUIREMENTS' => ['Nessun requisito applicabile', 'No applicable requirement', 'Keine Anforderung anwendbar', 'Aucune exigence applicable'],
            'HSE.ACTION_RENEW' => ['Rinnova certificato', 'Renew certificate', 'Zertifikat erneuern', 'Renouveler le certificat'],
            'HSE.ACTION_SCHEDULE_VISIT' => ['Pianifica visita medica', 'Schedule medical visit', 'Arztbesuch planen', 'Planifier une visite médicale'],
            'HSE.ACTION_ISSUE_PPE' => ['Consegna DPI', 'Issue PPE', 'PSA ausgeben', 'Remettre des EPI'],
            'HSE.ACTION_DOWNLOAD_VERBALE' => ['Scarica verbale', 'Download record', 'Protokoll herunterladen', 'Télécharger le procès-verbal'],

            'HSE.CERT_GENERAL_SAFETY' => ['Formazione generale (Accordo Stato-Regioni)', 'General safety (State-Regions Agreement)', 'Allgemeine Sicherheit (Staat-Regionen-Abkommen)', 'Sécurité générale (accord État-Régions)'],
            'HSE.CERT_LOW_RISK' => ['Formazione specifica rischio basso', 'Specific training, low risk', 'Spezifische Schulung, geringes Risiko', 'Formation spécifique, risque faible'],
            'HSE.CERT_MEDIUM_RISK' => ['Formazione specifica rischio medio', 'Specific training, medium risk', 'Spezifische Schulung, mittleres Risiko', 'Formation spécifique, risque moyen'],
            'HSE.CERT_HIGH_RISK' => ['Formazione specifica rischio alto', 'Specific training, high risk', 'Spezifische Schulung, hohes Risiko', 'Formation spécifique, risque élevé'],
            'HSE.CERT_FORKLIFT' => ['Patentino carrellista', 'Forklift licence', 'Staplerfahrausweis', 'Permis de cariste'],
            'HSE.CERT_CRANE' => ['Abilitazione gru', 'Crane operator licence', 'Kranführerausweis', 'Permis de grutier'],
            'HSE.CERT_FIRST_AID' => ['Primo soccorso', 'First aid', 'Erste Hilfe', 'Premiers secours'],
            'HSE.CERT_FIRE_SAFETY' => ['Antincendio', 'Fire safety', 'Brandschutz', 'Protection incendie'],
            'HSE.CERT_WORK_AT_HEIGHT' => ['Lavori in quota', 'Work at height', 'Arbeiten in der Höhe', 'Travaux en hauteur'],
            'HSE.CERT_CONFINED_SPACES' => ['Spazi confinati', 'Confined spaces', 'Enge Räume', 'Espaces confinés'],
            'HSE.CERT_SUVA_PSA' => ['Formazione PSA SUVA', 'SUVA PPE training', 'SUVA-PSA-Schulung', 'Formation EPI SUVA'],
            'HSE.CERT_RSPP' => ['Formazione RSPP', 'RSPP training', 'RSPP-Schulung', 'Formation RSPP'],
            'HSE.CERT_SIBE' => ['Formazione SiBe CFST', 'SiBe EKAS training', 'SiBe-EKAS-Schulung', 'Formation PERCO CFST'],

            'HSE.BLOCK_TITLE' => ['Assegnazione bloccata per sicurezza', 'Assignment blocked for safety', 'Einsatz aus Sicherheitsgründen gesperrt', 'Affectation bloquée pour des raisons de sécurité'],
            'HSE.BLOCK_INTRO' => ['{name} non può essere assegnato: risolvi i requisiti seguenti.', '{name} cannot be assigned: resolve the requirements below.', '{name} kann nicht eingeteilt werden: beheben Sie die folgenden Anforderungen.', '{name} ne peut pas être affecté : résolvez les exigences ci-dessous.'],
            'HSE.BLOCK_CLOSE' => ['Ho capito', 'Understood', 'Verstanden', 'Compris'],
            'HSE.BLOCK_WARNINGS' => ['Prescrizioni da rispettare', 'Prescriptions to respect', 'Einzuhaltende Auflagen', 'Prescriptions à respecter'],
            'HSE.REASON_CERTIFICATE_MISSING' => ['Certificato mancante', 'Certificate missing', 'Zertifikat fehlt', 'Certificat manquant'],
            'HSE.REASON_CERTIFICATE_EXPIRED' => ['Certificato scaduto', 'Certificate expired', 'Zertifikat abgelaufen', 'Certificat expiré'],
            'HSE.REASON_MEDICAL_MISSING' => ['Giudizio di idoneità mancante', 'Medical judgment missing', 'Medizinische Beurteilung fehlt', 'Aptitude médicale manquante'],
            'HSE.REASON_MEDICAL_EXPIRED' => ['Visita medica scaduta', 'Medical visit overdue', 'Arztbesuch überfällig', 'Visite médicale en retard'],
            'HSE.REASON_MEDICAL_UNFIT' => ['Giudizio di inidoneità', 'Judged unfit', 'Als untauglich beurteilt', 'Jugé inapte'],
            'HSE.REASON_MEDICAL_RESTRICTION_CONFLICT' => ['Prescrizione medica incompatibile con l’incarico', 'Medical prescription conflicts with the assignment', 'Ärztliche Auflage steht dem Einsatz entgegen', 'Prescription médicale incompatible avec l’affectation'],
            'HSE.REASON_PPE_MISSING' => ['DPI obbligatorio mancante o da sostituire', 'Mandatory PPE missing or due for replacement', 'Pflicht-PSA fehlt oder ist zu ersetzen', 'EPI obligatoire manquant ou à remplacer'],

            'HSE.FITNESS_FIT' => ['Idoneo', 'Fit', 'Tauglich', 'Apte'],
            'HSE.FITNESS_FIT_WITH_PRESCRIPTIONS' => ['Idoneo con prescrizioni', 'Fit with prescriptions', 'Tauglich mit Auflagen', 'Apte avec prescriptions'],
            'HSE.FITNESS_TEMPORARILY_UNFIT' => ['Inidoneo temporaneo', 'Temporarily unfit', 'Vorübergehend untauglich', 'Temporairement inapte'],
            'HSE.FITNESS_PERMANENTLY_UNFIT' => ['Inidoneo permanente', 'Permanently unfit', 'Dauerhaft untauglich', 'Définitivement inapte'],

            'HSE.REQ_TITLE' => ['Requisiti di sicurezza per ruolo e centro di lavoro', 'Safety requirements by role and work center', 'Sicherheitsanforderungen je Rolle und Arbeitsplatz', 'Exigences de sécurité par rôle et centre de travail'],
            'HSE.REQ_NAME' => ['Nome della regola', 'Rule name', 'Name der Regel', 'Nom de la règle'],
            'HSE.REQ_POSITION' => ['Posizione', 'Job position', 'Stelle', 'Poste'],
            'HSE.REQ_HAZARD_CLASS' => ['Classe di rischio SUVA', 'SUVA hazard class', 'SUVA-Gefahrenklasse', 'Classe de risque SUVA'],
            'HSE.REQ_TRAININGS' => ['Formazioni richieste', 'Required trainings', 'Erforderliche Schulungen', 'Formations requises'],
            'HSE.REQ_MEDICAL' => ['Protocolli sanitari', 'Medical protocols', 'Medizinische Protokolle', 'Protocoles médicaux'],
            'HSE.REQ_PPE' => ['DPI richiesti', 'Required PPE', 'Erforderliche PSA', 'EPI requis'],
            'HSE.REQ_NEEDS_SCOPE' => ['Indica almeno una posizione, un centro di lavoro o un cantiere', 'Pick at least a position, a work center or a construction site', 'Wählen Sie mindestens eine Stelle, einen Arbeitsplatz oder eine Baustelle', 'Indiquez au moins un poste, un centre de travail ou un chantier'],

            'HSE.PPE_TITLE' => ['Consegna DPI / PSA', 'PPE issuance', 'PSA-Ausgabe', 'Remise des EPI'],
            'HSE.PPE_ISSUE' => ['Consegna DPI', 'Issue PPE', 'PSA ausgeben', 'Remettre des EPI'],
            'HSE.PPE_PRODUCT' => ['Articolo', 'Item', 'Artikel', 'Article'],
            'HSE.PPE_WAREHOUSE' => ['Magazzino', 'Warehouse', 'Lager', 'Entrepôt'],
            'HSE.PPE_LOT' => ['Lotto o matricola', 'Lot or serial number', 'Charge oder Seriennummer', 'Lot ou numéro de série'],
            'HSE.PPE_QUANTITY' => ['Quantità', 'Quantity', 'Menge', 'Quantité'],
            'HSE.PPE_STOCK' => ['Disponibili: {quantity}', 'In stock: {quantity}', 'Verfügbar: {quantity}', 'Disponible : {quantity}'],
            'HSE.PPE_REPLACEMENT' => ['Sostituzione entro', 'Replace by', 'Ersatz bis', 'Remplacement avant le'],
            'HSE.PPE_SIGN_HERE' => ['Firma del lavoratore', 'Worker signature', 'Unterschrift des Arbeitnehmers', 'Signature du travailleur'],
            'HSE.PPE_CLEAR_SIGNATURE' => ['Cancella firma', 'Clear signature', 'Unterschrift löschen', 'Effacer la signature'],
            'HSE.PPE_CONFIRM' => ['Conferma consegna', 'Confirm hand-over', 'Übergabe bestätigen', 'Confirmer la remise'],
            'HSE.PPE_DONE' => ['Consegna registrata e verbale archiviato', 'Hand-over recorded and record filed', 'Übergabe erfasst und Protokoll abgelegt', 'Remise enregistrée et procès-verbal archivé'],
            'HSE.PPE_LEGAL_IT' => ['Informativa D.Lgs. 81/08 artt. 75-78', 'Notice D.Lgs. 81/08 Art. 75-78', 'Hinweis D.Lgs. 81/08 Art. 75-78', 'Information D.Lgs. 81/08 art. 75-78'],
            'HSE.PPE_LEGAL_CH' => ['Informativa OPI (OLL) artt. 5 e 90', 'Notice OLL/VUV Art. 5 and 90', 'Hinweis VUV Art. 5 und 90', 'Information OPA (OLL) art. 5 et 90'],

            'HSE.INC_TITLE' => ['Registro infortuni e near miss', 'Incident and near-miss registry', 'Unfall- und Beinaheunfallregister', 'Registre des accidents et presque-accidents'],
            'HSE.INC_REPORT' => ['Segnala un evento', 'Report an event', 'Ereignis melden', 'Signaler un événement'],
            'HSE.INC_TYPE_NEAR_MISS' => ['Near miss', 'Near miss', 'Beinaheunfall', 'Presque-accident'],
            'HSE.INC_TYPE_FIRST_AID' => ['Infortunio con primo soccorso', 'First-aid injury', 'Erste-Hilfe-Verletzung', 'Blessure avec premiers secours'],
            'HSE.INC_TYPE_LOST_TIME' => ['Infortunio con giorni di assenza (INAIL / SUVA)', 'Lost-time accident (INAIL / SUVA)', 'Unfall mit Arbeitsausfall (INAIL / SUVA)', 'Accident avec arrêt de travail (INAIL / SUVA)'],
            'HSE.INC_TYPE_FATAL' => ['Infortunio mortale o grave', 'Fatal or severe accident', 'Tödlicher oder schwerer Unfall', 'Accident mortel ou grave'],
            'HSE.INC_PROGNOSIS' => ['Prognosi (giorni)', 'Prognosis (days)', 'Prognose (Tage)', 'Pronostic (jours)'],
            'HSE.INC_DESCRIPTION' => ['Descrizione', 'Description', 'Beschreibung', 'Description'],
            'HSE.INC_ROOT_CAUSE' => ['Analisi delle cause (5 perché)', 'Root cause analysis (5 whys)', 'Ursachenanalyse (5 Warum)', 'Analyse des causes (5 pourquoi)'],
            'HSE.INC_CAPA' => ['Azioni correttive e preventive', 'Corrective and preventive actions', 'Korrektur- und Vorbeugemassnahmen', 'Actions correctives et préventives'],
            'HSE.INC_CLAIM_LINK' => ['Denuncia in Mod 074', 'Claim in Mod 074', 'Schadenmeldung in Mod 074', 'Déclaration dans Mod 074'],
            'HSE.INC_PROTOCOL' => ['Protocollo', 'Protocol', 'Protokoll', 'Protocole'],
            'HSE.INC_STATUS_DRAFT' => ['Bozza', 'Draft', 'Entwurf', 'Brouillon'],
            'HSE.INC_STATUS_INVESTIGATION' => ['In indagine', 'Under investigation', 'In Untersuchung', 'En enquête'],
            'HSE.INC_STATUS_CAPA_OPEN' => ['Azioni aperte', 'Actions open', 'Massnahmen offen', 'Actions ouvertes'],
            'HSE.INC_STATUS_CLOSED' => ['Chiuso', 'Closed', 'Geschlossen', 'Clôturé'],

            'HSE.AUDIT_TITLE' => ['DVR e audit CFST 6508', 'DVR and CFST 6508 audits', 'DVR und EKAS-6508-Audits', 'DVR et audits CFST 6508'],
            'HSE.AUDIT_MSSL' => ['Autovalutazione MSSL/ASA a 10 punti', '10-point MSSL/ASA self-assessment', 'MSSL/ASA-Selbstbeurteilung in 10 Punkten', 'Auto-évaluation MSST/ASA en 10 points'],
            'HSE.AUDIT_DVR' => ['Revisioni del DVR', 'DVR revisions', 'DVR-Revisionen', 'Révisions du DVR'],
            'HSE.AUDIT_SIGNATORIES' => ['Firmatari: datore di lavoro, RSPP, RLS, medico competente', 'Signatories: employer, RSPP, RLS, occupational physician', 'Unterzeichner: Arbeitgeber, RSPP, RLS, Arbeitsarzt', 'Signataires : employeur, RSPP, RLS, médecin du travail'],
            'HSE.AUDIT_ANSWER_YES' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'HSE.AUDIT_ANSWER_PARTIAL' => ['In parte', 'Partially', 'Teilweise', 'Partiellement'],
            'HSE.AUDIT_ANSWER_NO' => ['No', 'No', 'Nein', 'Non'],
            'HSE.AUDIT_SCORE' => ['Punteggio {score}%', 'Score {score}%', 'Ergebnis {score}%', 'Score {score}%'],
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
