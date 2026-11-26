<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the advanced second factors: the login challenge (switch method, remember device, recovery code,
 * email code, security key), the email code enrolment and the trusted devices of the security page. Each entry is
 * [it, en, de, fr]; the Italian text serves it-CH and it-IT, the German text de-CH and the French text fr-CH.
 * Placeholders are written {name}. Keys live under `auth.mfa.*` (login) and `security.mfa.*` (security page).
 */
final class AdvancedAuthMfaFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'auth.mfa.method.totp' => ['App di autenticazione', 'Authenticator app', 'Authenticator-App', 'Application d’authentification'],
            'auth.mfa.method.email' => ['Codice via email', 'Email code', 'E-Mail-Code', 'Code par e-mail'],
            'auth.mfa.method.webauthn' => ['Chiave di sicurezza o passkey', 'Security key or passkey', 'Sicherheitsschlüssel oder Passkey', 'Clé de sécurité ou passkey'],
            'auth.mfa.method.recovery_code' => ['Codice di recupero', 'Recovery code', 'Wiederherstellungscode', 'Code de récupération'],

            'auth.mfa.challenge.title' => ['Verifica in due passaggi', 'Two-step verification', 'Bestätigung in zwei Schritten', 'Vérification en deux étapes'],
            'auth.mfa.challenge.subtitle' => ['Conferma la tua identità per completare l’accesso.', 'Confirm your identity to finish signing in.', 'Bestätigen Sie Ihre Identität, um die Anmeldung abzuschliessen.', 'Confirmez votre identité pour terminer la connexion.'],
            'auth.mfa.challenge.totp_hint' => ['Inserisci il codice a sei cifre della tua app di autenticazione.', 'Enter the six-digit code from your authenticator app.', 'Geben Sie den sechsstelligen Code Ihrer Authenticator-App ein.', 'Saisissez le code à six chiffres de votre application d’authentification.'],
            'auth.mfa.challenge.email_hint' => ['Inserisci il codice a sei cifre che ti abbiamo inviato via email.', 'Enter the six-digit code we emailed you.', 'Geben Sie den sechsstelligen Code ein, den wir Ihnen per E-Mail gesendet haben.', 'Saisissez le code à six chiffres que nous vous avons envoyé par e-mail.'],
            'auth.mfa.challenge.recovery_hint' => ['Inserisci uno dei codici di recupero monouso. Ogni codice vale una sola volta.', 'Enter one of your single-use recovery codes. Each code works once.', 'Geben Sie einen Ihrer Wiederherstellungscodes ein. Jeder Code gilt nur einmal.', 'Saisissez l’un de vos codes de récupération à usage unique. Chaque code ne sert qu’une fois.'],
            'auth.mfa.challenge.webauthn_hint' => ['Collega la chiave di sicurezza o usa la passkey del dispositivo, poi segui le istruzioni del browser.', 'Plug in your security key or use the passkey of this device, then follow the browser prompt.', 'Verbinden Sie Ihren Sicherheitsschlüssel oder verwenden Sie den Passkey dieses Geräts und folgen Sie den Anweisungen des Browsers.', 'Branchez votre clé de sécurité ou utilisez la passkey de cet appareil, puis suivez les instructions du navigateur.'],
            'auth.mfa.challenge.code_label' => ['Codice di verifica', 'Verification code', 'Bestätigungscode', 'Code de vérification'],
            'auth.mfa.challenge.recovery_label' => ['Codice di recupero', 'Recovery code', 'Wiederherstellungscode', 'Code de récupération'],
            'auth.mfa.challenge.submit' => ['Verifica e accedi', 'Verify and sign in', 'Bestätigen und anmelden', 'Vérifier et se connecter'],
            'auth.mfa.challenge.switch_method' => ['Usa un altro metodo', 'Use another method', 'Andere Methode verwenden', 'Utiliser une autre méthode'],
            'auth.mfa.challenge.use_recovery_code' => ['Usa un codice di recupero', 'Use a recovery code', 'Wiederherstellungscode verwenden', 'Utiliser un code de récupération'],
            'auth.mfa.challenge.back_to_code' => ['Torna al codice di verifica', 'Back to the verification code', 'Zurück zum Bestätigungscode', 'Retour au code de vérification'],
            'auth.mfa.challenge.send_code' => ['Invia il codice', 'Send the code', 'Code senden', 'Envoyer le code'],
            'auth.mfa.challenge.code_sent' => ['Codice inviato a {email}', 'Code sent to {email}', 'Code an {email} gesendet', 'Code envoyé à {email}'],
            'auth.mfa.challenge.code_sent_generic' => ['Ti abbiamo inviato un codice via email.', 'We emailed you a code.', 'Wir haben Ihnen einen Code per E-Mail gesendet.', 'Nous vous avons envoyé un code par e-mail.'],
            'auth.mfa.challenge.resend' => ['Invia di nuovo il codice', 'Resend the code', 'Code erneut senden', 'Renvoyer le code'],
            'auth.mfa.challenge.resend_in' => ['Puoi richiedere un nuovo codice tra {seconds} secondi', 'You can ask for a new code in {seconds} seconds', 'Sie können in {seconds} Sekunden einen neuen Code anfordern', 'Vous pouvez demander un nouveau code dans {seconds} secondes'],
            'auth.mfa.challenge.webauthn_prompt' => ['In attesa della chiave di sicurezza…', 'Waiting for your security key…', 'Warten auf Ihren Sicherheitsschlüssel …', 'En attente de votre clé de sécurité…'],
            'auth.mfa.challenge.webauthn_button' => ['Usa la chiave di sicurezza', 'Use the security key', 'Sicherheitsschlüssel verwenden', 'Utiliser la clé de sécurité'],
            'auth.mfa.challenge.webauthn_cancelled' => ['La verifica con la chiave è stata annullata. Riprova.', 'The security key check was cancelled. Try again.', 'Die Überprüfung mit dem Schlüssel wurde abgebrochen. Versuchen Sie es erneut.', 'La vérification avec la clé a été annulée. Réessayez.'],
            'auth.mfa.challenge.remember_device' => ['Ricorda questo dispositivo per {days} giorni', 'Remember this device for {days} days', 'Dieses Gerät {days} Tage lang merken', 'Se souvenir de cet appareil pendant {days} jours'],
            'auth.mfa.challenge.remember_device_hint' => ['Su questo dispositivo non ti verrà chiesto il secondo fattore finché non scade. Usalo solo su dispositivi personali.', 'This device will not ask for the second factor until it expires. Use it only on personal devices.', 'Auf diesem Gerät wird der zweite Faktor bis zum Ablauf nicht abgefragt. Verwenden Sie dies nur auf persönlichen Geräten.', 'Cet appareil ne demandera pas le second facteur avant l’expiration. Utilisez cette option uniquement sur des appareils personnels.'],
            'auth.mfa.challenge.device_name' => ['Nome del dispositivo', 'Device name', 'Gerätename', 'Nom de l’appareil'],
            'auth.mfa.challenge.expired' => ['La verifica è scaduta. Accedi di nuovo con email e password.', 'The verification expired. Sign in again with your email and password.', 'Die Bestätigung ist abgelaufen. Melden Sie sich erneut mit E-Mail und Passwort an.', 'La vérification a expiré. Reconnectez-vous avec votre e-mail et votre mot de passe.'],

            'auth.mfa.error.invalid_code' => ['Il codice non è valido o è già stato usato.', 'The code is not valid or has already been used.', 'Der Code ist ungültig oder wurde bereits verwendet.', 'Le code n’est pas valide ou a déjà été utilisé.'],
            'auth.mfa.error.webauthn_failed' => ['Impossibile verificare la chiave di sicurezza. Riprova o usa un altro metodo.', 'The security key could not be verified. Try again or use another method.', 'Der Sicherheitsschlüssel konnte nicht überprüft werden. Versuchen Sie es erneut oder verwenden Sie eine andere Methode.', 'La clé de sécurité n’a pas pu être vérifiée. Réessayez ou utilisez une autre méthode.'],
            'auth.mfa.error.too_many_codes' => ['Hai richiesto troppi codici. Riprova tra {seconds} secondi.', 'You asked for too many codes. Try again in {seconds} seconds.', 'Sie haben zu viele Codes angefordert. Versuchen Sie es in {seconds} Sekunden erneut.', 'Vous avez demandé trop de codes. Réessayez dans {seconds} secondes.'],
            'auth.mfa.error.too_many_attempts' => ['Troppi tentativi errati. Accedi di nuovo.', 'Too many wrong attempts. Sign in again.', 'Zu viele Fehlversuche. Melden Sie sich erneut an.', 'Trop de tentatives erronées. Reconnectez-vous.'],
            'auth.mfa.error.account_locked' => ['L’account è temporaneamente bloccato.', 'The account is temporarily locked.', 'Das Konto ist vorübergehend gesperrt.', 'Le compte est temporairement verrouillé.'],
            'auth.mfa.error.method_unavailable' => ['Questo metodo non è disponibile per il tuo account.', 'This method is not available for your account.', 'Diese Methode ist für Ihr Konto nicht verfügbar.', 'Cette méthode n’est pas disponible pour votre compte.'],
            'auth.mfa.error.webauthn_unsupported' => ['Questo browser non supporta le chiavi di sicurezza.', 'This browser does not support security keys.', 'Dieser Browser unterstützt keine Sicherheitsschlüssel.', 'Ce navigateur ne prend pas en charge les clés de sécurité.'],

            'security.mfa.title' => ['Verifica in due passaggi', 'Two-step verification', 'Bestätigung in zwei Schritten', 'Vérification en deux étapes'],
            'security.mfa.methods.title' => ['Metodi di verifica', 'Verification methods', 'Bestätigungsmethoden', 'Méthodes de vérification'],
            'security.mfa.methods.enabled' => ['Attivo', 'Active', 'Aktiv', 'Actif'],
            'security.mfa.methods.disabled' => ['Non attivo', 'Not active', 'Nicht aktiv', 'Inactif'],
            'security.mfa.methods.recovery_left' => ['{count} codici di recupero rimasti', '{count} recovery codes left', 'Noch {count} Wiederherstellungscodes', '{count} codes de récupération restants'],

            'security.mfa.email.title' => ['Codice via email', 'Email code', 'E-Mail-Code', 'Code par e-mail'],
            'security.mfa.email.description' => ['Ricevi un codice a sei cifre al tuo indirizzo email a ogni accesso.', 'Get a six-digit code at your email address at every sign-in.', 'Erhalten Sie bei jeder Anmeldung einen sechsstelligen Code an Ihre E-Mail-Adresse.', 'Recevez un code à six chiffres à votre adresse e-mail à chaque connexion.'],
            'security.mfa.email.enable' => ['Attiva il codice via email', 'Turn on the email code', 'E-Mail-Code aktivieren', 'Activer le code par e-mail'],
            'security.mfa.email.disable' => ['Disattiva il codice via email', 'Turn off the email code', 'E-Mail-Code deaktivieren', 'Désactiver le code par e-mail'],
            'security.mfa.email.password_prompt' => ['Conferma la password per continuare.', 'Confirm your password to continue.', 'Bestätigen Sie Ihr Passwort, um fortzufahren.', 'Confirmez votre mot de passe pour continuer.'],
            'security.mfa.email.enter_code' => ['Inserisci il codice che abbiamo inviato a {email}.', 'Enter the code we sent to {email}.', 'Geben Sie den Code ein, den wir an {email} gesendet haben.', 'Saisissez le code que nous avons envoyé à {email}.'],
            'security.mfa.email.confirm' => ['Conferma il codice', 'Confirm the code', 'Code bestätigen', 'Confirmer le code'],
            'security.mfa.email.resend' => ['Invia di nuovo il codice', 'Resend the code', 'Code erneut senden', 'Renvoyer le code'],
            'security.mfa.email.enabled_toast' => ['Codice via email attivato', 'Email code turned on', 'E-Mail-Code aktiviert', 'Code par e-mail activé'],
            'security.mfa.email.disabled_toast' => ['Codice via email disattivato', 'Email code turned off', 'E-Mail-Code deaktiviert', 'Code par e-mail désactivé'],
            'security.mfa.email.last_factor' => ['È il tuo ultimo metodo: la tua azienda richiede almeno un secondo fattore.', 'This is your last method: your organisation requires at least one second factor.', 'Dies ist Ihre letzte Methode: Ihr Unternehmen verlangt mindestens einen zweiten Faktor.', 'C’est votre dernière méthode : votre entreprise exige au moins un second facteur.'],

            'security.mfa.recovery.new_codes_title' => ['Conserva i codici di recupero', 'Store your recovery codes', 'Bewahren Sie Ihre Wiederherstellungscodes auf', 'Conservez vos codes de récupération'],
            'security.mfa.recovery.new_codes_hint' => ['Questi codici vengono mostrati una sola volta. Ognuno vale per un accesso se perdi gli altri metodi.', 'These codes are shown only once. Each one signs you in once if you lose your other methods.', 'Diese Codes werden nur einmal angezeigt. Jeder gilt einmal für die Anmeldung, falls Sie Ihre anderen Methoden verlieren.', 'Ces codes ne sont affichés qu’une seule fois. Chacun permet une connexion si vous perdez vos autres méthodes.'],

            'security.mfa.trusted.title' => ['Dispositivi attendibili', 'Trusted devices', 'Vertrauenswürdige Geräte', 'Appareils de confiance'],
            'security.mfa.trusted.description' => ['Questi dispositivi saltano la verifica in due passaggi fino alla scadenza.', 'These devices skip the two-step verification until they expire.', 'Diese Geräte überspringen die Bestätigung in zwei Schritten bis zum Ablauf.', 'Ces appareils ignorent la vérification en deux étapes jusqu’à leur expiration.'],
            'security.mfa.trusted.empty' => ['Nessun dispositivo attendibile.', 'No trusted devices.', 'Keine vertrauenswürdigen Geräte.', 'Aucun appareil de confiance.'],
            'security.mfa.trusted.column.device' => ['Dispositivo', 'Device', 'Gerät', 'Appareil'],
            'security.mfa.trusted.column.ip' => ['Indirizzo IP', 'IP address', 'IP-Adresse', 'Adresse IP'],
            'security.mfa.trusted.column.last_used' => ['Ultimo utilizzo', 'Last used', 'Zuletzt verwendet', 'Dernière utilisation'],
            'security.mfa.trusted.column.created' => ['Aggiunto il', 'Added on', 'Hinzugefügt am', 'Ajouté le'],
            'security.mfa.trusted.column.expires' => ['Scade il', 'Expires on', 'Läuft ab am', 'Expire le'],
            'security.mfa.trusted.revoke' => ['Rimuovi', 'Remove', 'Entfernen', 'Supprimer'],
            'security.mfa.trusted.revoke_confirm' => ['Rimuovere questo dispositivo? Al prossimo accesso verrà chiesto il secondo fattore.', 'Remove this device? The next sign-in will ask for the second factor.', 'Dieses Gerät entfernen? Bei der nächsten Anmeldung wird der zweite Faktor abgefragt.', 'Supprimer cet appareil ? La prochaine connexion demandera le second facteur.'],
            'security.mfa.trusted.revoke_all' => ['Rimuovi tutti i dispositivi', 'Remove all devices', 'Alle Geräte entfernen', 'Supprimer tous les appareils'],
            'security.mfa.trusted.revoke_all_confirm' => ['Rimuovere tutti i dispositivi attendibili?', 'Remove all trusted devices?', 'Alle vertrauenswürdigen Geräte entfernen?', 'Supprimer tous les appareils de confiance ?'],
            'security.mfa.trusted.revoked_toast' => ['Dispositivo rimosso', 'Device removed', 'Gerät entfernt', 'Appareil supprimé'],
            'security.mfa.trusted.revoked_all_toast' => ['Tutti i dispositivi sono stati rimossi', 'All devices were removed', 'Alle Geräte wurden entfernt', 'Tous les appareils ont été supprimés'],
            'security.mfa.trusted.disabled' => ['La funzione “ricorda dispositivo” è disattivata dall’amministratore.', 'Remembering devices is turned off by your administrator.', 'Das Merken von Geräten wurde von Ihrem Administrator deaktiviert.', 'La mémorisation des appareils est désactivée par votre administrateur.'],
            'auth.mfa.method.sms' => ['Codice via SMS', 'SMS code', 'SMS-Code', 'Code par SMS'],
            'auth.mfa.challenge.sms_hint' => ['Inserisci il codice a sei cifre che ti abbiamo inviato via SMS.', 'Enter the six-digit code we sent you by SMS.', 'Geben Sie den sechsstelligen Code ein, den wir Ihnen per SMS gesendet haben.', 'Saisissez le code à six chiffres que nous vous avons envoyé par SMS.'],
            'auth.mfa.challenge.sms_sent' => ['Ti abbiamo inviato un SMS con il codice.', 'We sent you an SMS with the code.', 'Wir haben Ihnen eine SMS mit dem Code gesendet.', 'Nous vous avons envoyé un SMS avec le code.'],
            'auth.mfa.error.sms_unavailable' => ['Impossibile inviare l’SMS. Riprova più tardi o usa un altro metodo.', 'The SMS could not be sent. Try again later or use another method.', 'Die SMS konnte nicht gesendet werden. Versuchen Sie es später erneut oder verwenden Sie eine andere Methode.', 'Le SMS n’a pas pu être envoyé. Réessayez plus tard ou utilisez une autre méthode.'],

            'security.mfa.sms.title' => ['Codice via SMS', 'SMS code', 'SMS-Code', 'Code par SMS'],
            'security.mfa.sms.description' => ['Ricevi un codice a sei cifre via SMS sul tuo telefono a ogni accesso.', 'Get a six-digit code by SMS on your phone at every sign-in.', 'Erhalten Sie bei jeder Anmeldung einen sechsstelligen Code per SMS auf Ihr Telefon.', 'Recevez un code à six chiffres par SMS sur votre téléphone à chaque connexion.'],
            'security.mfa.sms.enable' => ['Attiva il codice via SMS', 'Turn on the SMS code', 'SMS-Code aktivieren', 'Activer le code par SMS'],
            'security.mfa.sms.disable' => ['Disattiva il codice via SMS', 'Turn off the SMS code', 'SMS-Code deaktivieren', 'Désactiver le code par SMS'],
            'security.mfa.sms.phone_label' => ['Numero di telefono', 'Phone number', 'Telefonnummer', 'Numéro de téléphone'],
            'security.mfa.sms.phone_hint' => ['Formato internazionale, ad esempio +41791234567.', 'International format, for example +41791234567.', 'Internationales Format, zum Beispiel +41791234567.', 'Format international, par exemple +41791234567.'],
            'security.mfa.sms.phone_invalid' => ['Il numero non è in formato internazionale.', 'The number is not in international format.', 'Die Nummer ist nicht im internationalen Format.', 'Le numéro n’est pas au format international.'],
            'security.mfa.sms.enter_code' => ['Inserisci il codice che abbiamo inviato al numero {phone}.', 'Enter the code we sent to {phone}.', 'Geben Sie den Code ein, den wir an {phone} gesendet haben.', 'Saisissez le code que nous avons envoyé au {phone}.'],
            'security.mfa.sms.confirm' => ['Conferma il codice', 'Confirm the code', 'Code bestätigen', 'Confirmer le code'],
            'security.mfa.sms.resend' => ['Invia di nuovo il codice', 'Resend the code', 'Code erneut senden', 'Renvoyer le code'],
            'security.mfa.sms.enabled_toast' => ['Codice via SMS attivato', 'SMS code turned on', 'SMS-Code aktiviert', 'Code par SMS activé'],
            'security.mfa.sms.disabled_toast' => ['Codice via SMS disattivato', 'SMS code turned off', 'SMS-Code deaktiviert', 'Code par SMS désactivé'],
            'security.mfa.sms.send_error' => ['Impossibile inviare l’SMS', 'The SMS could not be sent', 'Die SMS konnte nicht gesendet werden', 'Le SMS n’a pas pu être envoyé'],
            'security.mfa.sms.last_factor' => ['È il tuo ultimo metodo: la tua azienda richiede almeno un secondo fattore.', 'This is your last method: your organisation requires at least one second factor.', 'Dies ist Ihre letzte Methode: Ihr Unternehmen verlangt mindestens einen zweiten Faktor.', 'C’est votre dernière méthode : votre entreprise exige au moins un second facteur.'],

            'security.mfa.passkey.rename' => ['Rinomina', 'Rename', 'Umbenennen', 'Renommer'],
            'security.mfa.passkey.rename_title' => ['Rinomina la passkey', 'Rename the passkey', 'Passkey umbenennen', 'Renommer la passkey'],
            'security.mfa.passkey.name_label' => ['Nome della passkey', 'Passkey name', 'Name des Passkeys', 'Nom de la passkey'],
            'security.mfa.passkey.renamed_toast' => ['Passkey rinominata', 'Passkey renamed', 'Passkey umbenannt', 'Passkey renommée'],
            'security.mfa.passkey.recovery_title' => ['Codici di recupero generati', 'Recovery codes created', 'Wiederherstellungscodes erstellt', 'Codes de récupération créés'],
            'security.mfa.passkey.recovery_hint' => ['La passkey è ora il tuo secondo fattore dopo la password. Conserva questi codici: vengono mostrati una sola volta.', 'The passkey is now your second factor after the password. Store these codes: they are shown only once.', 'Der Passkey ist nun Ihr zweiter Faktor nach dem Passwort. Bewahren Sie diese Codes auf: Sie werden nur einmal angezeigt.', 'La passkey est maintenant votre second facteur après le mot de passe. Conservez ces codes : ils ne sont affichés qu’une seule fois.'],
            'security.mfa.passkey.last_factor' => ['È la tua ultima passkey e il tuo ultimo metodo: la tua azienda richiede almeno un secondo fattore.', 'This is your last passkey and your last method: your organisation requires at least one second factor.', 'Dies ist Ihr letzter Passkey und Ihre letzte Methode: Ihr Unternehmen verlangt mindestens einen zweiten Faktor.', 'C’est votre dernière passkey et votre dernière méthode : votre entreprise exige au moins un second facteur.'],

            'security.mfa.trusted.load_error' => ['Impossibile caricare i dispositivi attendibili', 'The trusted devices could not be loaded', 'Die vertrauenswürdigen Geräte konnten nicht geladen werden', 'Les appareils de confiance n’ont pas pu être chargés'],
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
