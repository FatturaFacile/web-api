<?php

declare(strict_types=1);

return [
    'export' => [
        'pdf' => [
            'title' => 'Export des Audit-Ledgers - Zusammenfassung',
            'tenant' => 'Unternehmen',
            'period' => 'Zeitraum',
            'entries' => 'Einträge',
            'sequences' => 'Sequenzen',
            'start_hash' => 'Hash des ersten Eintrags',
            'end_hash' => 'Hash des letzten Eintrags',
            'verification' => 'Prüfung der Kette',
            'generated' => 'Erstellt am',
            'note' => 'Der SHA-256 dieses Pakets ist signiert. Prüfen Sie die Kette mit den Hashes in manifest.json.',
        ],
    ],
    'alerts' => [
        'chain_broken' => [
            'title' => 'Alarm zur Integrität des Audit-Trails',
            'body' => 'Die Prüfung der Audit-Kette ist fehlgeschlagen (:outcome, Sequenz :sequence). Öffnen Sie die Audit-Einstellungen und starten Sie das Incident-Verfahren.',
        ],
    ],
];
