<?php

declare(strict_types=1);

return [
    'messages' => [
        'challenge_required' => 'Geben Sie den Code Ihrer Authenticator-App ein, um die Anmeldung abzuschliessen.',
        'setup_started' => 'Scannen Sie den QR-Code mit Ihrer Authenticator-App und bestätigen Sie mit dem ersten Code.',
        'enabled' => 'Zwei-Faktor-Authentifizierung aktiviert. Bewahren Sie die Wiederherstellungscodes sicher auf.',
        'disabled' => 'Zwei-Faktor-Authentifizierung deaktiviert.',
        'recovery_codes_regenerated' => 'Neue Wiederherstellungscodes erstellt. Die bisherigen sind nicht mehr gültig.',
        'policy_updated' => 'Richtlinie für die Zwei-Faktor-Authentifizierung aktualisiert.',
        'member_reset' => 'Die Zwei-Faktor-Authentifizierung des Benutzers wurde zurückgesetzt.',
    ],
    'errors' => [
        'challenge_invalid_title' => 'Anmeldung abgelaufen',
        'challenge_invalid_detail' => 'Die Überprüfung ist abgelaufen oder ungültig. Melden Sie sich erneut mit E-Mail und Passwort an.',
        'code_invalid_title' => 'Ungültiger Code',
        'code_invalid_detail' => 'Der Code ist ungültig oder wurde bereits verwendet.',
        'password_invalid' => 'Das Passwort ist nicht korrekt.',
        'already_enabled_title' => 'Bereits aktiviert',
        'already_enabled_detail' => 'Die Zwei-Faktor-Authentifizierung ist bereits aktiv. Deaktivieren Sie sie, bevor Sie sie neu einrichten.',
        'no_pending_setup_title' => 'Keine Einrichtung im Gange',
        'no_pending_setup_detail' => 'Starten Sie zuerst die Einrichtung der Zwei-Faktor-Authentifizierung.',
        'not_enabled_title' => 'Nicht aktiviert',
        'not_enabled_detail' => 'Die Zwei-Faktor-Authentifizierung ist nicht aktiv.',
        'setup_required_title' => 'Zwei-Faktor-Authentifizierung erforderlich',
        'setup_required_detail' => 'Ihr Unternehmen verlangt die Zwei-Faktor-Authentifizierung. Richten Sie sie ein, um fortzufahren.',
        'admin_setup_required_title' => 'Richten Sie zuerst Ihre eigenen zwei Faktoren ein',
        'admin_setup_required_detail' => 'Aktivieren Sie die Zwei-Faktor-Authentifizierung für Ihr Konto, bevor Sie sie verpflichtend machen.',
        'member_not_found_title' => 'Benutzer nicht gefunden',
        'member_not_found_detail' => 'Der Benutzer gehört nicht zu diesem Unternehmen.',
        'reset_not_allowed_title' => 'Zurücksetzen nicht erlaubt',
        'reset_not_allowed_detail' => 'Sie können weder Ihr eigenes Konto noch einen Plattform-Administrator oder einen Benutzer zurücksetzen, der auch anderen Unternehmen angehört.',
    ],
];
