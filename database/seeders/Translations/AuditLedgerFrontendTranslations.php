<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * UI strings of the probative audit ledger (Mod 005, issue #242): the audit settings page (search, entry drawer with the
 * old/new diff), the integrity status card, the export dialog and the record history tab, plus the label of every action the
 * ledger records (audit.actions.<ACTION>). Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German
 * text de-CH and the French text fr-CH. Legal acronyms (SDI, PEC, AVS, LPP, QR-IBAN, P/IVA, CF, UID/IDI) stay untranslated.
 */
final class AuditLedgerFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return array_merge(
            self::page(),
            self::detail(),
            self::enumerations(),
            self::integrity(),
            self::export(),
            self::history(),
            self::actions(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function page(): array
    {
        return [
            'settings.audit.nav' => ['Audit trail', 'Audit trail', 'Audit-Trail', 'Piste d\'audit'],
            'settings.audit.title' => ['Audit trail probatorio', 'Probative audit trail', 'Beweiskräftiger Audit-Trail', 'Piste d\'audit probante'],
            'settings.audit.subtitle' => [
                'Registro immutabile e verificabile di chi ha fatto cosa, quando e da dove',
                'Immutable, verifiable record of who did what, when and from where',
                'Unveränderliches, überprüfbares Protokoll darüber, wer was wann und von wo getan hat',
                'Registre immuable et vérifiable de qui a fait quoi, quand et d\'où',
            ],
            'settings.audit.filter.date_range' => ['Periodo', 'Date range', 'Zeitraum', 'Période'],
            'settings.audit.filter.actor' => ['Utente (ID)', 'Actor (ID)', 'Akteur (ID)', 'Acteur (ID)'],
            'settings.audit.filter.action' => ['Azione', 'Action', 'Aktion', 'Action'],
            'settings.audit.filter.resource_type' => ['Tipo di risorsa', 'Resource type', 'Ressourcentyp', 'Type de ressource'],
            'settings.audit.filter.channel' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'settings.audit.filter.category' => ['Categoria', 'Category', 'Kategorie', 'Catégorie'],
            'settings.audit.filter.apply' => ['Applica', 'Apply', 'Anwenden', 'Appliquer'],
            'settings.audit.filter.reset' => ['Azzera', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'settings.audit.filter.range_too_long' => [
                'Il periodo non può superare 366 giorni',
                'The range cannot exceed 366 days',
                'Der Zeitraum darf 366 Tage nicht überschreiten',
                'La période ne peut pas dépasser 366 jours',
            ],
            'settings.audit.col.sequence' => ['N°', '#', 'Nr.', 'N°'],
            'settings.audit.col.occurred_at' => ['Data e ora (UTC)', 'Timestamp (UTC)', 'Zeitstempel (UTC)', 'Horodatage (UTC)'],
            'settings.audit.col.action' => ['Azione', 'Action', 'Aktion', 'Action'],
            'settings.audit.col.resource' => ['Risorsa', 'Resource', 'Ressource', 'Ressource'],
            'settings.audit.col.actor' => ['Utente', 'Actor', 'Akteur', 'Acteur'],
            'settings.audit.col.channel' => ['Canale', 'Channel', 'Kanal', 'Canal'],
            'settings.audit.empty' => ['Nessun evento per i filtri scelti', 'No event matches the filters', 'Kein Ereignis entspricht den Filtern', 'Aucun événement pour ces filtres'],
            'settings.audit.error.title' => ['Impossibile caricare l\'audit trail', 'The audit trail could not be loaded', 'Der Audit-Trail konnte nicht geladen werden', 'Impossible de charger la piste d\'audit'],
            'settings.audit.error.subtitle' => [
                'Nessun dato è mostrato: riprova tra poco o contatta l\'amministratore.',
                'No data is shown: try again shortly or contact the administrator.',
                'Es werden keine Daten angezeigt: Versuchen Sie es gleich erneut oder kontaktieren Sie den Administrator.',
                'Aucune donnée n\'est affichée : réessayez dans un instant ou contactez l\'administrateur.',
            ],
            'settings.audit.error.forbidden' => [
                'Il tuo ruolo non può consultare l\'audit trail.',
                'Your role cannot read the audit trail.',
                'Ihre Rolle darf den Audit-Trail nicht einsehen.',
                'Votre rôle ne peut pas consulter la piste d\'audit.',
            ],
            'settings.audit.error.retry' => ['Riprova', 'Try again', 'Erneut versuchen', 'Réessayer'],
            'settings.audit.loading_more' => ['Caricamento...', 'Loading...', 'Wird geladen...', 'Chargement...'],
            'settings.audit.load_more' => ['Carica altri', 'Load more', 'Mehr laden', 'Charger plus'],
            'settings.audit.end_of_list' => ['Fine del registro', 'End of the log', 'Ende des Protokolls', 'Fin du registre'],
            'settings.audit.erased' => ['Soggetto cancellato', 'Erased subject', 'Gelöschte Person', 'Personne effacée'],
            'settings.audit.system_actor' => ['Sistema', 'System', 'System', 'Système'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function detail(): array
    {
        return [
            'settings.audit.detail.title' => ['Dettaglio evento', 'Event detail', 'Ereignisdetail', 'Détail de l\'événement'],
            'settings.audit.detail.changes' => ['Modifiche', 'Changes', 'Änderungen', 'Modifications'],
            'settings.audit.detail.field' => ['Campo', 'Field', 'Feld', 'Champ'],
            'settings.audit.detail.old' => ['Prima', 'Before', 'Vorher', 'Avant'],
            'settings.audit.detail.new' => ['Dopo', 'After', 'Nachher', 'Après'],
            'settings.audit.detail.no_changes' => ['Nessun valore registrato', 'No value recorded', 'Kein Wert erfasst', 'Aucune valeur enregistrée'],
            'settings.audit.detail.proof' => ['Prova crittografica', 'Cryptographic proof', 'Kryptografischer Nachweis', 'Preuve cryptographique'],
            'settings.audit.detail.hash' => ['Hash', 'Hash', 'Hash', 'Hash'],
            'settings.audit.detail.previous_hash' => ['Hash precedente', 'Previous hash', 'Vorheriger Hash', 'Hash précédent'],
            'settings.audit.detail.key_version' => ['Versione chiave', 'Key version', 'Schlüsselversion', 'Version de la clé'],
            'settings.audit.detail.context' => ['Contesto', 'Context', 'Kontext', 'Contexte'],
            'settings.audit.detail.impersonator' => ['Operatore reale (impersonificazione)', 'Real operator (impersonation)', 'Echter Bediener (Impersonation)', 'Opérateur réel (usurpation)'],
            'settings.audit.detail.request_id' => ['ID richiesta', 'Request ID', 'Anfrage-ID', 'ID de la requête'],
            'settings.audit.detail.ip' => ['Indirizzo IP (troncato)', 'IP address (truncated)', 'IP-Adresse (gekürzt)', 'Adresse IP (tronquée)'],
            'settings.audit.detail.user_agent' => ['User agent', 'User agent', 'User-Agent', 'User agent'],
            'settings.audit.detail.close' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function enumerations(): array
    {
        return [
            'settings.audit.channel.web' => ['Web', 'Web', 'Web', 'Web'],
            'settings.audit.channel.desktop' => ['Desktop', 'Desktop', 'Desktop', 'Bureau'],
            'settings.audit.channel.mobile' => ['Mobile', 'Mobile', 'Mobil', 'Mobile'],
            'settings.audit.channel.api' => ['API', 'API', 'API', 'API'],
            'settings.audit.category.crud' => ['Dati', 'Data', 'Daten', 'Données'],
            'settings.audit.category.business' => ['Processo', 'Business', 'Geschäftsprozess', 'Métier'],
            'settings.audit.category.security' => ['Sicurezza', 'Security', 'Sicherheit', 'Sécurité'],
            'settings.audit.category.access' => ['Accesso ai dati', 'Data access', 'Datenzugriff', 'Accès aux données'],
            'settings.audit.category.platform' => ['Piattaforma', 'Platform', 'Plattform', 'Plateforme'],
            'settings.audit.actor_type.user' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'settings.audit.actor_type.system' => ['Sistema', 'System', 'System', 'Système'],
            'settings.audit.actor_type.api_key' => ['Chiave API', 'API key', 'API-Schlüssel', 'Clé API'],
            'settings.audit.actor_type.job' => ['Processo automatico', 'Background job', 'Hintergrundjob', 'Tâche automatique'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function integrity(): array
    {
        return [
            'audit.integrity.title' => ['Integrità della catena', 'Chain integrity', 'Integrität der Kette', 'Intégrité de la chaîne'],
            'audit.integrity.status.valid' => ['Catena integra', 'Chain intact', 'Kette intakt', 'Chaîne intacte'],
            'audit.integrity.status.broken_at' => ['Catena alterata', 'Chain broken', 'Kette beschädigt', 'Chaîne altérée'],
            'audit.integrity.status.missing_range' => ['Registrazioni mancanti', 'Entries missing', 'Einträge fehlen', 'Entrées manquantes'],
            'audit.integrity.status.never_verified' => ['Mai verificata', 'Never verified', 'Nie geprüft', 'Jamais vérifiée'],
            'audit.integrity.status.running' => ['Verifica in corso', 'Verification running', 'Prüfung läuft', 'Vérification en cours'],
            'audit.integrity.run.queued' => ['In coda', 'Queued', 'In Warteschlange', 'En file d\'attente'],
            'audit.integrity.run.running' => ['In corso', 'Running', 'Läuft', 'En cours'],
            'audit.integrity.run.completed' => ['Completata', 'Completed', 'Abgeschlossen', 'Terminée'],
            'audit.integrity.run.failed' => ['Fallita', 'Failed', 'Fehlgeschlagen', 'Échouée'],
            'audit.integrity.last_run' => ['Ultima verifica', 'Last verification', 'Letzte Prüfung', 'Dernière vérification'],
            'audit.integrity.verified_entries' => ['Registrazioni verificate', 'Entries verified', 'Geprüfte Einträge', 'Entrées vérifiées'],
            'audit.integrity.tail' => ['Ultima sequenza', 'Last sequence', 'Letzte Sequenz', 'Dernière séquence'],
            'audit.integrity.checkpoint' => ['Ultimo checkpoint', 'Latest checkpoint', 'Letzter Checkpoint', 'Dernier point de contrôle'],
            'audit.integrity.anchored' => ['Ancorato su archivio WORM', 'Anchored on the WORM archive', 'Im WORM-Archiv verankert', 'Ancré sur l\'archive WORM'],
            'audit.integrity.not_anchored' => ['Non ancora ancorato', 'Not anchored yet', 'Noch nicht verankert', 'Pas encore ancré'],
            'audit.integrity.never' => ['Nessuna verifica eseguita', 'No verification has run', 'Noch keine Prüfung durchgeführt', 'Aucune vérification effectuée'],
            'audit.integrity.verify_now' => ['Verifica ora', 'Verify now', 'Jetzt prüfen', 'Vérifier maintenant'],
            'audit.integrity.verifying' => ['Verifica in corso...', 'Verifying...', 'Prüfung läuft...', 'Vérification...'],
            'audit.integrity.queued' => ['Verifica avviata', 'Verification started', 'Prüfung gestartet', 'Vérification lancée'],
            'audit.integrity.already_running' => ['Una verifica è già in corso', 'A verification is already running', 'Eine Prüfung läuft bereits', 'Une vérification est déjà en cours'],
            'audit.integrity.error' => ['Stato di integrità non disponibile', 'The integrity status is not available', 'Der Integritätsstatus ist nicht verfügbar', 'L\'état d\'intégrité n\'est pas disponible'],
            'audit.integrity.broken_title' => ['Catena di audit alterata', 'Audit chain broken', 'Audit-Kette beschädigt', 'Chaîne d\'audit altérée'],
            'audit.integrity.broken_body' => [
                'La registrazione {sequence} non corrisponde al suo hash: i dati sono stati modificati. Avvisa subito il responsabile della sicurezza.',
                'Entry {sequence} does not match its hash: the data was altered. Alert the security officer immediately.',
                'Eintrag {sequence} stimmt nicht mit seinem Hash überein: Die Daten wurden verändert. Informieren Sie sofort den Sicherheitsverantwortlichen.',
                'L\'entrée {sequence} ne correspond pas à son hash : les données ont été modifiées. Alertez immédiatement le responsable de la sécurité.',
            ],
            'audit.integrity.missing_body' => [
                'Mancano le registrazioni da {from} a {to}: sono state cancellate. Avvisa subito il responsabile della sicurezza.',
                'Entries {from} to {to} are missing: they were deleted. Alert the security officer immediately.',
                'Die Einträge {from} bis {to} fehlen: Sie wurden gelöscht. Informieren Sie sofort den Sicherheitsverantwortlichen.',
                'Les entrées {from} à {to} sont manquantes : elles ont été supprimées. Alertez immédiatement le responsable de la sécurité.',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function export(): array
    {
        return [
            'audit.export.button' => ['Esporta', 'Export', 'Exportieren', 'Exporter'],
            'audit.export.title' => ['Esporta il registro di audit', 'Export the audit ledger', 'Audit-Ledger exportieren', 'Exporter le registre d\'audit'],
            'audit.export.hint' => [
                'Pacchetto firmato con CSV, JSON, riepilogo PDF e manifest (hash di inizio e fine, esito della verifica).',
                'Signed package with CSV, JSON, a PDF summary and a manifest (start and end hashes, verification result).',
                'Signiertes Paket mit CSV, JSON, PDF-Zusammenfassung und Manifest (Start- und End-Hash, Prüfergebnis).',
                'Paquet signé avec CSV, JSON, résumé PDF et manifeste (hashs de début et de fin, résultat de la vérification).',
            ],
            'audit.export.range' => ['Periodo (max 366 giorni)', 'Range (max 366 days)', 'Zeitraum (max. 366 Tage)', 'Période (max 366 jours)'],
            'audit.export.submit' => ['Richiedi esportazione', 'Request export', 'Export anfordern', 'Demander l\'export'],
            'audit.export.cancel' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'audit.export.status.queued' => ['In coda', 'Queued', 'In Warteschlange', 'En file d\'attente'],
            'audit.export.status.running' => ['In preparazione', 'Being prepared', 'Wird erstellt', 'En préparation'],
            'audit.export.status.completed' => ['Pronto', 'Ready', 'Bereit', 'Prêt'],
            'audit.export.status.failed' => ['Fallito', 'Failed', 'Fehlgeschlagen', 'Échoué'],
            'audit.export.download' => ['Scarica il pacchetto', 'Download the package', 'Paket herunterladen', 'Télécharger le paquet'],
            'audit.export.entries' => ['Registrazioni', 'Entries', 'Einträge', 'Entrées'],
            'audit.export.sha256' => ['SHA-256 del pacchetto', 'Package SHA-256', 'SHA-256 des Pakets', 'SHA-256 du paquet'],
            'audit.export.signature' => ['Firma', 'Signature', 'Signatur', 'Signature'],
            'audit.export.verification' => ['Verifica della catena', 'Chain verification', 'Prüfung der Kette', 'Vérification de la chaîne'],
            'audit.export.started' => ['Esportazione avviata', 'Export started', 'Export gestartet', 'Export lancé'],
            'audit.export.error' => ['Esportazione non riuscita', 'The export failed', 'Der Export ist fehlgeschlagen', 'L\'export a échoué'],
            'audit.export.error_range' => [
                'Il periodo non può superare 366 giorni',
                'The range cannot exceed 366 days',
                'Der Zeitraum darf 366 Tage nicht überschreiten',
                'La période ne peut pas dépasser 366 jours',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function history(): array
    {
        return [
            'audit.history.title' => ['Storico', 'History', 'Verlauf', 'Historique'],
            'audit.history.empty' => ['Nessuna modifica registrata', 'No change recorded', 'Keine Änderung erfasst', 'Aucune modification enregistrée'],
            'audit.history.error' => ['Impossibile caricare lo storico', 'The history could not be loaded', 'Der Verlauf konnte nicht geladen werden', 'Impossible de charger l\'historique'],
            'audit.history.forbidden' => ['Non puoi consultare lo storico di questo record', 'You cannot view the history of this record', 'Sie dürfen den Verlauf dieses Datensatzes nicht einsehen', 'Vous ne pouvez pas consulter l\'historique de cet enregistrement'],
            'audit.history.retry' => ['Riprova', 'Try again', 'Erneut versuchen', 'Réessayer'],
            'audit.history.by' => ['da {actor}', 'by {actor}', 'von {actor}', 'par {actor}'],
            'audit.history.via' => ['tramite {channel}', 'via {channel}', 'über {channel}', 'via {channel}'],
            'audit.history.fields' => ['Campi modificati', 'Changed fields', 'Geänderte Felder', 'Champs modifiés'],
            'audit.history.impersonated_by' => ['operatore reale: {actor}', 'real operator: {actor}', 'echter Bediener: {actor}', 'opérateur réel : {actor}'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function actions(): array
    {
        return [
            'audit.actions.CREATED' => ['Creazione', 'Created', 'Erstellt', 'Création'],
            'audit.actions.UPDATED' => ['Modifica', 'Updated', 'Geändert', 'Modification'],
            'audit.actions.DELETED' => ['Eliminazione', 'Deleted', 'Gelöscht', 'Suppression'],
            'audit.actions.RESTORED' => ['Ripristino', 'Restored', 'Wiederhergestellt', 'Restauration'],
            'audit.actions.CANCELLED' => ['Annullamento', 'Cancelled', 'Storniert', 'Annulation'],
            'audit.actions.ISSUED' => ['Emissione', 'Issued', 'Ausgestellt', 'Émission'],
            'audit.actions.SDI_SENT' => ['Invio a SDI', 'Sent to SDI', 'An SDI gesendet', 'Envoi au SDI'],
            'audit.actions.SDI_OUTCOME' => ['Esito SDI', 'SDI outcome', 'SDI-Ergebnis', 'Résultat SDI'],
            'audit.actions.BOOKED' => ['Registrazione contabile', 'Booked', 'Gebucht', 'Comptabilisation'],
            'audit.actions.APPROVED' => ['Approvazione', 'Approved', 'Genehmigt', 'Approbation'],
            'audit.actions.REJECTED' => ['Rifiuto', 'Rejected', 'Abgelehnt', 'Refus'],
            'audit.actions.VIEWED' => ['Consultazione', 'Viewed', 'Eingesehen', 'Consultation'],
            'audit.actions.SENSITIVE_VIEWED' => ['Consultazione di dati sensibili', 'Sensitive data viewed', 'Sensible Daten eingesehen', 'Consultation de données sensibles'],
            'audit.actions.EXPORTED' => ['Esportazione', 'Exported', 'Exportiert', 'Exportation'],
            'audit.actions.DOWNLOADED' => ['Download', 'Downloaded', 'Heruntergeladen', 'Téléchargement'],
            'audit.actions.PRINTED' => ['Stampa', 'Printed', 'Gedruckt', 'Impression'],
            'audit.actions.LOGIN_SUCCESS' => ['Accesso riuscito', 'Login succeeded', 'Anmeldung erfolgreich', 'Connexion réussie'],
            'audit.actions.LOGIN_FAILED' => ['Accesso fallito', 'Login failed', 'Anmeldung fehlgeschlagen', 'Connexion échouée'],
            'audit.actions.LOGIN_RATE_LIMITED' => ['Indirizzo IP temporaneamente bloccato', 'IP address temporarily blocked', 'IP-Adresse vorübergehend gesperrt', 'Adresse IP temporairement bloquée'],
            'audit.actions.LOGOUT' => ['Disconnessione', 'Logout', 'Abmeldung', 'Déconnexion'],
            'audit.actions.TENANT_RESTORED' => ['Ripristino del backup', 'Backup restored', 'Backup wiederhergestellt', 'Restauration de la sauvegarde'],
            'audit.actions.AUDIT_INTEGRITY_VERIFIED' => ['Verifica di integrità', 'Integrity verification', 'Integritätsprüfung', 'Vérification d\'intégrité'],
            'audit.actions.AUDIT_EXPORT_REQUESTED' => ['Esportazione audit richiesta', 'Audit export requested', 'Audit-Export angefordert', 'Export d\'audit demandé'],
            'audit.actions.AUDIT_EXPORT_DOWNLOADED' => ['Esportazione audit scaricata', 'Audit export downloaded', 'Audit-Export heruntergeladen', 'Export d\'audit téléchargé'],
            'audit.actions.AUDIT_SUBJECT_ERASED' => ['Soggetto cancellato dall\'audit', 'Subject erased from the audit', 'Person aus dem Audit gelöscht', 'Personne effacée de l\'audit'],
            'audit.actions.AUDIT_KEY_ROTATED' => ['Rotazione della chiave di audit', 'Audit key rotated', 'Audit-Schlüssel rotiert', 'Rotation de la clé d\'audit'],
            'audit.actions.unknown' => ['Evento', 'Event', 'Ereignis', 'Événement'],
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
