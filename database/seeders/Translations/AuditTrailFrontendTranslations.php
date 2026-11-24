<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the immutable audit trail (Cybersecurity, Privacy & Audit Trail epic): explorer, diff and proof drawer,
 * entity timeline, forensic certificate and WORM archive. Each entry is [it, en, de, fr].
 */
final class AuditTrailFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.AUDIT_TRAIL' => ['Audit Trail', 'Audit Trail', 'Audit-Trail', 'Piste d\'audit'],
            'PAGE_TITLE.AUDIT_TRAIL' => ['Audit Trail Immutabile', 'Immutable Audit Trail', 'Unveränderlicher Audit-Trail', 'Piste d\'audit immuable'],
            'AUDIT.DETAIL.DIFF' => ['Modifiche campo per campo', 'Field-by-field changes', 'Änderungen Feld für Feld', 'Modifications champ par champ'],
            'AUDIT.TITLE' => ['Audit Trail Immutabile', 'Immutable Audit Trail', 'Unveränderlicher Audit-Trail', 'Piste d\'audit immuable'],
            'AUDIT.SUBTITLE' => [
                'Registro crittograficamente verificabile di chi ha fatto cosa, quando e da dove',
                'Cryptographically verifiable record of who did what, when and from where',
                'Kryptografisch überprüfbares Protokoll darüber, wer was wann und von wo getan hat',
                'Registre vérifiable cryptographiquement de qui a fait quoi, quand et d\'où',
            ],
            'AUDIT.TAB.EXPLORER' => ['Registro eventi', 'Event log', 'Ereignisprotokoll', 'Journal des événements'],
            'AUDIT.TAB.WORM' => ['Archivio WORM', 'WORM archive', 'WORM-Archiv', 'Archive WORM'],

            'AUDIT.EVENT.CREATED' => ['Creazione', 'Created', 'Erstellt', 'Création'],
            'AUDIT.EVENT.UPDATED' => ['Modifica', 'Updated', 'Geändert', 'Modification'],
            'AUDIT.EVENT.DELETED' => ['Eliminazione', 'Deleted', 'Gelöscht', 'Suppression'],
            'AUDIT.EVENT.RESTORED' => ['Ripristino', 'Restored', 'Wiederhergestellt', 'Restauration'],
            'AUDIT.EVENT.VIEWED' => ['Consultazione', 'Viewed', 'Eingesehen', 'Consultation'],
            'AUDIT.EVENT.EXPORTED' => ['Esportazione', 'Exported', 'Exportiert', 'Exportation'],
            'AUDIT.EVENT.LOGIN_SUCCESS' => ['Accesso riuscito', 'Login succeeded', 'Anmeldung erfolgreich', 'Connexion réussie'],
            'AUDIT.EVENT.LOGIN_FAILED' => ['Accesso fallito', 'Login failed', 'Anmeldung fehlgeschlagen', 'Connexion échouée'],
            'AUDIT.EVENT.PERMISSION_CHANGED' => ['Permessi modificati', 'Permissions changed', 'Berechtigungen geändert', 'Permissions modifiées'],
            'AUDIT.EVENT.WORM_SEALED' => ['Sigillo WORM', 'WORM sealed', 'WORM versiegelt', 'Scellé WORM'],
            'AUDIT.EVENT.AUDIT_LOG_INSPECTED' => ['Registro audit ispezionato', 'Audit record inspected', 'Audit-Eintrag geprüft', 'Entrée d\'audit inspectée'],
            'AUDIT.EVENT.SECURITY' => ['Sicurezza', 'Security', 'Sicherheit', 'Sécurité'],

            'AUDIT.COL.SEQUENCE' => ['N°', '#', 'Nr.', 'N°'],
            'AUDIT.COL.EVENT' => ['Evento', 'Event', 'Ereignis', 'Événement'],
            'AUDIT.COL.ENTITY' => ['Entità', 'Entity', 'Entität', 'Entité'],
            'AUDIT.COL.ACTOR' => ['Utente', 'Actor', 'Akteur', 'Acteur'],
            'AUDIT.COL.IP' => ['Indirizzo IP', 'IP address', 'IP-Adresse', 'Adresse IP'],
            'AUDIT.COL.CHANGED_FIELDS' => ['Campi modificati', 'Changed fields', 'Geänderte Felder', 'Champs modifiés'],
            'AUDIT.COL.WORM' => ['WORM', 'WORM', 'WORM', 'WORM'],
            'AUDIT.COL.OCCURRED_AT' => ['Data e ora', 'Timestamp', 'Zeitstempel', 'Horodatage'],

            'AUDIT.FILTER.DATE_RANGE' => ['Periodo', 'Date range', 'Zeitraum', 'Période'],
            'AUDIT.FILTER.EVENT_TYPE' => ['Tipo di evento', 'Event type', 'Ereignistyp', 'Type d\'événement'],
            'AUDIT.FILTER.ENTITY_TYPE' => ['Tipo di entità', 'Entity type', 'Entitätstyp', 'Type d\'entité'],
            'AUDIT.FILTER.ACTOR' => ['ID utente', 'Actor ID', 'Akteur-ID', 'ID de l\'acteur'],
            'AUDIT.FILTER.IP' => ['Indirizzo IP', 'IP address', 'IP-Adresse', 'Adresse IP'],
            'AUDIT.FILTER.ENTITY_ID' => ['ID entità', 'Entity ID', 'Entitäts-ID', 'ID de l\'entité'],
            'AUDIT.FILTER.CHANGED_FIELD' => ['Campo modificato', 'Changed field', 'Geändertes Feld', 'Champ modifié'],
            'AUDIT.FILTER.APPLY' => ['Applica filtri', 'Apply filters', 'Filter anwenden', 'Appliquer les filtres'],
            'AUDIT.FILTER.RESET' => ['Azzera', 'Reset', 'Zurücksetzen', 'Réinitialiser'],

            'AUDIT.INTEGRITY.TITLE' => ['Integrità della catena', 'Chain integrity', 'Integrität der Kette', 'Intégrité de la chaîne'],
            'AUDIT.INTEGRITY.NEVER' => ['Catena non ancora verificata in questa sessione', 'Chain not verified yet in this session', 'Kette in dieser Sitzung noch nicht geprüft', 'Chaîne pas encore vérifiée dans cette session'],
            'AUDIT.INTEGRITY.VALID' => ['Catena integra', 'Chain intact', 'Kette intakt', 'Chaîne intacte'],
            'AUDIT.INTEGRITY.BROKEN' => ['Catena compromessa', 'Chain broken', 'Kette unterbrochen', 'Chaîne rompue'],
            'AUDIT.INTEGRITY.LAST_VERIFIED' => ['Ultima verifica', 'Last verified', 'Zuletzt geprüft', 'Dernière vérification'],
            'AUDIT.INTEGRITY.MERKLE_ROOT' => ['Radice di Merkle', 'Merkle root', 'Merkle-Wurzel', 'Racine de Merkle'],
            'AUDIT.INTEGRITY.VERIFY_NOW' => ['Verifica la catena crittografica ora', 'Verify Cryptographic Chain Now', 'Kryptografische Kette jetzt prüfen', 'Vérifier la chaîne cryptographique maintenant'],
            'AUDIT.INTEGRITY.VERIFYING' => ['Verifica in corso…', 'Verifying…', 'Prüfung läuft…', 'Vérification en cours…'],

            'AUDIT.CERT.TITLE' => ['Certificato di verifica forense', 'Forensic Verification Certificate', 'Forensisches Prüfzertifikat', 'Certificat de vérification forensique'],
            'AUDIT.CERT.STATUS' => ['Esito', 'Result', 'Ergebnis', 'Résultat'],
            'AUDIT.CERT.CHECKED' => ['Record verificati', 'Records checked', 'Geprüfte Einträge', 'Enregistrements vérifiés'],
            'AUDIT.CERT.FIRST_SEQUENCE' => ['Prima sequenza', 'First sequence', 'Erste Sequenz', 'Première séquence'],
            'AUDIT.CERT.LAST_SEQUENCE' => ['Ultima sequenza', 'Last sequence', 'Letzte Sequenz', 'Dernière séquence'],
            'AUDIT.CERT.BROKEN_AT' => ['Interrotta alla sequenza', 'Broken at sequence', 'Unterbrochen bei Sequenz', 'Rompue à la séquence'],
            'AUDIT.CERT.REASON' => ['Motivo', 'Reason', 'Grund', 'Motif'],
            'AUDIT.CERT.VERIFIED_AT' => ['Verificato il', 'Verified at', 'Geprüft am', 'Vérifié le'],
            'AUDIT.CERT.SIGNATURE' => ['Firma del certificato (HMAC-SHA256)', 'Certificate signature (HMAC-SHA256)', 'Zertifikatssignatur (HMAC-SHA256)', 'Signature du certificat (HMAC-SHA256)'],
            'AUDIT.CERT.CLOSE' => ['Chiudi', 'Close', 'Schliessen', 'Fermer'],

            'AUDIT.DETAIL.TITLE' => ['Dettaglio forense del record', 'Forensic record detail', 'Forensische Eintragsdetails', 'Détail forensique de l\'enregistrement'],
            'AUDIT.DETAIL.BEFORE' => ['Prima', 'Before', 'Vorher', 'Avant'],
            'AUDIT.DETAIL.AFTER' => ['Dopo', 'After', 'Nachher', 'Après'],
            'AUDIT.DETAIL.FIELD' => ['Campo', 'Field', 'Feld', 'Champ'],
            'AUDIT.DETAIL.NO_PAYLOAD' => ['Questo evento non contiene valori', 'This event carries no values', 'Dieses Ereignis enthält keine Werte', 'Cet événement ne contient aucune valeur'],
            'AUDIT.DETAIL.ADDED' => ['Aggiunto', 'Added', 'Hinzugefügt', 'Ajouté'],
            'AUDIT.DETAIL.MODIFIED' => ['Modificato', 'Modified', 'Geändert', 'Modifié'],
            'AUDIT.DETAIL.REMOVED' => ['Rimosso', 'Removed', 'Entfernt', 'Supprimé'],
            'AUDIT.DETAIL.INSPECT_NOTICE' => ['L\'apertura di questo record è stata registrata nell\'audit trail.', 'Opening this record was written to the audit trail.', 'Das Öffnen dieses Eintrags wurde im Audit-Trail protokolliert.', 'L\'ouverture de cet enregistrement a été consignée dans la piste d\'audit.'],
            'AUDIT.PROOF.TITLE' => ['Prova crittografica', 'Cryptographic proof', 'Kryptografischer Nachweis', 'Preuve cryptographique'],
            'AUDIT.PROOF.SEQUENCE' => ['Sequenza n.', 'Sequence #', 'Sequenz Nr.', 'Séquence n°'],
            'AUDIT.PROOF.PREVIOUS_HASH' => ['Hash precedente', 'Previous hash', 'Vorheriger Hash', 'Hash précédent'],
            'AUDIT.PROOF.CURRENT_HASH' => ['Hash SHA-256 corrente', 'Current SHA-256 hash', 'Aktueller SHA-256-Hash', 'Hash SHA-256 actuel'],
            'AUDIT.PROOF.HMAC' => ['Firma HMAC', 'HMAC signature', 'HMAC-Signatur', 'Signature HMAC'],
            'AUDIT.PROOF.REQUEST_ID' => ['ID di correlazione della richiesta', 'Request correlation ID', 'Korrelations-ID der Anfrage', 'ID de corrélation de la requête'],
            'AUDIT.PROOF.USER_AGENT' => ['User agent', 'User agent', 'User-Agent', 'User agent'],
            'AUDIT.PROOF.WORM_BATCH' => ['Lotto di archiviazione WORM', 'WORM storage batch', 'WORM-Speicherbatch', 'Lot de stockage WORM'],
            'AUDIT.PROOF.NOT_SEALED' => ['Non ancora sigillato nell\'archivio WORM', 'Not sealed in the WORM archive yet', 'Noch nicht im WORM-Archiv versiegelt', 'Pas encore scellé dans l\'archive WORM'],
            'AUDIT.PROOF.COPY' => ['Copia', 'Copy', 'Kopieren', 'Copier'],
            'AUDIT.PROOF.COPIED' => ['Copiato negli appunti', 'Copied to clipboard', 'In die Zwischenablage kopiert', 'Copié dans le presse-papiers'],

            'AUDIT.TIMELINE.TITLE' => ['Cronologia audit', 'Audit history', 'Audit-Verlauf', 'Historique d\'audit'],
            'AUDIT.TIMELINE.EMPTY' => ['Nessun evento registrato per questo record', 'No events recorded for this record', 'Keine Ereignisse für diesen Datensatz erfasst', 'Aucun événement enregistré pour cet enregistrement'],
            'AUDIT.TIMELINE.OPEN' => ['Apri dettaglio', 'Open detail', 'Details öffnen', 'Ouvrir le détail'],

            'AUDIT.WORM.TITLE' => ['Archivio WORM e conservazione', 'WORM storage & retention', 'WORM-Speicher und Aufbewahrung', 'Stockage WORM et conservation'],
            'AUDIT.WORM.SUBTITLE' => ['Lotti sigillati su storage Object Lock (Write Once, Read Many)', 'Batches sealed on Object Lock storage (Write Once, Read Many)', 'Auf Object-Lock-Speicher versiegelte Batches (Write Once, Read Many)', 'Lots scellés sur stockage Object Lock (Write Once, Read Many)'],
            'AUDIT.WORM.FROM' => ['Da sequenza', 'From sequence', 'Ab Sequenz', 'De la séquence'],
            'AUDIT.WORM.TO' => ['A sequenza', 'To sequence', 'Bis Sequenz', 'À la séquence'],
            'AUDIT.WORM.RECORDS' => ['Record', 'Records', 'Einträge', 'Enregistrements'],
            'AUDIT.WORM.MERKLE' => ['Radice di Merkle', 'Merkle root', 'Merkle-Wurzel', 'Racine de Merkle'],
            'AUDIT.WORM.STORAGE' => ['Percorso di archiviazione', 'Storage path', 'Speicherpfad', 'Chemin de stockage'],
            'AUDIT.WORM.RETENTION' => ['Blocco fino al', 'Locked until', 'Gesperrt bis', 'Verrouillé jusqu\'au'],
            'AUDIT.WORM.SEALED_AT' => ['Sigillato il', 'Sealed at', 'Versiegelt am', 'Scellé le'],
            'AUDIT.WORM.SEAL_NOW' => ['Sigilla ora', 'Seal now', 'Jetzt versiegeln', 'Sceller maintenant'],
            'AUDIT.WORM.SEAL_CONFIRM' => ['Sigillare i record non ancora archiviati nello storage WORM? L\'operazione non è reversibile.', 'Seal the records not archived yet into the WORM storage? This cannot be undone.', 'Noch nicht archivierte Einträge im WORM-Speicher versiegeln? Dies kann nicht rückgängig gemacht werden.', 'Sceller les enregistrements pas encore archivés dans le stockage WORM ? Cette action est irréversible.'],
            'AUDIT.WORM.SEALED_OK' => ['Record sigillati nell\'archivio WORM', 'Records sealed into the WORM archive', 'Einträge im WORM-Archiv versiegelt', 'Enregistrements scellés dans l\'archive WORM'],
            'AUDIT.WORM.NOTHING' => ['Niente da sigillare: tutto è già archiviato', 'Nothing to seal: everything is already archived', 'Nichts zu versiegeln: alles ist bereits archiviert', 'Rien à sceller : tout est déjà archivé'],
            'AUDIT.WORM.EMPTY' => ['Nessun lotto sigillato', 'No sealed batches', 'Keine versiegelten Batches', 'Aucun lot scellé'],

            'AUDIT.EXPORT.BUTTON' => ['Esporta bundle forense', 'Export forensic bundle', 'Forensisches Bundle exportieren', 'Exporter le bundle forensique'],
            'AUDIT.ERROR.FORBIDDEN' => ['Non hai il permesso di consultare l\'audit trail', 'You are not allowed to view the audit trail', 'Sie dürfen den Audit-Trail nicht einsehen', 'Vous n\'êtes pas autorisé à consulter la piste d\'audit'],
            'AUDIT.ERROR.LOAD' => ['Impossibile caricare i dati di audit', 'The audit data could not be loaded', 'Die Audit-Daten konnten nicht geladen werden', 'Les données d\'audit n\'ont pas pu être chargées'],
            'AUDIT.ERROR.ACTION' => ['Operazione non riuscita', 'The operation failed', 'Der Vorgang ist fehlgeschlagen', 'L\'opération a échoué'],
            'AUDIT.EMPTY' => ['Nessun evento corrisponde ai filtri', 'No events match the filters', 'Keine Ereignisse entsprechen den Filtern', 'Aucun événement ne correspond aux filtres'],
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
