<?php

declare(strict_types=1);

return [
    'messages' => [
        'requested' => 'Falls die E-Mail-Adresse in unserem System existiert, erhalten Sie eine E-Mail mit Anweisungen zum Wiederherstellen Ihres Passworts.',
        'token_valid' => 'Der Link ist gültig.',
        'updated' => 'Passwort aktualisiert. Sie können sich jetzt mit dem neuen Passwort anmelden.',
    ],
    'errors' => [
        'token_invalid_title' => 'Ungültiger Link',
        'token_invalid_detail' => 'Der Link zum Zurücksetzen des Passworts ist ungültig oder wurde bereits verwendet.',
        'token_expired_title' => 'Link abgelaufen',
        'token_expired_detail' => 'Der Link zum Zurücksetzen des Passworts ist abgelaufen. Bitte fordern Sie einen neuen an.',
        'locked_title' => 'Zu viele Versuche',
        'locked_detail' => 'Zu viele fehlgeschlagene Versuche. Bitte versuchen Sie es später erneut.',
    ],
    'mail' => [
        'reset' => [
            'subject' => 'Setzen Sie Ihr Passwort zurück - :app',
            'intro' => 'Wir haben eine Anfrage erhalten, das Passwort Ihres Kontos bei :app zurückzusetzen.',
            'action' => 'Passwort zurücksetzen',
            'expires' => 'Der Link ist bis :date gültig und kann nur einmal verwendet werden.',
            'security' => 'Falls Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese Nachricht: Ihr Passwort bleibt unverändert. Geben Sie diesen Link niemals weiter.',
        ],
        'changed' => [
            'subject' => 'Ihr Passwort wurde geändert - :app',
            'intro' => 'Das Passwort Ihres Kontos bei :app wurde geändert. Zu Ihrer Sicherheit wurden alle aktiven Sitzungen beendet.',
            'date' => 'Datum und Uhrzeit: :date',
            'ip' => 'IP-Adresse: :ip',
            'device' => 'Browser und Gerät: :device',
            'unknown' => 'nicht verfügbar',
            'security' => 'Falls Sie das nicht waren, kontaktieren Sie sofort den Support: Jemand könnte Zugriff auf Ihr Postfach gehabt haben.',
            'security_profile' => 'Falls Sie das nicht waren, kennt jemand Ihr Passwort: Setzen Sie es sofort über „Passwort vergessen“ zurück und kontaktieren Sie den Support.',
        ],
        'alert' => [
            'subject' => 'Viele Anfragen zur Passwort-Wiederherstellung - :app',
            'intro' => 'Wir haben viele Anfragen zur Passwort-Wiederherstellung für Ihr Konto bei :app erhalten und sie vorübergehend blockiert.',
            'security' => 'Ihr Passwort wurde nicht geändert. Falls Sie das nicht waren, können Sie diese Nachricht ignorieren; falls Sie sie weiterhin erhalten, kontaktieren Sie den Support.',
        ],
    ],
];
