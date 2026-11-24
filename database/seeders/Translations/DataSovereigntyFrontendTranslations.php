<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the data residency, sovereignty and BYOK/HYOK area (Mod 381): the sovereignty dashboard, the key management
 * page with its kill-switch and the cross-border egress guard. Each entry is [it, en, de, fr]; the Italian text serves it-CH
 * and it-IT, the German text de-CH and the French text fr-CH. Placeholders are written {name}.
 */
final class DataSovereigntyFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'DATA_SOVEREIGNTY.NAV' => ['Sovranità dei dati', 'Data sovereignty', 'Datensouveränität', 'Souveraineté des données'],
            'DATA_SOVEREIGNTY.TITLE' => ['Sovranità e residenza dei dati', 'Data sovereignty and residency', 'Datensouveränität und Datenresidenz', 'Souveraineté et résidence des données'],
            'DATA_SOVEREIGNTY.SUBTITLE' => ['Dove risiedono fisicamente i dati, chi detiene le chiavi e le prove per FINMA, IFPDT e Garante', 'Where the data physically lives, who holds the keys and the evidence for FINMA, FDPIC and the Garante', 'Wo die Daten physisch liegen, wer die Schlüssel hält und die Nachweise für FINMA, EDÖB und Garante', 'Où résident physiquement les données, qui détient les clés et les preuves pour la FINMA, le PFPDT et le Garante'],
            'DATA_SOVEREIGNTY.TAB_DASHBOARD' => ['Panoramica', 'Overview', 'Übersicht', 'Vue d’ensemble'],
            'DATA_SOVEREIGNTY.TAB_KEYS' => ['Chiavi BYOK / HYOK', 'BYOK / HYOK keys', 'BYOK-/HYOK-Schlüssel', 'Clés BYOK / HYOK'],
            'DATA_SOVEREIGNTY.TAB_EGRESS' => ['Trasferimenti transfrontalieri', 'Cross-border transfers', 'Grenzüberschreitende Übermittlungen', 'Transferts transfrontaliers'],
            'DATA_SOVEREIGNTY.LOAD_ERROR' => ['Impossibile caricare la situazione di sovranità', 'The sovereignty posture could not be loaded', 'Die Souveränitätslage konnte nicht geladen werden', 'La situation de souveraineté n’a pas pu être chargée'],
            'DATA_SOVEREIGNTY.NO_POLICY' => ['Nessuna politica di residenza configurata', 'No residency policy is configured yet', 'Noch keine Residenzrichtlinie konfiguriert', 'Aucune politique de résidence n’est encore configurée'],
            'DATA_SOVEREIGNTY.LOCKED_BANNER' => ['L’accesso alle chiavi è sospeso: i dati cifrati non sono leggibili', 'Key access is suspended: the encrypted data is unreadable', 'Der Schlüsselzugriff ist gesperrt: die verschlüsselten Daten sind nicht lesbar', 'L’accès aux clés est suspendu : les données chiffrées sont illisibles'],

            'DATA_SOVEREIGNTY.POSTURE' => ['Giurisdizione e conformità', 'Jurisdiction and compliance', 'Rechtsraum und Compliance', 'Juridiction et conformité'],
            'DATA_SOVEREIGNTY.ZONE_CH' => ['Solo Svizzera (nLPD)', 'Switzerland only (nLPD)', 'Nur Schweiz (nDSG)', 'Suisse uniquement (nLPD)'],
            'DATA_SOVEREIGNTY.ZONE_IT' => ['Solo Italia (GDPR)', 'Italy only (GDPR)', 'Nur Italien (DSGVO)', 'Italie uniquement (RGPD)'],
            'DATA_SOVEREIGNTY.ZONE_EEA' => ['SEE e adeguatezza Svizzera', 'EEA plus Swiss adequacy', 'EWR und Schweizer Angemessenheit', 'EEE et adéquation suisse'],
            'DATA_SOVEREIGNTY.DOWNLOAD_PDF' => ['Scarica il certificato di sovranità (PDF)', 'Download sovereignty certificate (PDF)', 'Souveränitätszertifikat herunterladen (PDF)', 'Télécharger le certificat de souveraineté (PDF)'],
            'DATA_SOVEREIGNTY.DOWNLOAD_JSON' => ['Pacchetto verificabile (JSON)', 'Verifiable bundle (JSON)', 'Prüfbares Paket (JSON)', 'Paquet vérifiable (JSON)'],
            'DATA_SOVEREIGNTY.DOWNLOAD_ERROR' => ['Impossibile scaricare il certificato', 'The certificate could not be downloaded', 'Das Zertifikat konnte nicht heruntergeladen werden', 'Le certificat n’a pas pu être téléchargé'],
            'DATA_SOVEREIGNTY.CERTIFICATE_ERROR' => ['Impossibile emettere il certificato', 'The certificate could not be issued', 'Das Zertifikat konnte nicht ausgestellt werden', 'Le certificat n’a pas pu être émis'],
            'DATA_SOVEREIGNTY.VALID_UNTIL' => ['valido fino al', 'valid until', 'gültig bis', 'valable jusqu’au'],

            'DATA_SOVEREIGNTY.MATRIX' => ['Matrice di geo-sharding per entità giuridica', 'Geo-sharding matrix by legal entity', 'Geo-Sharding-Matrix je Rechtseinheit', 'Matrice de géo-partitionnement par entité juridique'],
            'DATA_SOVEREIGNTY.COL_ENTITY' => ['Entità', 'Entity', 'Einheit', 'Entité'],
            'DATA_SOVEREIGNTY.COL_ZONE' => ['Giurisdizione', 'Jurisdiction', 'Rechtsraum', 'Juridiction'],
            'DATA_SOVEREIGNTY.COL_CONSOLIDATION' => ['Consolidamento', 'Consolidation', 'Konsolidierung', 'Consolidation'],
            'DATA_SOVEREIGNTY.TENANT_DEFAULT' => ['Predefinito del tenant', 'Tenant default', 'Mandanten-Standard', 'Défaut du locataire'],
            'DATA_SOVEREIGNTY.AGGREGATES_ONLY' => ['Solo aggregati non personali', 'Non-personal aggregates only', 'Nur nicht-personenbezogene Aggregate', 'Agrégats non personnels uniquement'],
            'DATA_SOVEREIGNTY.NO_CONSOLIDATION' => ['Nessun consolidamento', 'No consolidation', 'Keine Konsolidierung', 'Aucune consolidation'],
            'DATA_SOVEREIGNTY.MAP' => ['Data center sovrani', 'Sovereign data centers', 'Souveräne Rechenzentren', 'Centres de données souverains'],
            'DATA_SOVEREIGNTY.MAP_LABEL' => ['Mappa dei data center in Svizzera e in Italia', 'Map of the data centers in Switzerland and Italy', 'Karte der Rechenzentren in der Schweiz und in Italien', 'Carte des centres de données en Suisse et en Italie'],
            'DATA_SOVEREIGNTY.KEY_HEALTH' => ['Stato delle chiavi', 'Key health', 'Schlüsselzustand', 'État des clés'],

            'DATA_SOVEREIGNTY.SERVICE_POSTGRES_PRIMARY' => ['Database primario', 'Primary database', 'Primäre Datenbank', 'Base de données principale'],
            'DATA_SOVEREIGNTY.SERVICE_POSTGRES_REPLICAS' => ['Repliche database', 'Database replicas', 'Datenbank-Replikate', 'Réplicas de base de données'],
            'DATA_SOVEREIGNTY.SERVICE_S3_DMS' => ['Archivio documenti (DMS)', 'Document storage (DMS)', 'Dokumentenspeicher (DMS)', 'Stockage des documents (GED)'],
            'DATA_SOVEREIGNTY.SERVICE_PGVECTOR' => ['Ricerca vettoriale', 'Vector search', 'Vektorsuche', 'Recherche vectorielle'],
            'DATA_SOVEREIGNTY.SERVICE_AI_INFERENCE' => ['IA / OCR', 'AI / OCR', 'KI / OCR', 'IA / OCR'],
            'DATA_SOVEREIGNTY.SERVICE_BACKUP_VAULT' => ['Backup', 'Backups', 'Backups', 'Sauvegardes'],
            'DATA_SOVEREIGNTY.SERVICE_QUEUE_BROKER' => ['Code di lavoro', 'Job queues', 'Warteschlangen', 'Files de tâches'],

            'DATA_SOVEREIGNTY.KEYS_TITLE' => ['Chiavi gestite dal cliente', 'Customer-managed keys', 'Kundenverwaltete Schlüssel', 'Clés gérées par le client'],
            'DATA_SOVEREIGNTY.KEYS_SUBTITLE' => ['BYOK e HYOK con il vostro HSM o KMS, rotazione e interruttore di emergenza', 'BYOK and HYOK with your own HSM or KMS, rotation and the emergency switch', 'BYOK und HYOK mit Ihrem eigenen HSM oder KMS, Rotation und Notschalter', 'BYOK et HYOK avec votre propre HSM ou KMS, rotation et interrupteur d’urgence'],
            'DATA_SOVEREIGNTY.KEY_STATUS' => ['Stato della chiave', 'Key status', 'Schlüsselstatus', 'État de la clé'],
            'DATA_SOVEREIGNTY.NO_ENVELOPE' => ['Nessuna chiave cliente configurata', 'No customer key is configured', 'Kein Kundenschlüssel konfiguriert', 'Aucune clé client n’est configurée'],
            'DATA_SOVEREIGNTY.KEY_VERSION' => ['Versione della chiave', 'Key version', 'Schlüsselversion', 'Version de la clé'],
            'DATA_SOVEREIGNTY.CACHE_TTL' => ['Durata cache della chiave', 'Key cache duration', 'Cache-Dauer des Schlüssels', 'Durée du cache de la clé'],
            'DATA_SOVEREIGNTY.LAST_ROTATED' => ['Ultima rotazione', 'Last rotation', 'Letzte Rotation', 'Dernière rotation'],
            'DATA_SOVEREIGNTY.NEXT_ROTATION' => ['Prossima rotazione', 'Next rotation', 'Nächste Rotation', 'Prochaine rotation'],
            'DATA_SOVEREIGNTY.ROTATION_DUE' => ['Rotazione scaduta', 'Rotation overdue', 'Rotation überfällig', 'Rotation en retard'],
            'DATA_SOVEREIGNTY.ROTATE_NOW' => ['Ruota la KEK ora', 'Rotate KEK now', 'KEK jetzt rotieren', 'Effectuer la rotation de la KEK'],
            'DATA_SOVEREIGNTY.ROTATED' => ['Chiave ruotata senza ricifrare i dati', 'Key rotated without re-encrypting the data', 'Schlüssel rotiert, ohne die Daten neu zu verschlüsseln', 'Clé renouvelée sans rechiffrer les données'],
            'DATA_SOVEREIGNTY.CONFIGURE_KMS' => ['Configura HSM / KMS esterno', 'Configure external HSM / KMS', 'Externes HSM / KMS konfigurieren', 'Configurer le HSM / KMS externe'],
            'DATA_SOVEREIGNTY.CONFIGURED' => ['Chiave del cliente configurata', 'Customer key configured', 'Kundenschlüssel konfiguriert', 'Clé client configurée'],
            'DATA_SOVEREIGNTY.MODE' => ['Modalità', 'Mode', 'Modus', 'Mode'],
            'DATA_SOVEREIGNTY.MODE_BYOK' => ['BYOK (la vostra chiave)', 'BYOK (bring your own key)', 'BYOK (Ihr eigener Schlüssel)', 'BYOK (votre propre clé)'],
            'DATA_SOVEREIGNTY.MODE_HYOK' => ['HYOK (chiave solo nel vostro HSM)', 'HYOK (hold your own key)', 'HYOK (Schlüssel nur in Ihrem HSM)', 'HYOK (clé uniquement dans votre HSM)'],
            'DATA_SOVEREIGNTY.BYOK_HINT' => ['BYOK: il vostro KMS protegge la chiave dei dati, che resta in memoria per pochi minuti', 'BYOK: your KMS wraps the data key, which stays in memory for a few minutes', 'BYOK: Ihr KMS schützt den Datenschlüssel, der wenige Minuten im Speicher bleibt', 'BYOK : votre KMS protège la clé de données, qui reste en mémoire quelques minutes'],
            'DATA_SOVEREIGNTY.HYOK_HINT' => ['HYOK: nessuna chiave in memoria, ogni operazione interroga il vostro HSM', 'HYOK: no key in memory, every operation asks your HSM', 'HYOK: kein Schlüssel im Speicher, jede Operation fragt Ihr HSM', 'HYOK : aucune clé en mémoire, chaque opération interroge votre HSM'],
            'DATA_SOVEREIGNTY.PROVIDER' => ['Fornitore', 'Provider', 'Anbieter', 'Fournisseur'],
            'DATA_SOVEREIGNTY.KEY_URI' => ['URI o ARN della chiave', 'Key URI or ARN', 'Schlüssel-URI oder -ARN', 'URI ou ARN de la clé'],
            'DATA_SOVEREIGNTY.KMIP_ENDPOINT' => ['Endpoint KMIP', 'KMIP endpoint', 'KMIP-Endpunkt', 'Point de terminaison KMIP'],
            'DATA_SOVEREIGNTY.CREDENTIALS' => ['Credenziali (inviate una sola volta, mai mostrate)', 'Credentials (sent once, never shown)', 'Zugangsdaten (einmalig gesendet, nie angezeigt)', 'Identifiants (envoyés une fois, jamais affichés)'],
            'DATA_SOVEREIGNTY.ROTATION_DAYS' => ['Intervallo di rotazione (giorni)', 'Rotation interval (days)', 'Rotationsintervall (Tage)', 'Intervalle de rotation (jours)'],
            'DATA_SOVEREIGNTY.TEST_HANDSHAKE' => ['Verifica il collegamento con l’HSM', 'Test HSM handshake', 'HSM-Handshake testen', 'Tester la connexion au HSM'],
            'DATA_SOVEREIGNTY.HANDSHAKE_OK' => ['Cifratura e decifratura riuscite nell’HSM', 'Wrap and unwrap succeeded in the HSM', 'Wrap und Unwrap im HSM erfolgreich', 'Chiffrement et déchiffrement réussis dans le HSM'],
            'DATA_SOVEREIGNTY.HANDSHAKE_FAILED' => ['Il collegamento con l’HSM non è riuscito', 'The HSM handshake failed', 'Der HSM-Handshake ist fehlgeschlagen', 'La connexion au HSM a échoué'],
            'DATA_SOVEREIGNTY.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'DATA_SOVEREIGNTY.CANCEL' => ['Annulla', 'Cancel', 'Abbrechen', 'Annuler'],
            'DATA_SOVEREIGNTY.GENERIC_ERROR' => ['Operazione non riuscita', 'The operation failed', 'Der Vorgang ist fehlgeschlagen', 'L’opération a échoué'],

            'DATA_SOVEREIGNTY.KILL_SWITCH' => ['Interruttore di emergenza', 'Emergency kill-switch', 'Notschalter', 'Interrupteur d’urgence'],
            'DATA_SOVEREIGNTY.KILL_SWITCH_WARNING' => ['Sospendendo l’accesso alla chiave tutti i dati cifrati diventano subito illeggibili, anche per gli amministratori dell’infrastruttura', 'Suspending key access makes all encrypted data unreadable at once, even for infrastructure administrators', 'Mit der Sperre des Schlüsselzugriffs sind alle verschlüsselten Daten sofort unlesbar, auch für Infrastruktur-Administratoren', 'La suspension de l’accès à la clé rend aussitôt toutes les données chiffrées illisibles, même pour les administrateurs de l’infrastructure'],
            'DATA_SOVEREIGNTY.SHRED_WARNING' => ['Il crypto-shredding distrugge la chiave dei dati in modo irreversibile: i dati non potranno mai più essere recuperati', 'Crypto-shredding destroys the data key irreversibly: the data can never be recovered', 'Crypto-Shredding zerstört den Datenschlüssel unwiderruflich: die Daten sind nie wieder wiederherstellbar', 'Le crypto-effacement détruit la clé de données de façon irréversible : les données ne pourront jamais être récupérées'],
            'DATA_SOVEREIGNTY.SUSPEND_ACCESS' => ['🔒 Sospendi l’accesso alla chiave (kill-switch)', '🔒 Suspend BYOK key access (kill-switch)', '🔒 Schlüsselzugriff sperren (Kill-Switch)', '🔒 Suspendre l’accès à la clé (kill-switch)'],
            'DATA_SOVEREIGNTY.CRYPTO_SHRED' => ['Crypto-shredding permanente', 'Permanent crypto-shred', 'Dauerhaftes Crypto-Shredding', 'Crypto-effacement permanent'],
            'DATA_SOVEREIGNTY.REASON' => ['Motivo (obbligatorio)', 'Reason (required)', 'Grund (erforderlich)', 'Motif (obligatoire)'],
            'DATA_SOVEREIGNTY.TYPE_PHRASE' => ['Digitare la frase di conferma', 'Type the confirmation phrase', 'Bestätigungsphrase eingeben', 'Saisir la phrase de confirmation'],
            'DATA_SOVEREIGNTY.MFA_CODE' => ['Codice di verifica a due fattori', 'Two-factor verification code', 'Zwei-Faktor-Bestätigungscode', 'Code de vérification à deux facteurs'],
            'DATA_SOVEREIGNTY.CONFIRM_KILL_SWITCH' => ['Conferma', 'Confirm', 'Bestätigen', 'Confirmer'],
            'DATA_SOVEREIGNTY.KEY_LOCKED' => ['Accesso alla chiave sospeso', 'Key access suspended', 'Schlüsselzugriff gesperrt', 'Accès à la clé suspendu'],

            'DATA_SOVEREIGNTY.AUDIT_STREAM' => ['Accessi al KMS in tempo reale', 'Real-time KMS access', 'KMS-Zugriffe in Echtzeit', 'Accès au KMS en temps réel'],
            'DATA_SOVEREIGNTY.FILTER_OPERATION' => ['Filtra per operazione', 'Filter by operation', 'Nach Operation filtern', 'Filtrer par opération'],
            'DATA_SOVEREIGNTY.CHAIN_VALID' => ['Catena SHA-256 integra', 'SHA-256 chain intact', 'SHA-256-Kette intakt', 'Chaîne SHA-256 intacte'],
            'DATA_SOVEREIGNTY.CHAIN_BROKEN' => ['Catena SHA-256 interrotta', 'SHA-256 chain broken', 'SHA-256-Kette unterbrochen', 'Chaîne SHA-256 rompue'],
            'DATA_SOVEREIGNTY.COL_TIME' => ['Data e ora', 'Time', 'Zeitpunkt', 'Date et heure'],
            'DATA_SOVEREIGNTY.COL_OPERATION' => ['Operazione', 'Operation', 'Operation', 'Opération'],
            'DATA_SOVEREIGNTY.COL_OUTCOME' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'DATA_SOVEREIGNTY.COL_LATENCY' => ['Latenza', 'Latency', 'Latenz', 'Latence'],
            'DATA_SOVEREIGNTY.COL_NODE' => ['Nodo', 'Node', 'Knoten', 'Nœud'],

            'DATA_SOVEREIGNTY.EGRESS_TITLE' => ['Trasferimenti transfrontalieri', 'Cross-border transfers', 'Grenzüberschreitende Übermittlungen', 'Transferts transfrontaliers'],
            'DATA_SOVEREIGNTY.EGRESS_SUBTITLE' => ['Elenco di adeguatezza (nLPD allegato 1, GDPR art. 45) e trasferimenti bloccati', 'Adequacy list (nLPD Annex 1, GDPR Art. 45) and blocked transfers', 'Angemessenheitsliste (nDSG Anhang 1, DSGVO Art. 45) und blockierte Übermittlungen', 'Liste d’adéquation (nLPD annexe 1, RGPD art. 45) et transferts bloqués'],
            'DATA_SOVEREIGNTY.BLOCKED_30_DAYS' => ['Trasferimenti bloccati negli ultimi 30 giorni', 'Transfers blocked in the last 30 days', 'In den letzten 30 Tagen blockierte Übermittlungen', 'Transferts bloqués ces 30 derniers jours'],
            'DATA_SOVEREIGNTY.TEST_ENDPOINT' => ['Verifica un endpoint in uscita', 'Test an outbound endpoint', 'Ausgehenden Endpunkt prüfen', 'Tester un point de terminaison sortant'],
            'DATA_SOVEREIGNTY.TARGET_URL' => ['URL di destinazione (webhook, connettore, esportazione)', 'Target URL (webhook, connector, export)', 'Ziel-URL (Webhook, Konnektor, Export)', 'URL cible (webhook, connecteur, export)'],
            'DATA_SOVEREIGNTY.PURPOSE' => ['Finalità', 'Purpose', 'Zweck', 'Finalité'],
            'DATA_SOVEREIGNTY.COUNTRY_OPTIONAL' => ['Paese (facoltativo, ISO a 2 lettere)', 'Country (optional, 2-letter ISO)', 'Land (optional, ISO mit 2 Buchstaben)', 'Pays (facultatif, ISO à 2 lettres)'],
            'DATA_SOVEREIGNTY.RUN_TEST' => ['Verifica', 'Check', 'Prüfen', 'Vérifier'],
            'DATA_SOVEREIGNTY.VERDICT_ALLOWED' => ['Trasferimento consentito', 'Transfer allowed', 'Übermittlung zulässig', 'Transfert autorisé'],
            'DATA_SOVEREIGNTY.VERDICT_BLOCKED' => ['Trasferimento bloccato', 'Transfer blocked', 'Übermittlung blockiert', 'Transfert bloqué'],
            'DATA_SOVEREIGNTY.ADEQUACY' => ['Matrice di adeguatezza', 'Adequacy matrix', 'Angemessenheitsmatrix', 'Matrice d’adéquation'],
            'DATA_SOVEREIGNTY.COL_COUNTRY' => ['Paese', 'Country', 'Land', 'Pays'],
            'DATA_SOVEREIGNTY.COL_CH_ADEQUACY' => ['Adeguatezza svizzera', 'Swiss adequacy', 'Schweizer Angemessenheit', 'Adéquation suisse'],
            'DATA_SOVEREIGNTY.COL_EU_ADEQUACY' => ['Adeguatezza UE', 'EU adequacy', 'EU-Angemessenheit', 'Adéquation UE'],
            'DATA_SOVEREIGNTY.COL_DECISION' => ['Decisione', 'Decision', 'Entscheid', 'Décision'],
            'DATA_SOVEREIGNTY.COL_LEGAL_BASIS' => ['Base giuridica', 'Legal basis', 'Rechtsgrundlage', 'Base juridique'],
            'DATA_SOVEREIGNTY.BLOCKED_ATTEMPTS' => ['Tentativi bloccati', 'Blocked attempts', 'Blockierte Versuche', 'Tentatives bloquées'],
            'DATA_SOVEREIGNTY.COL_TARGET' => ['Destinazione', 'Target', 'Ziel', 'Cible'],
            'DATA_SOVEREIGNTY.COL_REASON' => ['Motivo', 'Reason', 'Grund', 'Motif'],
            'DATA_SOVEREIGNTY.YES' => ['Sì', 'Yes', 'Ja', 'Oui'],
            'DATA_SOVEREIGNTY.NO' => ['No', 'No', 'Nein', 'Non'],

            'DATA_SOVEREIGNTY.DECISION_ALLOWED' => ['Consentito', 'Allowed', 'Zulässig', 'Autorisé'],
            'DATA_SOVEREIGNTY.DECISION_REQUIRES_SCC_TIA' => ['Richiede SCC e TIA', 'Requires SCC and TIA', 'Erfordert SCC und TIA', 'Exige CCT et TIA'],
            'DATA_SOVEREIGNTY.DECISION_BLOCKED' => ['Bloccato', 'Blocked', 'Blockiert', 'Bloqué'],
            'DATA_SOVEREIGNTY.HEALTH_HEALTHY' => ['KMS raggiungibile', 'KMS reachable', 'KMS erreichbar', 'KMS joignable'],
            'DATA_SOVEREIGNTY.HEALTH_DEGRADED' => ['KMS non raggiungibile', 'KMS unreachable', 'KMS nicht erreichbar', 'KMS injoignable'],
            'DATA_SOVEREIGNTY.HEALTH_UNKNOWN' => ['KMS non ancora usato', 'KMS not used yet', 'KMS noch nicht verwendet', 'KMS pas encore utilisé'],
            'DATA_SOVEREIGNTY.STATUS_ACTIVE' => ['Attiva', 'Active', 'Aktiv', 'Active'],
            'DATA_SOVEREIGNTY.STATUS_ROTATING' => ['In rotazione', 'Rotating', 'In Rotation', 'En rotation'],
            'DATA_SOVEREIGNTY.STATUS_SUSPENDED' => ['Sospesa', 'Suspended', 'Gesperrt', 'Suspendue'],
            'DATA_SOVEREIGNTY.STATUS_REVOKED_CRYPTO_SHREDDED' => ['Distrutta (crypto-shredding)', 'Revoked (crypto-shredded)', 'Widerrufen (Crypto-Shredding)', 'Révoquée (crypto-effacée)'],

            'DATA_SOVEREIGNTY.PROVIDER_SECUROSYS_PRIMUS_HSM' => ['Securosys Primus HSM', 'Securosys Primus HSM', 'Securosys Primus HSM', 'Securosys Primus HSM'],
            'DATA_SOVEREIGNTY.PROVIDER_AWS_KMS' => ['AWS KMS', 'AWS KMS', 'AWS KMS', 'AWS KMS'],
            'DATA_SOVEREIGNTY.PROVIDER_AZURE_KEY_VAULT' => ['Azure Key Vault', 'Azure Key Vault', 'Azure Key Vault', 'Azure Key Vault'],
            'DATA_SOVEREIGNTY.PROVIDER_GOOGLE_CLOUD_EKM' => ['Google Cloud EKM', 'Google Cloud EKM', 'Google Cloud EKM', 'Google Cloud EKM'],
            'DATA_SOVEREIGNTY.PROVIDER_HASHICORP_VAULT' => ['HashiCorp Vault', 'HashiCorp Vault', 'HashiCorp Vault', 'HashiCorp Vault'],
            'DATA_SOVEREIGNTY.PROVIDER_KMIP_GENERIC' => ['KMIP generico', 'Generic KMIP', 'Generisches KMIP', 'KMIP générique'],
            'DATA_SOVEREIGNTY.PROVIDER_PLATFORM' => ['Chiavi della piattaforma', 'Platform keys', 'Plattform-Schlüssel', 'Clés de la plateforme'],

            'DATA_SOVEREIGNTY.OP_UNWRAP_DEK' => ['Decifratura della chiave dati', 'Unwrap data key', 'Datenschlüssel entschlüsseln', 'Déchiffrement de la clé de données'],
            'DATA_SOVEREIGNTY.OP_WRAP_DEK' => ['Cifratura della chiave dati', 'Wrap data key', 'Datenschlüssel verschlüsseln', 'Chiffrement de la clé de données'],
            'DATA_SOVEREIGNTY.OP_ROTATE_KEK' => ['Rotazione della KEK', 'KEK rotation', 'KEK-Rotation', 'Rotation de la KEK'],
            'DATA_SOVEREIGNTY.OP_CACHE_HIT' => ['Chiave dalla cache', 'Key from cache', 'Schlüssel aus dem Cache', 'Clé depuis le cache'],
            'DATA_SOVEREIGNTY.OP_KILL_SWITCH_SUSPEND' => ['Kill-switch: sospensione', 'Kill-switch: suspension', 'Kill-Switch: Sperre', 'Kill-switch : suspension'],
            'DATA_SOVEREIGNTY.OP_KILL_SWITCH_SHRED' => ['Kill-switch: crypto-shredding', 'Kill-switch: crypto-shredding', 'Kill-Switch: Crypto-Shredding', 'Kill-switch : crypto-effacement'],
            'DATA_SOVEREIGNTY.OP_KMS_HEALTH_CHECK' => ['Verifica del KMS', 'KMS health check', 'KMS-Prüfung', 'Contrôle du KMS'],
            'DATA_SOVEREIGNTY.OP_ACCESS_DENIED_REVOKED' => ['Accesso negato (chiave revocata)', 'Access denied (key revoked)', 'Zugriff verweigert (Schlüssel widerrufen)', 'Accès refusé (clé révoquée)'],
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
