<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the enterprise authentication screens: the security audit viewer (filters, columns, every event type),
 * the SCIM groups, group to role mappings and provisioning log panel, the lockout (unlock button, locked badge,
 * progressive lockout settings) and the password rules (breached password setting, strength meter, rule labels).
 * Each entry is [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text
 * fr-CH. Placeholders are written {name}. Keys live under `security.audit.*`, `security.scim.groups.*`,
 * `security.lockout.*` and `security.password.*`; the label of an event type is
 * `security.audit.event.<type in lower case, dots as underscores>`.
 */
final class AdvancedAuthEnterpriseFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'security.audit.title' => ['Registro di audit della sicurezza', 'Security audit log', 'Sicherheits-Auditprotokoll', 'Journal d’audit de sécurité'],
            'security.audit.subtitle' => ['Accessi, password, secondi fattori, sessioni, blocchi, single sign-on e SCIM della tua organizzazione.', 'Sign-ins, passwords, second factors, sessions, lockouts, single sign-on and SCIM of your organization.', 'Anmeldungen, Passwörter, zweite Faktoren, Sitzungen, Sperren, Single Sign-on und SCIM Ihrer Organisation.', 'Connexions, mots de passe, seconds facteurs, sessions, verrouillages, authentification unique et SCIM de votre organisation.'],
            'security.audit.load_error' => ['Impossibile caricare il registro di audit', 'The audit log could not be loaded', 'Das Auditprotokoll konnte nicht geladen werden', 'Le journal d’audit n’a pas pu être chargé'],
            'security.audit.empty' => ['Nessun evento corrisponde ai filtri', 'No events match the filters', 'Keine Ereignisse entsprechen den Filtern', 'Aucun événement ne correspond aux filtres'],
            'security.audit.filters.from' => ['Dal', 'From', 'Von', 'Du'],
            'security.audit.filters.to' => ['Al', 'To', 'Bis', 'Au'],
            'security.audit.filters.user' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'security.audit.filters.event_type' => ['Tipo di evento', 'Event type', 'Ereignistyp', 'Type d’événement'],
            'security.audit.filters.status' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'security.audit.filters.auth_method' => ['Metodo di accesso', 'Sign-in method', 'Anmeldemethode', 'Méthode de connexion'],
            'security.audit.filters.all' => ['Tutti', 'All', 'Alle', 'Tous'],
            'security.audit.filters.apply' => ['Applica filtri', 'Apply filters', 'Filter anwenden', 'Appliquer les filtres'],
            'security.audit.filters.reset' => ['Reimposta', 'Reset', 'Zurücksetzen', 'Réinitialiser'],
            'security.audit.columns.time' => ['Data e ora', 'Date and time', 'Datum und Uhrzeit', 'Date et heure'],
            'security.audit.columns.user' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'security.audit.columns.event' => ['Evento', 'Event', 'Ereignis', 'Événement'],
            'security.audit.columns.status' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'security.audit.columns.ip_address' => ['Indirizzo IP', 'IP address', 'IP-Adresse', 'Adresse IP'],
            'security.audit.columns.user_agent' => ['Dispositivo', 'Device', 'Gerät', 'Appareil'],
            'security.audit.columns.auth_method' => ['Metodo', 'Method', 'Methode', 'Méthode'],
            'security.audit.columns.mfa_method' => ['Secondo fattore', 'Second factor', 'Zweiter Faktor', 'Second facteur'],
            'security.audit.columns.reason' => ['Motivo', 'Reason', 'Grund', 'Motif'],
            'security.audit.unknown_user' => ['Utente sconosciuto', 'Unknown user', 'Unbekannter Benutzer', 'Utilisateur inconnu'],
            'security.audit.status.success' => ['Riuscito', 'Success', 'Erfolgreich', 'Réussi'],
            'security.audit.status.failure' => ['Non riuscito', 'Failure', 'Fehlgeschlagen', 'Échec'],
            'security.audit.auth_method.password' => ['Password', 'Password', 'Passwort', 'Mot de passe'],
            'security.audit.auth_method.sso' => ['Single sign-on', 'Single sign-on', 'Single Sign-on', 'Authentification unique'],
            'security.audit.auth_method.sso_saml' => ['SSO SAML', 'SAML SSO', 'SAML-SSO', 'SSO SAML'],
            'security.audit.auth_method.sso_oidc' => ['SSO OpenID Connect', 'OpenID Connect SSO', 'OpenID-Connect-SSO', 'SSO OpenID Connect'],
            'security.audit.auth_method.passkey' => ['Passkey', 'Passkey', 'Passkey', 'Passkey'],
            'security.audit.mfa_method.totp' => ['App di autenticazione', 'Authenticator app', 'Authenticator-App', 'Application d’authentification'],
            'security.audit.mfa_method.email' => ['Codice via email', 'Email code', 'E-Mail-Code', 'Code par e-mail'],
            'security.audit.mfa_method.email_otp' => ['Codice via email', 'Email code', 'E-Mail-Code', 'Code par e-mail'],
            'security.audit.mfa_method.sms' => ['Codice via SMS', 'SMS code', 'SMS-Code', 'Code par SMS'],
            'security.audit.mfa_method.webauthn' => ['Chiave di sicurezza', 'Security key', 'Sicherheitsschlüssel', 'Clé de sécurité'],
            'security.audit.mfa_method.recovery_code' => ['Codice di recupero', 'Recovery code', 'Wiederherstellungscode', 'Code de récupération'],
            'security.audit.category.login' => ['Accessi', 'Sign-ins', 'Anmeldungen', 'Connexions'],
            'security.audit.category.password' => ['Password', 'Passwords', 'Passwörter', 'Mots de passe'],
            'security.audit.category.mfa' => ['Secondo fattore', 'Second factor', 'Zweiter Faktor', 'Second facteur'],
            'security.audit.category.passkey' => ['Passkey', 'Passkeys', 'Passkeys', 'Passkeys'],
            'security.audit.category.session' => ['Sessioni', 'Sessions', 'Sitzungen', 'Sessions'],
            'security.audit.category.lockout' => ['Blocchi', 'Lockouts', 'Sperren', 'Verrouillages'],
            'security.audit.category.sso' => ['Single sign-on', 'Single sign-on', 'Single Sign-on', 'Authentification unique'],
            'security.audit.category.scim' => ['SCIM', 'SCIM', 'SCIM', 'SCIM'],
            'security.audit.category.policy' => ['Criteri di sicurezza', 'Security policy', 'Sicherheitsrichtlinie', 'Politique de sécurité'],
            'security.audit.category.registration' => ['Registrazione e accesso', 'Registration and access', 'Registrierung und Zugang', 'Inscription et accès'],
            'security.audit.category.access' => ['Ruoli', 'Roles', 'Rollen', 'Rôles'],
            'security.audit.category.impersonation' => ['Impersonificazione', 'Impersonation', 'Identitätswechsel', 'Usurpation d’identité'],
            'security.audit.category.other' => ['Altro', 'Other', 'Sonstiges', 'Autre'],

            'security.scim.groups.title' => ['Gruppi SCIM', 'SCIM groups', 'SCIM-Gruppen', 'Groupes SCIM'],
            'security.scim.groups.subtitle' => ['Collega i gruppi del tuo identity provider ai ruoli dell’organizzazione.', 'Link the groups of your identity provider to roles of the organization.', 'Verknüpfen Sie die Gruppen Ihres Identity Providers mit Rollen der Organisation.', 'Associez les groupes de votre fournisseur d’identité aux rôles de l’organisation.'],
            'security.scim.groups.mappings.title' => ['Mappatura gruppi-ruoli', 'Group to role mappings', 'Gruppen-Rollen-Zuordnung', 'Correspondance groupes-rôles'],
            'security.scim.groups.mappings.help' => ['I membri di un gruppo ricevono il ruolo associato. Il proprietario non viene mai assegnato né modificato.', 'Members of a group get the mapped role. The owner is never granted or changed.', 'Mitglieder einer Gruppe erhalten die zugeordnete Rolle. Der Eigentümer wird nie vergeben oder geändert.', 'Les membres d’un groupe reçoivent le rôle associé. Le propriétaire n’est jamais attribué ni modifié.'],
            'security.scim.groups.mappings.group_name' => ['Nome del gruppo', 'Group name', 'Gruppenname', 'Nom du groupe'],
            'security.scim.groups.mappings.role' => ['Ruolo', 'Role', 'Rolle', 'Rôle'],
            'security.scim.groups.mappings.members' => ['Membri', 'Members', 'Mitglieder', 'Membres'],
            'security.scim.groups.mappings.not_synced' => ['Non ancora inviato dall’identity provider', 'Not pushed by the identity provider yet', 'Vom Identity Provider noch nicht übertragen', 'Pas encore transmis par le fournisseur d’identité'],
            'security.scim.groups.mappings.add' => ['Aggiungi mappatura', 'Add mapping', 'Zuordnung hinzufügen', 'Ajouter une correspondance'],
            'security.scim.groups.mappings.save' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'security.scim.groups.mappings.delete' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'security.scim.groups.mappings.delete_confirm' => ['Eliminare la mappatura di “{group}”? I membri perdono il ruolo che concedeva.', 'Delete the mapping of “{group}”? Members lose the role it granted.', 'Zuordnung von „{group}“ löschen? Mitglieder verlieren die damit vergebene Rolle.', 'Supprimer la correspondance de « {group} » ? Les membres perdent le rôle qu’elle accordait.'],
            'security.scim.groups.mappings.saved' => ['Mappatura salvata', 'Mapping saved', 'Zuordnung gespeichert', 'Correspondance enregistrée'],
            'security.scim.groups.mappings.removed' => ['Mappatura eliminata', 'Mapping removed', 'Zuordnung entfernt', 'Correspondance supprimée'],
            'security.scim.groups.mappings.empty' => ['Nessuna mappatura configurata', 'No mappings configured', 'Keine Zuordnungen konfiguriert', 'Aucune correspondance configurée'],
            'security.scim.groups.mappings.load_error' => ['Impossibile caricare le mappature', 'The mappings could not be loaded', 'Die Zuordnungen konnten nicht geladen werden', 'Les correspondances n’ont pas pu être chargées'],
            'security.scim.groups.logs.title' => ['Registro di provisioning', 'Provisioning log', 'Provisioning-Protokoll', 'Journal de provisionnement'],
            'security.scim.groups.logs.empty' => ['Nessuna operazione SCIM registrata', 'No SCIM operations logged', 'Keine SCIM-Vorgänge protokolliert', 'Aucune opération SCIM enregistrée'],
            'security.scim.groups.logs.load_error' => ['Impossibile caricare il registro di provisioning', 'The provisioning log could not be loaded', 'Das Provisioning-Protokoll konnte nicht geladen werden', 'Le journal de provisionnement n’a pas pu être chargé'],
            'security.scim.groups.logs.time' => ['Data e ora', 'Date and time', 'Datum und Uhrzeit', 'Date et heure'],
            'security.scim.groups.logs.resource' => ['Risorsa', 'Resource', 'Ressource', 'Ressource'],
            'security.scim.groups.logs.operation' => ['Operazione', 'Operation', 'Vorgang', 'Opération'],
            'security.scim.groups.logs.status' => ['Esito', 'Outcome', 'Ergebnis', 'Résultat'],
            'security.scim.groups.logs.name' => ['Utente o gruppo', 'User or group', 'Benutzer oder Gruppe', 'Utilisateur ou groupe'],
            'security.scim.groups.logs.error' => ['Errore', 'Error', 'Fehler', 'Erreur'],
            'security.scim.groups.logs.resource_user' => ['Utente', 'User', 'Benutzer', 'Utilisateur'],
            'security.scim.groups.logs.resource_group' => ['Gruppo', 'Group', 'Gruppe', 'Groupe'],
            'security.scim.groups.logs.operation_create' => ['Creazione', 'Create', 'Erstellen', 'Création'],
            'security.scim.groups.logs.operation_replace' => ['Sostituzione', 'Replace', 'Ersetzen', 'Remplacement'],
            'security.scim.groups.logs.operation_patch' => ['Modifica', 'Update', 'Ändern', 'Modification'],
            'security.scim.groups.logs.operation_delete' => ['Eliminazione', 'Delete', 'Löschen', 'Suppression'],
            'security.scim.groups.logs.status_success' => ['Riuscita', 'Success', 'Erfolgreich', 'Réussie'],
            'security.scim.groups.logs.status_failure' => ['Non riuscita', 'Failure', 'Fehlgeschlagen', 'Échec'],
            'security.scim.groups.sync.title' => ['Stato della sincronizzazione', 'Sync status', 'Synchronisationsstatus', 'État de la synchronisation'],
            'security.scim.groups.sync.last_sync' => ['Ultima sincronizzazione', 'Last sync', 'Letzte Synchronisation', 'Dernière synchronisation'],
            'security.scim.groups.sync.never' => ['Mai', 'Never', 'Nie', 'Jamais'],
            'security.scim.groups.sync.users' => ['Utenti sincronizzati', 'Users provisioned', 'Bereitgestellte Benutzer', 'Utilisateurs provisionnés'],
            'security.scim.groups.sync.groups' => ['Gruppi sincronizzati', 'Groups provisioned', 'Bereitgestellte Gruppen', 'Groupes provisionnés'],
            'security.scim.groups.sync.last_error' => ['Ultimo errore', 'Last error', 'Letzter Fehler', 'Dernière erreur'],
            'security.scim.groups.sync.no_error' => ['Nessun errore', 'No errors', 'Keine Fehler', 'Aucune erreur'],

            'security.lockout.unlock' => ['Sblocca account', 'Unlock account', 'Konto entsperren', 'Déverrouiller le compte'],
            'security.lockout.unlock_title' => ['Sbloccare questo account?', 'Unlock this account?', 'Dieses Konto entsperren?', 'Déverrouiller ce compte ?'],
            'security.lockout.unlock_body' => ['{name} potrà accedere di nuovo subito e riceverà una email di conferma.', '{name} will be able to sign in again right away and will get a confirmation email.', '{name} kann sich sofort wieder anmelden und erhält eine Bestätigungs-E-Mail.', '{name} pourra se reconnecter immédiatement et recevra un e-mail de confirmation.'],
            'security.lockout.unlock_confirm' => ['Sblocca', 'Unlock', 'Entsperren', 'Déverrouiller'],
            'security.lockout.unlock_success' => ['Account sbloccato', 'Account unlocked', 'Konto entsperrt', 'Compte déverrouillé'],
            'security.lockout.unlock_error' => ['Impossibile sbloccare l’account', 'The account could not be unlocked', 'Das Konto konnte nicht entsperrt werden', 'Le compte n’a pas pu être déverrouillé'],
            'security.lockout.locked_badge' => ['Bloccato', 'Locked', 'Gesperrt', 'Verrouillé'],
            'security.lockout.locked_until' => ['Bloccato fino alle {time}', 'Locked until {time}', 'Gesperrt bis {time}', 'Verrouillé jusqu’à {time}'],
            'security.lockout.failed_attempts' => ['{count} tentativi falliti', '{count} failed attempts', '{count} fehlgeschlagene Versuche', '{count} tentatives échouées'],
            'security.sessions.revoke_user' => ['Revoca le sessioni', 'Revoke sessions', 'Sitzungen widerrufen', 'Révoquer les sessions'],
            'security.sessions.revoke_user_confirm' => ['Revocare tutte le sessioni di {name}? Verrà disconnesso da ogni browser e da ogni token API.', 'Revoke all sessions of {name}? They will be signed out of every browser and API token.', 'Alle Sitzungen von {name} widerrufen? Alle Browser-Sitzungen und API-Token werden beendet.', 'Révoquer toutes les sessions de {name} ? Cette personne sera déconnectée de chaque navigateur et de chaque jeton API.'],
            'security.sessions.revoked_user' => ['Revocate {sessions} sessioni e {tokens} token', '{sessions} sessions and {tokens} tokens revoked', '{sessions} Sitzungen und {tokens} Token widerrufen', '{sessions} sessions et {tokens} jetons révoqués'],

            'security.lockout.backoff.title' => ['Blocco progressivo', 'Progressive lockout', 'Progressive Sperre', 'Verrouillage progressif'],
            'security.lockout.backoff.help' => ['Ogni blocco senza un accesso riuscito nel mezzo raddoppia la durata del successivo, fino al massimo indicato.', 'Each lockout without a successful sign-in in between doubles the next lock, up to the maximum set.', 'Jede Sperre ohne zwischenzeitliche erfolgreiche Anmeldung verdoppelt die nächste Sperre bis zum festgelegten Maximum.', 'Chaque verrouillage sans connexion réussie entre-temps double le suivant, jusqu’au maximum défini.'],
            'security.lockout.backoff.enabled' => ['Raddoppia la durata a ogni blocco consecutivo', 'Double the duration at every consecutive lockout', 'Dauer bei jeder aufeinanderfolgenden Sperre verdoppeln', 'Doubler la durée à chaque verrouillage consécutif'],
            'security.lockout.backoff.max_minutes' => ['Durata massima del blocco (minuti)', 'Maximum lock duration (minutes)', 'Maximale Sperrdauer (Minuten)', 'Durée maximale du verrouillage (minutes)'],
            'security.lockout.backoff.max_minutes_help' => ['Non può essere inferiore alla durata base del blocco.', 'It cannot be shorter than the base lock duration.', 'Sie darf nicht kürzer sein als die Basissperrdauer.', 'Elle ne peut pas être inférieure à la durée de base.'],

            'security.password.breached.title' => ['Password compromesse', 'Compromised passwords', 'Kompromittierte Passwörter', 'Mots de passe compromis'],
            'security.password.breached.enabled' => ['Rifiuta le password presenti in violazioni di dati note', 'Refuse passwords found in known data breaches', 'Passwörter aus bekannten Datenlecks ablehnen', 'Refuser les mots de passe présents dans des fuites de données connues'],
            'security.password.breached.help' => ['Il controllo usa Have I Been Pwned: viene inviato solo un frammento dell’impronta, mai la password. Se il servizio non risponde, la password è accettata.', 'The check uses Have I Been Pwned: only a fragment of the hash is sent, never the password. If the service is unreachable, the password is accepted.', 'Die Prüfung nutzt Have I Been Pwned: Es wird nur ein Teil des Hashs gesendet, nie das Passwort. Ist der Dienst nicht erreichbar, wird das Passwort akzeptiert.', 'Le contrôle utilise Have I Been Pwned : seul un fragment de l’empreinte est envoyé, jamais le mot de passe. Si le service est injoignable, le mot de passe est accepté.'],
            'security.password.strength.label' => ['Robustezza della password', 'Password strength', 'Passwortstärke', 'Robustesse du mot de passe'],
            'security.password.strength.0' => ['Vuota', 'Empty', 'Leer', 'Vide'],
            'security.password.strength.1' => ['Debole', 'Weak', 'Schwach', 'Faible'],
            'security.password.strength.2' => ['Discreta', 'Fair', 'Mittel', 'Moyenne'],
            'security.password.strength.3' => ['Buona', 'Good', 'Gut', 'Bonne'],
            'security.password.strength.4' => ['Ottima', 'Strong', 'Stark', 'Excellente'],
            'security.password.rules.title' => ['La password deve rispettare queste regole', 'The password must meet these rules', 'Das Passwort muss diese Regeln erfüllen', 'Le mot de passe doit respecter ces règles'],
            'security.password.rules.min_length' => ['Almeno {min} caratteri', 'At least {min} characters', 'Mindestens {min} Zeichen', 'Au moins {min} caractères'],
            'security.password.rules.mixed_case' => ['Lettere maiuscole e minuscole', 'Uppercase and lowercase letters', 'Gross- und Kleinbuchstaben', 'Majuscules et minuscules'],
            'security.password.rules.numbers' => ['Almeno un numero', 'At least one number', 'Mindestens eine Zahl', 'Au moins un chiffre'],
            'security.password.rules.symbols' => ['Almeno un simbolo', 'At least one symbol', 'Mindestens ein Sonderzeichen', 'Au moins un symbole'],
            'security.password.rules.reused' => ['Diversa dalle ultime {count} password', 'Different from the last {count} passwords', 'Anders als die letzten {count} Passwörter', 'Différent des {count} derniers mots de passe'],
            'security.password.rules.breached' => ['Non presente in violazioni di dati note', 'Not found in known data breaches', 'Nicht in bekannten Datenlecks enthalten', 'Absent des fuites de données connues'],
            'security.password.rules.checking' => ['Verifica in corso…', 'Checking…', 'Wird geprüft …', 'Vérification en cours…'],
        ] + self::eventLabels();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function eventLabels(): array
    {
        $labels = [
            'user_registered' => ['Registrazione utente', 'User registered', 'Benutzer registriert', 'Utilisateur inscrit'],
            'email_verification_sent' => ['Email di verifica inviata', 'Verification email sent', 'Bestätigungs-E-Mail gesendet', 'E-mail de vérification envoyé'],
            'email_verification_resent' => ['Email di verifica reinviata', 'Verification email resent', 'Bestätigungs-E-Mail erneut gesendet', 'E-mail de vérification renvoyé'],
            'email_verified' => ['Email verificata', 'Email verified', 'E-Mail bestätigt', 'E-mail vérifié'],
            'email_verification_token_invalid' => ['Token di verifica email non valido', 'Invalid email verification token', 'Ungültiges E-Mail-Bestätigungstoken', 'Jeton de vérification d’e-mail invalide'],
            'email_verification_token_expired' => ['Token di verifica email scaduto', 'Expired email verification token', 'Abgelaufenes E-Mail-Bestätigungstoken', 'Jeton de vérification d’e-mail expiré'],
            'tenant_access_approved' => ['Accesso all’organizzazione approvato', 'Organization access approved', 'Zugang zur Organisation genehmigt', 'Accès à l’organisation approuvé'],
            'tenant_access_moved' => ['Accesso spostato in un’altra organizzazione', 'Access moved to another organization', 'Zugang in eine andere Organisation verschoben', 'Accès déplacé vers une autre organisation'],
            'tenant_access_rejected' => ['Accesso all’organizzazione rifiutato', 'Organization access rejected', 'Zugang zur Organisation abgelehnt', 'Accès à l’organisation refusé'],
            'password_reset_requested' => ['Reimpostazione password richiesta', 'Password reset requested', 'Passwort-Zurücksetzung angefordert', 'Réinitialisation du mot de passe demandée'],
            'password_reset_sent' => ['Email di reimpostazione inviata', 'Password reset email sent', 'E-Mail zum Zurücksetzen gesendet', 'E-mail de réinitialisation envoyé'],
            'password_reset_resent' => ['Email di reimpostazione reinviata', 'Password reset email resent', 'E-Mail zum Zurücksetzen erneut gesendet', 'E-mail de réinitialisation renvoyé'],
            'password_reset_link_opened' => ['Link di reimpostazione aperto', 'Password reset link opened', 'Link zum Zurücksetzen geöffnet', 'Lien de réinitialisation ouvert'],
            'password_reset_completed' => ['Password reimpostata', 'Password reset completed', 'Passwort zurückgesetzt', 'Mot de passe réinitialisé'],
            'password_reset_token_invalid' => ['Token di reimpostazione non valido', 'Invalid password reset token', 'Ungültiges Token zum Zurücksetzen', 'Jeton de réinitialisation invalide'],
            'password_reset_token_expired' => ['Token di reimpostazione scaduto', 'Expired password reset token', 'Abgelaufenes Token zum Zurücksetzen', 'Jeton de réinitialisation expiré'],
            'password_reset_attempts_exceeded' => ['Troppi tentativi di reimpostazione', 'Too many password reset attempts', 'Zu viele Versuche zum Zurücksetzen', 'Trop de tentatives de réinitialisation'],
            'password_reset_sessions_revoked' => ['Sessioni revocate dopo la reimpostazione', 'Sessions revoked after password reset', 'Sitzungen nach dem Zurücksetzen widerrufen', 'Sessions révoquées après la réinitialisation'],
            'password_change_notified' => ['Notifica di cambio password inviata', 'Password change notification sent', 'Benachrichtigung über Passwortänderung gesendet', 'Notification de changement de mot de passe envoyée'],
            'password_changed' => ['Password cambiata', 'Password changed', 'Passwort geändert', 'Mot de passe modifié'],
            'password_change_failed' => ['Cambio password non riuscito', 'Password change failed', 'Passwortänderung fehlgeschlagen', 'Échec du changement de mot de passe'],
            'password_change_attempts_exceeded' => ['Troppi tentativi di cambio password', 'Too many password change attempts', 'Zu viele Versuche zur Passwortänderung', 'Trop de tentatives de changement de mot de passe'],
            'two_factor_setup_started' => ['Configurazione del secondo fattore avviata', 'Second factor setup started', 'Einrichtung des zweiten Faktors gestartet', 'Configuration du second facteur démarrée'],
            'two_factor_enabled' => ['Secondo fattore attivato', 'Second factor enabled', 'Zweiter Faktor aktiviert', 'Second facteur activé'],
            'two_factor_disabled' => ['Secondo fattore disattivato', 'Second factor disabled', 'Zweiter Faktor deaktiviert', 'Second facteur désactivé'],
            'two_factor_reset_by_admin' => ['Secondo fattore reimpostato da un amministratore', 'Second factor reset by an administrator', 'Zweiter Faktor von einem Administrator zurückgesetzt', 'Second facteur réinitialisé par un administrateur'],
            'two_factor_recovery_codes_regenerated' => ['Codici di recupero rigenerati', 'Recovery codes regenerated', 'Wiederherstellungscodes neu erzeugt', 'Codes de récupération régénérés'],
            'two_factor_recovery_code_used' => ['Codice di recupero usato', 'Recovery code used', 'Wiederherstellungscode verwendet', 'Code de récupération utilisé'],
            'two_factor_challenge_failed' => ['Verifica del secondo fattore non riuscita', 'Second factor challenge failed', 'Prüfung des zweiten Faktors fehlgeschlagen', 'Échec de la vérification du second facteur'],
            'two_factor_challenge_passed' => ['Verifica del secondo fattore superata', 'Second factor challenge passed', 'Prüfung des zweiten Faktors bestanden', 'Vérification du second facteur réussie'],
            'two_factor_requirement_changed' => ['Obbligo del secondo fattore modificato', 'Second factor requirement changed', 'Pflicht zum zweiten Faktor geändert', 'Obligation du second facteur modifiée'],
            'two_factor_email_enabled' => ['Codice via email attivato', 'Email code enabled', 'E-Mail-Code aktiviert', 'Code par e-mail activé'],
            'two_factor_email_disabled' => ['Codice via email disattivato', 'Email code disabled', 'E-Mail-Code deaktiviert', 'Code par e-mail désactivé'],
            'two_factor_email_code_sent' => ['Codice via email inviato', 'Email code sent', 'E-Mail-Code gesendet', 'Code par e-mail envoyé'],
            'two_factor_sms_enabled' => ['Codice via SMS attivato', 'SMS code enabled', 'SMS-Code aktiviert', 'Code par SMS activé'],
            'two_factor_sms_disabled' => ['Codice via SMS disattivato', 'SMS code disabled', 'SMS-Code deaktiviert', 'Code par SMS désactivé'],
            'two_factor_sms_code_sent' => ['Codice via SMS inviato', 'SMS code sent', 'SMS-Code gesendet', 'Code par SMS envoyé'],
            'two_factor_webauthn_used' => ['Chiave di sicurezza usata', 'Security key used', 'Sicherheitsschlüssel verwendet', 'Clé de sécurité utilisée'],
            'trusted_device_created' => ['Dispositivo attendibile aggiunto', 'Trusted device added', 'Vertrauenswürdiges Gerät hinzugefügt', 'Appareil de confiance ajouté'],
            'trusted_device_used' => ['Dispositivo attendibile usato', 'Trusted device used', 'Vertrauenswürdiges Gerät verwendet', 'Appareil de confiance utilisé'],
            'trusted_device_revoked' => ['Dispositivo attendibile revocato', 'Trusted device revoked', 'Vertrauenswürdiges Gerät widerrufen', 'Appareil de confiance révoqué'],
            'trusted_devices_revoked' => ['Tutti i dispositivi attendibili revocati', 'All trusted devices revoked', 'Alle vertrauenswürdigen Geräte widerrufen', 'Tous les appareils de confiance révoqués'],
            'session_revoked' => ['Sessione revocata', 'Session revoked', 'Sitzung widerrufen', 'Session révoquée'],
            'other_sessions_revoked' => ['Altre sessioni revocate', 'Other sessions revoked', 'Andere Sitzungen widerrufen', 'Autres sessions révoquées'],
            'session_idle_expired' => ['Sessione scaduta per inattività', 'Session expired from inactivity', 'Sitzung wegen Inaktivität abgelaufen', 'Session expirée pour inactivité'],
            'session_limit_revoked' => ['Sessioni revocate per limite superato', 'Sessions revoked over the limit', 'Sitzungen wegen Überschreitung des Limits widerrufen', 'Sessions révoquées au-delà de la limite'],
            'login_locked' => ['Account bloccato', 'Account locked', 'Konto gesperrt', 'Compte verrouillé'],
            'login_unlocked' => ['Account sbloccato da un amministratore', 'Account unlocked by an administrator', 'Konto von einem Administrator entsperrt', 'Compte déverrouillé par un administrateur'],
            'login_succeeded' => ['Accesso riuscito', 'Sign-in succeeded', 'Anmeldung erfolgreich', 'Connexion réussie'],
            'login_failed' => ['Accesso non riuscito', 'Sign-in failed', 'Anmeldung fehlgeschlagen', 'Échec de la connexion'],
            'password_expired_login_denied' => ['Accesso negato: password scaduta', 'Sign-in denied: password expired', 'Anmeldung verweigert: Passwort abgelaufen', 'Connexion refusée : mot de passe expiré'],
            'password_login_denied_sso_only' => ['Accesso con password negato: solo SSO', 'Password sign-in denied: SSO only', 'Passwort-Anmeldung verweigert: nur SSO', 'Connexion par mot de passe refusée : SSO uniquement'],
            'password_login_break_glass' => ['Accesso con password di emergenza', 'Break-glass password sign-in', 'Notfall-Anmeldung mit Passwort', 'Connexion de secours par mot de passe'],
            'security_policy_changed' => ['Criteri di sicurezza modificati', 'Security policy changed', 'Sicherheitsrichtlinie geändert', 'Politique de sécurité modifiée'],
            'sso_provider_saved' => ['Provider SSO salvato', 'SSO provider saved', 'SSO-Anbieter gespeichert', 'Fournisseur SSO enregistré'],
            'sso_provider_removed' => ['Provider SSO rimosso', 'SSO provider removed', 'SSO-Anbieter entfernt', 'Fournisseur SSO supprimé'],
            'sso_logout' => ['Disconnessione SSO', 'SSO sign-out', 'SSO-Abmeldung', 'Déconnexion SSO'],
            'sso_login_started' => ['Accesso SSO avviato', 'SSO sign-in started', 'SSO-Anmeldung gestartet', 'Connexion SSO démarrée'],
            'sso_login_succeeded' => ['Accesso SSO riuscito', 'SSO sign-in succeeded', 'SSO-Anmeldung erfolgreich', 'Connexion SSO réussie'],
            'sso_login_failed' => ['Accesso SSO non riuscito', 'SSO sign-in failed', 'SSO-Anmeldung fehlgeschlagen', 'Échec de la connexion SSO'],
            'sso_user_provisioned' => ['Utente creato tramite SSO', 'User provisioned through SSO', 'Benutzer über SSO bereitgestellt', 'Utilisateur provisionné par SSO'],
            'sso_identity_linked' => ['Identità SSO collegata', 'SSO identity linked', 'SSO-Identität verknüpft', 'Identité SSO liée'],
            'scim_token_rotated' => ['Token SCIM creato o ruotato', 'SCIM token created or rotated', 'SCIM-Token erstellt oder rotiert', 'Jeton SCIM créé ou renouvelé'],
            'scim_token_revoked' => ['Token SCIM revocato', 'SCIM token revoked', 'SCIM-Token widerrufen', 'Jeton SCIM révoqué'],
            'scim_user_created' => ['Utente creato tramite SCIM', 'User created through SCIM', 'Benutzer über SCIM erstellt', 'Utilisateur créé par SCIM'],
            'scim_user_updated' => ['Utente aggiornato tramite SCIM', 'User updated through SCIM', 'Benutzer über SCIM aktualisiert', 'Utilisateur mis à jour par SCIM'],
            'scim_user_deactivated' => ['Utente disattivato tramite SCIM', 'User deactivated through SCIM', 'Benutzer über SCIM deaktiviert', 'Utilisateur désactivé par SCIM'],
            'scim_group_created' => ['Gruppo creato tramite SCIM', 'Group created through SCIM', 'Gruppe über SCIM erstellt', 'Groupe créé par SCIM'],
            'scim_group_updated' => ['Gruppo aggiornato tramite SCIM', 'Group updated through SCIM', 'Gruppe über SCIM aktualisiert', 'Groupe mis à jour par SCIM'],
            'scim_group_deleted' => ['Gruppo eliminato tramite SCIM', 'Group deleted through SCIM', 'Gruppe über SCIM gelöscht', 'Groupe supprimé par SCIM'],
            'scim_group_members_changed' => ['Membri del gruppo modificati tramite SCIM', 'Group members changed through SCIM', 'Gruppenmitglieder über SCIM geändert', 'Membres du groupe modifiés par SCIM'],
            'scim_role_applied' => ['Ruolo assegnato o ripristinato da un gruppo', 'Role granted or restored by a group', 'Rolle durch eine Gruppe vergeben oder wiederhergestellt', 'Rôle accordé ou rétabli par un groupe'],
            'scim_operation_failed' => ['Operazione SCIM non riuscita', 'SCIM operation failed', 'SCIM-Vorgang fehlgeschlagen', 'Échec d’une opération SCIM'],
            'scim_group_mapping_saved' => ['Mappatura gruppo-ruolo salvata', 'Group to role mapping saved', 'Gruppen-Rollen-Zuordnung gespeichert', 'Correspondance groupe-rôle enregistrée'],
            'scim_group_mapping_removed' => ['Mappatura gruppo-ruolo rimossa', 'Group to role mapping removed', 'Gruppen-Rollen-Zuordnung entfernt', 'Correspondance groupe-rôle supprimée'],
            'access_security_role_created' => ['Ruolo creato', 'Role created', 'Rolle erstellt', 'Rôle créé'],
            'access_security_role_updated' => ['Ruolo modificato', 'Role updated', 'Rolle geändert', 'Rôle modifié'],
            'access_security_role_assigned' => ['Ruolo assegnato', 'Role assigned', 'Rolle zugewiesen', 'Rôle attribué'],
            'access_security_role_revoked' => ['Ruolo revocato', 'Role revoked', 'Rolle entzogen', 'Rôle retiré'],
            'access_security_passkey_registered' => ['Passkey registrata', 'Passkey registered', 'Passkey registriert', 'Passkey enregistrée'],
            'access_security_passkey_renamed' => ['Passkey rinominata', 'Passkey renamed', 'Passkey umbenannt', 'Passkey renommée'],
            'access_security_passkey_revoked' => ['Passkey revocata', 'Passkey revoked', 'Passkey widerrufen', 'Passkey révoquée'],
            'access_security_mfa_enabled' => ['Autenticazione a più fattori attivata', 'Multi-factor authentication enabled', 'Mehrfaktor-Authentifizierung aktiviert', 'Authentification multifacteur activée'],
            'access_security_mfa_disabled' => ['Autenticazione a più fattori disattivata', 'Multi-factor authentication disabled', 'Mehrfaktor-Authentifizierung deaktiviert', 'Authentification multifacteur désactivée'],
            'access_security_login_succeeded' => ['Accesso riuscito', 'Sign-in succeeded', 'Anmeldung erfolgreich', 'Connexion réussie'],
            'access_security_login_failed' => ['Accesso non riuscito', 'Sign-in failed', 'Anmeldung fehlgeschlagen', 'Échec de la connexion'],
            'access_security_account_locked' => ['Account bloccato', 'Account locked', 'Konto gesperrt', 'Compte verrouillé'],
            'access_security_sessions_revoked' => ['Sessioni revocate', 'Sessions revoked', 'Sitzungen widerrufen', 'Sessions révoquées'],
            'access_security_impersonation_started' => ['Impersonificazione avviata', 'Impersonation started', 'Identitätswechsel gestartet', 'Usurpation d’identité démarrée'],
            'access_security_impersonation_ended' => ['Impersonificazione terminata', 'Impersonation ended', 'Identitätswechsel beendet', 'Usurpation d’identité terminée'],
        ];

        $prefixed = [];

        foreach ($labels as $type => $values) {
            $prefixed['security.audit.event.'.$type] = $values;
        }

        return $prefixed;
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
