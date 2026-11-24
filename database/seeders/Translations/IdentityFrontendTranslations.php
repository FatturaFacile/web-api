<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

/**
 * UI strings of the IAM console (Mod 004): roles and permission matrix, field policies, ABAC rules, passkeys and the
 * "Reserved" marker of masked fields. Each entry is [it, en, de, fr].
 */
final class IdentityFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'IAM.ROLES_TITLE' => ['Ruoli e permessi', 'Roles and permissions', 'Rollen und Berechtigungen', 'Rôles et autorisations'],
            'IAM.ROLES_SUBTITLE' => [
                'Definisci chi può fare cosa: permessi per modulo e azione, regole per attributi e visibilità dei campi sensibili.',
                'Define who can do what: permissions per module and action, attribute rules and visibility of sensitive fields.',
                'Legen Sie fest, wer was darf: Berechtigungen pro Modul und Aktion, Attributregeln und Sichtbarkeit sensibler Felder.',
                'Définissez qui peut faire quoi : autorisations par module et action, règles d\'attributs et visibilité des champs sensibles.',
            ],
            'IAM.ROLE_NAME' => ['Nome del ruolo', 'Role name', 'Rollenname', 'Nom du rôle'],
            'IAM.ROLE_PERMISSIONS' => ['Permessi', 'Permissions', 'Berechtigungen', 'Autorisations'],
            'IAM.ROLE_SYSTEM' => ['Di sistema', 'System', 'System', 'Système'],
            'IAM.ROLE_NEW' => ['Nuovo ruolo', 'New role', 'Neue Rolle', 'Nouveau rôle'],
            'IAM.ROLE_CLONE' => ['Duplica', 'Clone', 'Duplizieren', 'Dupliquer'],
            'IAM.ROLE_DELETE' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'IAM.ROLE_DELETE_CONFIRM' => ['Eliminare il ruolo {name}?', 'Delete the role {name}?', 'Die Rolle {name} löschen?', 'Supprimer le rôle {name} ?'],
            'IAM.ROLE_DELETED' => ['Ruolo eliminato', 'Role deleted', 'Rolle gelöscht', 'Rôle supprimé'],
            'IAM.ROLE_COPY_SUFFIX' => ['(copia)', '(copy)', '(Kopie)', '(copie)'],
            'IAM.TAB_PERMISSIONS' => ['Permessi', 'Permissions', 'Berechtigungen', 'Autorisations'],
            'IAM.TAB_FIELDS' => ['Campi sensibili', 'Sensitive fields', 'Sensible Felder', 'Champs sensibles'],
            'IAM.TAB_ABAC' => ['Regole per attributi', 'Attribute rules', 'Attributregeln', 'Règles d\'attributs'],
            'IAM.SAVE' => ['Salva', 'Save', 'Speichern', 'Enregistrer'],
            'IAM.SAVED' => ['Modifiche salvate', 'Changes saved', 'Änderungen gespeichert', 'Modifications enregistrées'],
            'IAM.EDIT_CONFLICT' => [
                'Il ruolo è stato modificato da un altro utente: i dati sono stati ricaricati.',
                'The role was changed by another user: the data was reloaded.',
                'Die Rolle wurde von einem anderen Benutzer geändert: die Daten wurden neu geladen.',
                'Le rôle a été modifié par un autre utilisateur : les données ont été rechargées.',
            ],
            'IAM.ADD' => ['Aggiungi', 'Add', 'Hinzufügen', 'Ajouter'],
            'IAM.REMOVE' => ['Rimuovi', 'Remove', 'Entfernen', 'Retirer'],

            'IAM.MATRIX_RESOURCE' => ['Modulo e risorsa', 'Module and resource', 'Modul und Ressource', 'Module et ressource'],
            'IAM.ACTION_VIEW' => ['Visualizza', 'View', 'Anzeigen', 'Consulter'],
            'IAM.ACTION_CREATE' => ['Crea', 'Create', 'Erstellen', 'Créer'],
            'IAM.ACTION_UPDATE' => ['Modifica', 'Update', 'Bearbeiten', 'Modifier'],
            'IAM.ACTION_DELETE' => ['Elimina', 'Delete', 'Löschen', 'Supprimer'],
            'IAM.ACTION_EXPORT' => ['Esporta', 'Export', 'Exportieren', 'Exporter'],
            'IAM.ACTION_APPROVE' => ['Approva', 'Approve', 'Genehmigen', 'Approuver'],
            'IAM.ACTION_POST' => ['Registra', 'Post', 'Buchen', 'Comptabiliser'],
            'IAM.ACTION_CANCEL' => ['Annulla', 'Cancel', 'Stornieren', 'Annuler'],
            'IAM.ACTION_SEND_SDI' => ['Invia a SdI', 'Send to SDI', 'An SDI senden', 'Envoyer au SDI'],
            'IAM.ACTION_VIEW_SENSITIVE' => ['Vedi dati sensibili', 'View sensitive data', 'Sensible Daten sehen', 'Voir les données sensibles'],

            'IAM.FIELD_RESOURCE' => ['Risorsa', 'Resource', 'Ressource', 'Ressource'],
            'IAM.FIELD_NAME' => ['Campo', 'Field', 'Feld', 'Champ'],
            'IAM.FIELD_KIND' => ['Tipo', 'Type', 'Typ', 'Type'],
            'IAM.FIELD_VISIBILITY' => ['Visibilità', 'Visibility', 'Sichtbarkeit', 'Visibilité'],
            'IAM.FIELD_ADD' => ['Aggiungi un campo da proteggere', 'Add a field to protect', 'Zu schützendes Feld hinzufügen', 'Ajouter un champ à protéger'],
            'IAM.KIND_AMOUNT' => ['Importo', 'Amount', 'Betrag', 'Montant'],
            'IAM.KIND_IBAN' => ['IBAN', 'IBAN', 'IBAN', 'IBAN'],
            'IAM.VISIBILITY_VISIBLE' => ['Visibile', 'Visible', 'Sichtbar', 'Visible'],
            'IAM.VISIBILITY_MASKED' => ['Mascherato', 'Masked', 'Maskiert', 'Masqué'],
            'IAM.VISIBILITY_HIDDEN' => ['Nascosto', 'Hidden', 'Verborgen', 'Caché'],
            'IAM.RESERVED' => ['Riservato', 'Reserved', 'Vertraulich', 'Réservé'],
            'IAM.RESERVED_HINT' => [
                'Il tuo ruolo non può vedere questo valore.',
                'Your role cannot see this value.',
                'Ihre Rolle darf diesen Wert nicht sehen.',
                'Votre rôle ne peut pas voir cette valeur.',
            ],

            'IAM.ABAC_PERMISSION' => ['Permesso', 'Permission', 'Berechtigung', 'Autorisation'],
            'IAM.ABAC_ALLOW' => ['Consenti solo se', 'Allow only if', 'Nur erlauben wenn', 'Autoriser seulement si'],
            'IAM.ABAC_DENY' => ['Nega se', 'Deny if', 'Verweigern wenn', 'Refuser si'],
            'IAM.ABAC_VALUE' => ['Valore', 'Value', 'Wert', 'Valeur'],
            'IAM.ABAC_ADD_CONDITION' => ['Aggiungi condizione', 'Add condition', 'Bedingung hinzufügen', 'Ajouter une condition'],
            'IAM.ABAC_ADD_RULE' => ['Aggiungi regola', 'Add rule', 'Regel hinzufügen', 'Ajouter une règle'],

            'IAM.IMPERSONATION_BANNER' => ['Sessione di supporto: stai agendo come un altro utente', 'Support session: you are acting as another user', 'Support-Sitzung: Sie handeln als anderer Benutzer', 'Session de support : vous agissez comme un autre utilisateur'],
            'IAM.IMPERSONATION_UNTIL' => ['Scade alle', 'Expires at', 'Läuft ab um', 'Expire à'],
            'IAM.IMPERSONATION_LEAVE' => ['Esci dalla sessione', 'Leave session', 'Sitzung verlassen', 'Quitter la session'],

            'IAM.PASSKEYS_TITLE' => ['Passkey', 'Passkeys', 'Passkeys', 'Passkeys'],
            'IAM.PASSKEYS_HELP' => [
                'Accedi senza password con l\'impronta, il volto o il PIN del dispositivo. La chiave privata non lascia mai il dispositivo.',
                'Sign in without a password using your device fingerprint, face or PIN. The private key never leaves your device.',
                'Melden Sie sich ohne Passwort mit Fingerabdruck, Gesicht oder PIN Ihres Geräts an. Der private Schlüssel verlässt das Gerät nie.',
                'Connectez-vous sans mot de passe avec l\'empreinte, le visage ou le code PIN de votre appareil. La clé privée ne quitte jamais l\'appareil.',
            ],
            'IAM.PASSKEY_NAME' => ['Nome del dispositivo', 'Device name', 'Gerätename', 'Nom de l\'appareil'],
            'IAM.PASSKEY_CREATED' => ['Creata il', 'Created', 'Erstellt am', 'Créée le'],
            'IAM.PASSKEY_LAST_USED' => ['Ultimo utilizzo', 'Last used', 'Zuletzt verwendet', 'Dernière utilisation'],
            'IAM.PASSKEY_ADD' => ['Aggiungi una passkey', 'Add a passkey', 'Passkey hinzufügen', 'Ajouter une passkey'],
            'IAM.PASSKEY_ADDED' => ['Passkey aggiunta', 'Passkey added', 'Passkey hinzugefügt', 'Passkey ajoutée'],
            'IAM.PASSKEY_REVOKE' => ['Revoca', 'Revoke', 'Widerrufen', 'Révoquer'],
            'IAM.PASSKEY_REVOKE_CONFIRM' => ['Revocare la passkey {name}?', 'Revoke the passkey {name}?', 'Die Passkey {name} widerrufen?', 'Révoquer la passkey {name} ?'],
            'IAM.PASSKEY_SIGNIN' => ['Accedi con una passkey', 'Sign in with a passkey', 'Mit Passkey anmelden', 'Se connecter avec une passkey'],
            'IAM.PASSKEY_UNAVAILABLE' => [
                'Le passkey non sono disponibili su questo browser o dispositivo.',
                'Passkeys are not available on this browser or device.',
                'Passkeys sind in diesem Browser oder auf diesem Gerät nicht verfügbar.',
                'Les passkeys ne sont pas disponibles sur ce navigateur ou cet appareil.',
            ],
            'IAM.PASSKEY_REJECTED' => [
                'La passkey non è valida o è stata revocata.',
                'The passkey is not valid or was revoked.',
                'Die Passkey ist ungültig oder wurde widerrufen.',
                'La passkey n\'est pas valide ou a été révoquée.',
            ],
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
