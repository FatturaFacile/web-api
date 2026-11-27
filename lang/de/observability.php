<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Datensätze erfolgreich abgerufen.',
        'loaded' => 'Datensatz erfolgreich abgerufen.',
        'saved' => 'Die Observability-Einstellungen wurden gespeichert.',
        'probed' => 'Die institutionellen Konnektoren wurden geprüft.',
    ],
    'errors' => [
        'unknown_connector_title' => 'Unbekannter Konnektor',
        'unknown_connector' => 'Der institutionelle Konnektor existiert nicht.',
    ],
    'hints' => [
        'not_postgres' => 'pg_stat_statements ist nur unter PostgreSQL verfügbar; diese Umgebung verwendet eine andere Datenbank-Engine.',
        'extension_missing' => 'Die Erweiterung pg_stat_statements ist nicht aktiviert. Fügen Sie pg_stat_statements zu shared_preload_libraries hinzu, starten Sie PostgreSQL neu und führen Sie CREATE EXTENSION pg_stat_statements; in der Anwendungsdatenbank aus.',
        'permission_denied' => 'Der Datenbankbenutzer darf pg_stat_statements nicht lesen. Weisen Sie dem Anwendungsbenutzer die Rolle pg_read_all_stats zu.',
    ],
];
