<?php

declare(strict_types=1);

return [
    'errors' => [
        'user_limit' => 'Der Tarif des Unternehmens erlaubt höchstens :max aktive Personen, Einladungen eingeschlossen.',
        'role_limit' => 'Der Tarif des Unternehmens erlaubt höchstens :max eigene Rollen.',
        'role_in_use' => 'Diese Rolle haben noch Mitglieder: weisen Sie ihnen zuerst eine andere Rolle zu.',
        'delegator_not_member' => 'Der Delegierende ist kein aktives Mitglied des Unternehmens.',
        'delegate_not_member' => 'Der Vertreter ist kein aktives Mitglied des Unternehmens.',
        'delegate_is_delegator' => 'Eine Person kann nicht an sich selbst delegieren.',
        'delegate_for_others' => 'Nur ein Inhaber oder Administrator kann im Namen einer anderen Person delegieren.',
        'delegate_owner_reserved' => 'Nur ein Inhaber kann den Zugriff eines Inhabers delegieren.',
        'team_member_not_member' => 'Jedes Teammitglied muss Mitglied des Unternehmens sein.',
        'already_member' => 'Diese Adresse gehört bereits einem Mitglied des Unternehmens.',
        'invitation_closed' => 'Die Einladung wurde bereits angenommen oder widerrufen.',
        'invitation_invalid' => 'Der Einladungslink ist ungültig oder abgelaufen.',
        'invitation_sign_in' => 'Melden Sie sich mit dem eingeladenen Konto an, um diese Einladung anzunehmen.',
        'import_no_header' => 'Die Datei braucht eine Kopfzeile mit der Spalte email.',
        'import_too_many_rows' => 'Die Datei hat zu viele Zeilen (höchstens :max).',
    ],
    'import' => [
        'already_member' => 'Bereits Mitglied.',
        'already_invited' => 'Eine Einladung steht bereits aus.',
        'invalid_email' => 'Die E-Mail-Adresse ist ungültig.',
        'duplicate' => 'In der Datei wiederholt.',
        'unknown_role' => 'Unbekannte Rolle :role.',
        'unknown_team' => 'Unbekanntes Team :team.',
        'failed' => 'Die Zeile konnte nicht importiert werden.',
    ],
    'mail' => [
        'invitation' => [
            'subject' => 'Einladung zu :company - :app',
            'greeting' => 'Sie wurden eingeladen',
            'body' => ':inviter hat Sie eingeladen, :company auf :app als :role beizutreten.',
            'action' => 'Einladung annehmen',
            'expires' => 'Der Link ist gültig bis :date.',
        ],
    ],
];
