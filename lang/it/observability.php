<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Record recuperati con successo.',
        'loaded' => 'Record recuperato con successo.',
        'saved' => 'Le impostazioni di osservabilità sono state salvate.',
        'probed' => 'I connettori istituzionali sono stati verificati.',
    ],
    'errors' => [
        'unknown_connector_title' => 'Connettore sconosciuto',
        'unknown_connector' => 'Il connettore istituzionale non esiste.',
    ],
    'hints' => [
        'not_postgres' => 'pg_stat_statements è disponibile solo su PostgreSQL; questo ambiente usa un altro motore di database.',
        'extension_missing' => 'L\'estensione pg_stat_statements non è abilitata. Aggiungi pg_stat_statements a shared_preload_libraries, riavvia PostgreSQL ed esegui CREATE EXTENSION pg_stat_statements; nel database dell\'applicazione.',
        'permission_denied' => 'L\'utente del database non può leggere pg_stat_statements. Assegna il ruolo pg_read_all_stats all\'utente dell\'applicazione.',
    ],
];
