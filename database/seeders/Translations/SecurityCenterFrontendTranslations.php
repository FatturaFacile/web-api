<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the Security, Privacy & Compliance Center: posture dashboard, audit trail explorer, privacy console
 * (DSAR wizard, retention matrix), breach board and the PII reveal control. Each entry is [it, en, de, fr]; the Italian
 * text serves it-CH and it-IT, the German text de-CH and the French text fr-CH. Placeholders are written {name}.
 */
final class SecurityCenterFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'SECURITY_CENTER.NAV.TITLE' => ['Centro sicurezza e conformità', 'Security & Compliance Center', 'Sicherheits- und Compliance-Center', 'Centre sécurité et conformité'],
            'SECURITY_CENTER.TITLE' => ['Centro sicurezza, privacy e conformità', 'Security, Privacy & Compliance Center', 'Sicherheits-, Datenschutz- und Compliance-Center', 'Centre de sécurité, confidentialité et conformité'],
            'SECURITY_CENTER.SUBTITLE' => [
                'Integrità del registro di audit, cifratura, richieste degli interessati, blocchi legali e incidenti in un unico posto.',
                'Audit ledger integrity, encryption, data subject requests, legal holds and incidents in one place.',
                'Integrität des Audit-Registers, Verschlüsselung, Betroffenenanfragen, Legal Holds und Vorfälle an einem Ort.',
                'Intégrité du registre d’audit, chiffrement, demandes des personnes concernées, blocages légaux et incidents au même endroit.',
            ],
            'SECURITY_CENTER.TAB.POSTURE' => ['Postura', 'Posture', 'Sicherheitslage', 'Posture'],
            'SECURITY_CENTER.TAB.AUDIT' => ['Registro di audit', 'Audit trail', 'Audit-Trail', 'Journal d’audit'],
            'SECURITY_CENTER.TAB.PRIVACY' => ['Console privacy', 'Privacy console', 'Datenschutz-Konsole', 'Console de confidentialité'],
            'SECURITY_CENTER.TAB.BREACHES' => ['Violazioni dei dati', 'Data breaches', 'Datenschutzverletzungen', 'Violations de données'],

            'SECURITY_CENTER.POSTURE.LOAD_ERROR' => ['Impossibile caricare la postura di sicurezza', 'The security posture could not be loaded', 'Die Sicherheitslage konnte nicht geladen werden', 'La posture de sécurité n’a pas pu être chargée'],
            'SECURITY_CENTER.POSTURE.AUDIT_CHAIN' => ['Integrità della catena di audit', 'Audit chain integrity', 'Integrität der Audit-Kette', 'Intégrité de la chaîne d’audit'],
            'SECURITY_CENTER.POSTURE.CHAIN_VERIFIED' => ['Verificata', 'Verified', 'Verifiziert', 'Vérifiée'],
            'SECURITY_CENTER.POSTURE.CHAIN_TAMPER' => ['Allarme di manomissione', 'Tamper alert', 'Manipulationsalarm', 'Alerte de falsification'],
            'SECURITY_CENTER.POSTURE.LAST_VERIFIED' => ['Ultima verifica', 'Last verified', 'Zuletzt geprüft', 'Dernière vérification'],
            'SECURITY_CENTER.POSTURE.NEVER' => ['mai', 'never', 'nie', 'jamais'],
            'SECURITY_CENTER.POSTURE.ENCRYPTION' => ['Copertura dei campi cifrati', 'Encrypted field coverage', 'Abdeckung verschlüsselter Felder', 'Couverture des champs chiffrés'],
            'SECURITY_CENTER.POSTURE.ENCRYPTED_OF' => ['{encrypted} campi cifrati su {total}', '{encrypted} of {total} fields encrypted', '{encrypted} von {total} Feldern verschlüsselt', '{encrypted} champs chiffrés sur {total}'],
            'SECURITY_CENTER.POSTURE.ACTIVE_DSAR' => ['Richieste degli interessati attive', 'Active data subject requests', 'Aktive Betroffenenanfragen', 'Demandes des personnes concernées actives'],
            'SECURITY_CENTER.POSTURE.NO_DSAR' => ['Nessuna richiesta aperta', 'No open request', 'Keine offene Anfrage', 'Aucune demande ouverte'],
            'SECURITY_CENTER.POSTURE.SLA_DAYS' => ['Prossima scadenza tra {days} giorni', 'Next deadline in {days} days', 'Nächste Frist in {days} Tagen', 'Prochaine échéance dans {days} jours'],
            'SECURITY_CENTER.POSTURE.SLA_OVERDUE' => ['Scaduta da {days} giorni', 'Overdue by {days} days', '{days} Tage überfällig', 'En retard de {days} jours'],
            'SECURITY_CENTER.POSTURE.LEGAL_HOLDS' => ['Blocchi legali attivi', 'Active legal holds', 'Aktive Legal Holds', 'Blocages légaux actifs'],
            'SECURITY_CENTER.POSTURE.INCIDENTS' => ['Incidenti aperti', 'Open incidents', 'Offene Vorfälle', 'Incidents ouverts'],
            'SECURITY_CENTER.POSTURE.READINESS' => ['Indice di prontezza', 'Readiness score', 'Bereitschaftsindex', 'Indice de préparation'],
            'SECURITY_CENTER.POSTURE.READINESS_GOOD' => ['Buona', 'Good', 'Gut', 'Bonne'],
            'SECURITY_CENTER.POSTURE.READINESS_FAIR' => ['Discreta', 'Fair', 'Mittel', 'Moyenne'],
            'SECURITY_CENTER.POSTURE.READINESS_POOR' => ['Insufficiente', 'Poor', 'Ungenügend', 'Insuffisante'],

            'SECURITY_CENTER.AUDIT.CHAIN' => ['Catena di audit non ancora verificata', 'Audit chain not verified yet', 'Audit-Kette noch nicht geprüft', 'Chaîne d’audit pas encore vérifiée'],
            'SECURITY_CENTER.AUDIT.CHAIN_VERIFIED' => ['Catena di audit integra', 'Audit chain intact', 'Audit-Kette intakt', 'Chaîne d’audit intacte'],
            'SECURITY_CENTER.AUDIT.CHAIN_TAMPERED' => ['Catena di audit manomessa', 'Audit chain tampered', 'Audit-Kette manipuliert', 'Chaîne d’audit falsifiée'],
            'SECURITY_CENTER.AUDIT.CHECKED' => ['Registrazioni controllate', 'Records checked', 'Geprüfte Einträge', 'Enregistrements contrôlés'],
            'SECURITY_CENTER.AUDIT.BROKEN_AT' => ['Interrotta alla sequenza', 'Broken at sequence', 'Unterbrochen bei Sequenz', 'Rompue à la séquence'],
            'SECURITY_CENTER.AUDIT.VERIFY_NOW' => ['Verifica la catena ora', 'Verify chain now', 'Kette jetzt prüfen', 'Vérifier la chaîne maintenant'],
            'SECURITY_CENTER.AUDIT.VERIFY_ERROR' => ['Impossibile verificare la catena', 'The chain could not be verified', 'Die Kette konnte nicht geprüft werden', 'La chaîne n’a pas pu être vérifiée'],
            'SECURITY_CENTER.AUDIT.LOAD_ERROR' => ['Impossibile caricare il registro di audit', 'The audit trail could not be loaded', 'Der Audit-Trail konnte nicht geladen werden', 'Le journal d’audit n’a pas pu être chargé'],
            'SECURITY_CENTER.AUDIT.DATE_RANGE' => ['Intervallo di date', 'Date range', 'Zeitraum', 'Période'],
            'SECURITY_CENTER.AUDIT.ACTOR' => ['Utente (ID)', 'Actor (ID)', 'Benutzer (ID)', 'Utilisateur (ID)'],
            'SECURITY_CENTER.AUDIT.ENTITY_TYPE' => ['Tipo di entità', 'Entity type', 'Entitätstyp', 'Type d’entité'],
            'SECURITY_CENTER.AUDIT.ENTITY_ID' => ['ID entità', 'Entity ID', 'Entitäts-ID', 'ID de l’entité'],
            'SECURITY_CENTER.AUDIT.ACTION' => ['Azione', 'Action', 'Aktion', 'Action'],
            'SECURITY_CENTER.AUDIT.SEVERITY' => ['Gravità', 'Severity', 'Schweregrad', 'Gravité'],
            'SECURITY_CENTER.AUDIT.SEVERITY_INFO' => ['Info', 'Info', 'Info', 'Info'],
            'SECURITY_CENTER.AUDIT.SEVERITY_WARNING' => ['Attenzione', 'Warning', 'Warnung', 'Avertissement'],
            'SECURITY_CENTER.AUDIT.SEVERITY_CRITICAL' => ['Critica', 'Critical', 'Kritisch', 'Critique'],
            'SECURITY_CENTER.AUDIT.APPLY' => ['Applica', 'Apply', 'Anwenden', 'Appliquer'],
            'SECURITY_CENTER.AUDIT.RESET' => ['Azzera', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'SECURITY_CENTER.AUDIT.EMPTY' => ['Nessuna registrazione trovata', 'No records found', 'Keine Einträge gefunden', 'Aucun enregistrement trouvé'],
            'SECURITY_CENTER.AUDIT.OCCURRED_AT' => ['Data e ora (UTC)', 'Date and time (UTC)', 'Datum und Zeit (UTC)', 'Date et heure (UTC)'],
            'SECURITY_CENTER.AUDIT.DETAIL' => ['Dettaglio della registrazione', 'Record detail', 'Eintragsdetails', 'Détail de l’enregistrement'],
            'SECURITY_CENTER.AUDIT.DIFF_EMPTY' => ['Questa registrazione non contiene valori', 'This record carries no values', 'Dieser Eintrag enthält keine Werte', 'Cet enregistrement ne contient aucune valeur'],
            'SECURITY_CENTER.AUDIT.DIFF_FIELD' => ['Campo', 'Field', 'Feld', 'Champ'],
            'SECURITY_CENTER.AUDIT.DIFF_BEFORE' => ['Prima', 'Before', 'Vorher', 'Avant'],
            'SECURITY_CENTER.AUDIT.DIFF_AFTER' => ['Dopo', 'After', 'Nachher', 'Après'],
            'SECURITY_CENTER.AUDIT.HASH_INSPECTOR' => ['Ispettore hash', 'Hash inspector', 'Hash-Inspektor', 'Inspecteur de hash'],
            'SECURITY_CENTER.AUDIT.SEQUENCE' => ['Numero di sequenza', 'Sequence number', 'Sequenznummer', 'Numéro de séquence'],
            'SECURITY_CENTER.AUDIT.PREVIOUS_HASH' => ['Hash precedente', 'Previous hash', 'Vorheriger Hash', 'Hash précédent'],
            'SECURITY_CENTER.AUDIT.RECORD_HASH' => ['Hash della registrazione', 'Record hash', 'Hash des Eintrags', 'Hash de l’enregistrement'],

            'SECURITY_CENTER.PRIVACY.ROPA' => ['Registro dei trattamenti', 'RoPA', 'Bearbeitungsverzeichnis', 'Registre des traitements'],
            'SECURITY_CENTER.PRIVACY.CONSENTS' => ['Registro dei consensi', 'Consent ledger', 'Einwilligungsregister', 'Registre des consentements'],
            'SECURITY_CENTER.PRIVACY.DSAR' => ['Richieste DSAR', 'DSAR wizard', 'DSAR-Assistent', 'Assistant DSAR'],
            'SECURITY_CENTER.PRIVACY.RETENTION' => ['Matrice di conservazione CH/IT', 'Retention matrix CH/IT', 'Aufbewahrungsmatrix CH/IT', 'Matrice de conservation CH/IT'],

            'SECURITY_CENTER.DSAR.STEP_REQUEST' => ['Richiesta', 'Request', 'Anfrage', 'Demande'],
            'SECURITY_CENTER.DSAR.STEP_FULFIL' => ['Evasione', 'Fulfilment', 'Bearbeitung', 'Traitement'],
            'SECURITY_CENTER.DSAR.STEP_DONE' => ['Completata', 'Done', 'Abgeschlossen', 'Terminée'],
            'SECURITY_CENTER.DSAR.SUBJECT_TYPE' => ['Tipo di interessato', 'Subject type', 'Art der betroffenen Person', 'Type de personne concernée'],
            'SECURITY_CENTER.DSAR.FULL_NAME' => ['Nome e cognome', 'Full name', 'Vor- und Nachname', 'Nom et prénom'],
            'SECURITY_CENTER.DSAR.NAME_INVALID' => ['Inserisci almeno 2 caratteri', 'Enter at least 2 characters', 'Mindestens 2 Zeichen eingeben', 'Saisissez au moins 2 caractères'],
            'SECURITY_CENTER.DSAR.EMAIL' => ['E-mail dell’interessato', 'Subject e-mail', 'E-Mail der betroffenen Person', 'E-mail de la personne concernée'],
            'SECURITY_CENTER.DSAR.EMAIL_INVALID' => ['Inserisci un indirizzo e-mail valido', 'Enter a valid e-mail address', 'Gültige E-Mail-Adresse eingeben', 'Saisissez une adresse e-mail valide'],
            'SECURITY_CENTER.DSAR.REQUEST_TYPE' => ['Tipo di richiesta', 'Request type', 'Art der Anfrage', 'Type de demande'],
            'SECURITY_CENTER.DSAR.NOTES' => ['Note interne', 'Internal notes', 'Interne Notizen', 'Notes internes'],
            'SECURITY_CENTER.DSAR.CREATE' => ['Registra la richiesta', 'Record the request', 'Anfrage erfassen', 'Enregistrer la demande'],
            'SECURITY_CENTER.DSAR.IDENTITY_METHOD' => ['Metodo di verifica dell’identità', 'Identity verification method', 'Methode der Identitätsprüfung', 'Méthode de vérification d’identité'],
            'SECURITY_CENTER.DSAR.VERIFY_IDENTITY' => ['Verifica l’identità', 'Verify identity', 'Identität prüfen', 'Vérifier l’identité'],
            'SECURITY_CENTER.DSAR.GENERATE_EXPORT' => ['Genera l’esportazione dei dati', 'Generate the data export', 'Datenexport erstellen', 'Générer l’export des données'],
            'SECURITY_CENTER.DSAR.DOWNLOAD' => ['Scarica il pacchetto', 'Download the package', 'Paket herunterladen', 'Télécharger le paquet'],
            'SECURITY_CENTER.DSAR.PACKAGE_QUEUED' => ['Pacchetto in coda', 'Package queued', 'Paket eingereiht', 'Paquet en file'],
            'SECURITY_CENTER.DSAR.PACKAGE_PROCESSING' => ['Pacchetto in preparazione', 'Package being prepared', 'Paket wird erstellt', 'Paquet en préparation'],
            'SECURITY_CENTER.DSAR.PACKAGE_FAILED' => ['Generazione del pacchetto non riuscita', 'Package generation failed', 'Paketerstellung fehlgeschlagen', 'La génération du paquet a échoué'],
            'SECURITY_CENTER.DSAR.LEGAL_HOLD_NOTICE' => [
                'Prima di cancellare, il sistema valuta i blocchi legali e gli obblighi di conservazione fiscale: i dati protetti non vengono cancellati.',
                'Before erasing, the system evaluates legal holds and fiscal retention duties: protected data is not erased.',
                'Vor dem Löschen prüft das System Legal Holds und steuerliche Aufbewahrungspflichten: geschützte Daten werden nicht gelöscht.',
                'Avant l’effacement, le système évalue les blocages légaux et les obligations de conservation fiscale : les données protégées ne sont pas effacées.',
            ],
            'SECURITY_CENTER.DSAR.EVALUATE_ERASURE' => ['Valuta la cancellazione (simulazione)', 'Evaluate the erasure (dry run)', 'Löschung prüfen (Simulation)', 'Évaluer l’effacement (simulation)'],
            'SECURITY_CENTER.DSAR.ACK_ERASURE' => ['Ho letto la valutazione dei blocchi legali e confermo la cancellazione irreversibile', 'I have read the legal-hold evaluation and confirm the irreversible erasure', 'Ich habe die Prüfung der Legal Holds gelesen und bestätige die unwiderrufliche Löschung', 'J’ai lu l’évaluation des blocages légaux et je confirme l’effacement irréversible'],
            'SECURITY_CENTER.DSAR.EXECUTE_ERASURE' => ['Esegui la cancellazione', 'Execute the erasure', 'Löschung ausführen', 'Exécuter l’effacement'],
            'SECURITY_CENTER.DSAR.USE_WORKBENCH' => ['Questo tipo di richiesta si gestisce nella scrivania DSAR della sezione Privacy.', 'This type of request is handled in the DSAR workbench of the Privacy section.', 'Diese Anfrageart wird im DSAR-Arbeitsplatz des Bereichs Datenschutz bearbeitet.', 'Ce type de demande se traite dans le poste de travail DSAR de la section Confidentialité.'],
            'SECURITY_CENTER.DSAR.DONE' => ['Richiesta evasa', 'Request fulfilled', 'Anfrage erledigt', 'Demande traitée'],
            'SECURITY_CENTER.DSAR.NEW_REQUEST' => ['Nuova richiesta', 'New request', 'Neue Anfrage', 'Nouvelle demande'],
            'SECURITY_CENTER.DSAR.ERROR' => ['Operazione non riuscita', 'The operation failed', 'Der Vorgang ist fehlgeschlagen', 'L’opération a échoué'],

            'SECURITY_CENTER.RETENTION.TITLE' => ['Conservazione legale: Svizzera e Italia', 'Statutory retention: Switzerland and Italy', 'Gesetzliche Aufbewahrung: Schweiz und Italien', 'Conservation légale : Suisse et Italie'],
            'SECURITY_CENTER.RETENTION.LOAD_ERROR' => ['Impossibile caricare le regole di conservazione', 'The retention rules could not be loaded', 'Die Aufbewahrungsregeln konnten nicht geladen werden', 'Les règles de conservation n’ont pas pu être chargées'],
            'SECURITY_CENTER.RETENTION.RECORD_CLASS' => ['Classe di documenti', 'Record class', 'Dokumentenklasse', 'Classe de documents'],
            'SECURITY_CENTER.RETENTION.CH' => ['Svizzera (CH)', 'Switzerland (CH)', 'Schweiz (CH)', 'Suisse (CH)'],
            'SECURITY_CENTER.RETENTION.IT' => ['Italia (IT)', 'Italy (IT)', 'Italien (IT)', 'Italie (IT)'],
            'SECURITY_CENTER.RETENTION.CONFIGURED' => ['Regola configurata', 'Configured rule', 'Konfigurierte Regel', 'Règle configurée'],
            'SECURITY_CENTER.RETENTION.YEARS' => ['{years} anni', '{years} years', '{years} Jahre', '{years} ans'],
            'SECURITY_CENTER.RETENTION.NO_FIXED' => ['Nessun termine fisso', 'No fixed period', 'Keine feste Frist', 'Aucun délai fixe'],
            'SECURITY_CENTER.RETENTION.DAYS' => ['{days} giorni', '{days} days', '{days} Tage', '{days} jours'],
            'SECURITY_CENTER.RETENTION.NOT_CONFIGURED' => ['Non configurata', 'Not configured', 'Nicht konfiguriert', 'Non configurée'],
            'SECURITY_CENTER.RETENTION.TOO_SHORT' => ['Troppo breve', 'Too short', 'Zu kurz', 'Trop courte'],
            'SECURITY_CENTER.RETENTION.CLASS_ACCOUNTING_RECORDS' => ['Libri e scritture contabili', 'Accounting records', 'Geschäftsbücher und Buchungsbelege', 'Livres et pièces comptables'],
            'SECURITY_CENTER.RETENTION.CLASS_VAT_DOCUMENTS' => ['Documenti IVA', 'VAT documents', 'MWST-Belege', 'Documents TVA'],
            'SECURITY_CENTER.RETENTION.CLASS_CONSENT_RECORDS' => ['Prove di consenso', 'Consent records', 'Einwilligungsnachweise', 'Preuves de consentement'],
            'SECURITY_CENTER.RETENTION.CLASS_DSAR_RECORDS' => ['Pratiche DSAR', 'DSAR files', 'DSAR-Akten', 'Dossiers DSAR'],

            'SECURITY_CENTER.BREACH.LOAD_ERROR' => ['Impossibile caricare le violazioni dei dati', 'The data breaches could not be loaded', 'Die Datenschutzverletzungen konnten nicht geladen werden', 'Les violations de données n’ont pas pu être chargées'],
            'SECURITY_CENTER.BREACH.NONE' => ['Nessuna violazione dei dati registrata', 'No data breach recorded', 'Keine Datenschutzverletzung erfasst', 'Aucune violation de données enregistrée'],
            'SECURITY_CENTER.BREACH.DETECTED' => ['Rilevata il', 'Detected on', 'Festgestellt am', 'Détectée le'],
            'SECURITY_CENTER.BREACH.NOTIFIED' => ['Autorità notificata', 'Authority notified', 'Behörde benachrichtigt', 'Autorité notifiée'],
            'SECURITY_CENTER.BREACH.TIME_LEFT' => ['Tempo residuo per la notifica (72 ore)', 'Time left to notify (72 hours)', 'Verbleibende Zeit für die Meldung (72 Stunden)', 'Temps restant pour notifier (72 heures)'],

            'SECURITY_CENTER.PII.REVEAL' => ['Mostra', 'Reveal', 'Anzeigen', 'Afficher'],
            'SECURITY_CENTER.PII.HIDE' => ['Nascondi', 'Hide', 'Ausblenden', 'Masquer'],
            'SECURITY_CENTER.PII.JUSTIFICATION' => ['Motivo della visualizzazione', 'Reason for revealing', 'Grund für die Anzeige', 'Motif de l’affichage'],
            'SECURITY_CENTER.PII.JUSTIFICATION_HINT' => ['Almeno {min} caratteri; il motivo viene registrato nel registro di audit.', 'At least {min} characters; the reason is written to the audit trail.', 'Mindestens {min} Zeichen; der Grund wird im Audit-Trail protokolliert.', 'Au moins {min} caractères ; le motif est consigné dans le journal d’audit.'],
            'SECURITY_CENTER.PII.CONFIRM' => ['Conferma e mostra', 'Confirm and reveal', 'Bestätigen und anzeigen', 'Confirmer et afficher'],
            'SECURITY_CENTER.PII.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'SECURITY_CENTER.PII.FORBIDDEN' => ['Non hai il permesso di vedere questo valore', 'You are not allowed to see this value', 'Sie dürfen diesen Wert nicht sehen', 'Vous n’êtes pas autorisé à voir cette valeur'],
            'SECURITY_CENTER.PII.ERROR' => ['Impossibile mostrare il valore', 'The value could not be revealed', 'Der Wert konnte nicht angezeigt werden', 'La valeur n’a pas pu être affichée'],
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
