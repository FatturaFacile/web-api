<?php

declare(strict_types=1);

return [
    'mail' => [
        'access_subject' => 'Ihr Zugang zum Lieferantenportal',
        'access_line' => 'Oeffnen Sie das Lieferantenportal ueber den folgenden persoenlichen Link: Registrierung, Dokumente, Bestellungen, Offertanfragen und Rechnungen.',
    ],
    'notification' => [
        'expiring' => [
            'subject' => 'Dokument laeuft ab: :supplier',
            'body' => 'Das Dokument ":document" des Lieferanten :supplier laeuft am :date ab (in :days Tagen).',
        ],
        'expired' => [
            'subject' => 'Dokument abgelaufen: :supplier',
            'body' => 'Das Dokument ":document" des Lieferanten :supplier ist am :date abgelaufen.',
        ],
    ],
    'validation' => [
        'vat_number_invalid' => 'Die MWST- oder UID-Nummer ist fuer das angegebene Land nicht gueltig.',
    ],
];
