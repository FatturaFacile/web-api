<?php

declare(strict_types=1);

return [
    'password' => [
        'breached' => 'Dieses Passwort taucht in bekannten Datenlecks auf: Wählen Sie ein anderes.',
    ],
    'messages' => [
        'member_unlocked' => 'Das Konto wurde entsperrt.',
        'scim_mapping_saved' => 'Gruppenzuordnung gespeichert.',
        'scim_mapping_removed' => 'Gruppenzuordnung entfernt.',
        'security_audit_retrieved' => 'Sicherheits-Auditprotokoll abgerufen.',
    ],
    'errors' => [
        'lockout_cap_too_small' => 'Die maximale Sperrdauer darf nicht kürzer sein als die Basissperrdauer.',
        'unknown_event_type' => 'Unbekannter Ereignistyp: :type.',
    ],
    'mail' => [
        'unknown' => 'unbekannt',
        'locked' => [
            'subject' => ':app: Ihr Konto wurde gesperrt',
            'intro' => 'Ihr :app-Konto wurde nach zu vielen falschen Passwörtern für :minutes Minuten gesperrt.',
            'until' => 'Gesperrt bis: :date',
            'ip' => 'IP-Adresse der Versuche: :ip',
            'device' => 'Gerät: :device',
            'security' => 'Wenn Sie das nicht waren, versucht möglicherweise jemand, Ihr Passwort zu erraten: Ändern Sie es, sobald die Sperre endet, und aktivieren Sie die Zwei-Faktor-Authentifizierung. Wiederholte Sperren verlängern die nächste Sperre.',
        ],
        'unlocked' => [
            'subject' => ':app: Ihr Konto wurde entsperrt',
            'intro' => 'Ein Administrator Ihrer Organisation hat Ihr :app-Konto entsperrt: Sie können sich wieder anmelden.',
            'security' => 'Wenn Sie das nicht veranlasst haben, wenden Sie sich an den Administrator Ihrer Organisation.',
        ],
    ],
];
