<?php

declare(strict_types=1);

return [
    'notifications' => [
        'passkey_registered' => ['title' => 'Passkey hinzugefügt', 'body' => 'Ihrem Konto wurde ein neuer Passkey hinzugefügt. Waren Sie das nicht, entfernen Sie ihn und beenden Sie Ihre Sitzungen.'],
        'passkey_revoked' => ['title' => 'Passkey entfernt', 'body' => 'Ein Passkey wurde aus Ihrem Konto entfernt.'],
        'mfa_disabled' => ['title' => 'Zwei-Faktor-Authentifizierung deaktiviert', 'body' => 'Die Zwei-Faktor-Authentifizierung wurde für Ihr Konto deaktiviert.'],
        'sessions_revoked' => ['title' => 'Sitzungen beendet', 'body' => 'Alle Ihre Sitzungen und Token wurden beendet. Melden Sie sich erneut an.'],
        'role_changed' => ['title' => 'Ihre Rollen haben sich geändert', 'body' => 'Ein Administrator hat die Ihnen zugewiesenen Rollen geändert.'],
        'account_locked' => ['title' => 'Konto gesperrt', 'body' => 'Ihr Konto wurde nach zu vielen fehlgeschlagenen Anmeldeversuchen gesperrt.'],
        'impersonation_started' => ['title' => 'Support-Sitzung gestartet', 'body' => 'Auf Ihrem Konto wurde eine Support-Sitzung mit dokumentierter Zustimmung gestartet.'],
    ],
];
