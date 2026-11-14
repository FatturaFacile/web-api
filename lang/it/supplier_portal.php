<?php

declare(strict_types=1);

return [
    'mail' => [
        'access_subject' => 'Il tuo accesso al portale fornitori',
        'access_line' => 'Usa il link personale seguente per accedere al portale fornitori: registrazione, documenti, ordini, richieste di offerta e fatture.',
    ],
    'notification' => [
        'expiring' => [
            'subject' => 'Documento in scadenza: :supplier',
            'body' => 'Il documento ":document" del fornitore :supplier scade il :date (tra :days giorni).',
        ],
        'expired' => [
            'subject' => 'Documento scaduto: :supplier',
            'body' => 'Il documento ":document" del fornitore :supplier e scaduto il :date.',
        ],
    ],
    'validation' => [
        'vat_number_invalid' => 'Il numero di partita IVA o UID non e valido per il paese indicato.',
    ],
];
