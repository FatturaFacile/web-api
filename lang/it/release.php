<?php

declare(strict_types=1);

return [

    'title' => 'Prontezza alla produzione della release :version',

    'status' => [
        'ok' => 'OK',
        'warning' => 'AVVISO',
        'fail' => 'ERRORE',
    ],

    'checks' => [
        'environment' => "L'ambiente è di produzione (APP_ENV=:value)",
        'debug' => 'La modalità debug è disattivata (APP_DEBUG)',
        'key' => "La chiave dell'applicazione è impostata (APP_KEY)",
        'queue' => 'La connessione della coda è asincrona (QUEUE_CONNECTION=:value)',
        'cache' => 'Lo store della cache è condiviso tra i processi (CACHE_STORE=:value)',
        'scheduler' => 'Lo scheduler include il backup notturno (tenants:backup --all)',
        'backup_disk' => 'Il disco di backup è configurato (:disk)',
        'storage' => 'Le cartelle di storage sono scrivibili',
        'database' => 'Il database è raggiungibile e migrato',
        'docs' => 'La specifica OpenAPI è generata (docs/api/openapi.json)',
        'config_cache' => 'La configurazione è in cache (php artisan config:cache)',
        'route_cache' => 'Le rotte sono in cache (php artisan route:cache)',
    ],

    'summary' => ':failed non riusciti, :warnings avvisi, :ok superati.',
    'ready' => 'Pronta per il rilascio.',
    'not_ready' => 'Non pronta: correggi i controlli falliti prima del deploy.',

];
