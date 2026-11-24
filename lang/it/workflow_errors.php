<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Delegante e delegato non possono essere lo stesso utente.',
        'circular' => 'Delega circolare rifiutata: l\'utente delegato ti ha già delegato.',
        'overlap' => 'Hai già una delega attiva che copre una parte di questo periodo.',
    ],
    'inbox' => [
        'period_too_long' => 'Il periodo non può superare i 366 giorni.',
    ],
    'definition' => [
        'publish_to_activate' => 'Pubblica la definizione per attivarla.',
        'has_running_instances' => 'La definizione ha istanze in corso: annullale oppure disattiva la definizione.',
        'graph_locked' => 'Solo una definizione in bozza può modificare il proprio grafo: crea una nuova versione.',
    ],
];
