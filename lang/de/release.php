<?php

declare(strict_types=1);

return [

    'title' => 'Produktionsreife des Release :version',

    'status' => [
        'ok' => 'OK',
        'warning' => 'WARNUNG',
        'fail' => 'FEHLER',
    ],

    'checks' => [
        'environment' => 'Die Umgebung ist Produktion (APP_ENV=:value)',
        'debug' => 'Der Debug-Modus ist ausgeschaltet (APP_DEBUG)',
        'key' => 'Der Anwendungsschlüssel ist gesetzt (APP_KEY)',
        'queue' => 'Die Queue-Verbindung ist asynchron (QUEUE_CONNECTION=:value)',
        'cache' => 'Der Cache-Store wird von allen Prozessen gemeinsam genutzt (CACHE_STORE=:value)',
        'scheduler' => 'Der Scheduler enthält das nächtliche Backup (tenants:backup --all)',
        'backup_disk' => 'Der Backup-Datenträger ist konfiguriert (:disk)',
        'storage' => 'Die Storage-Verzeichnisse sind beschreibbar',
        'database' => 'Die Datenbank ist erreichbar und migriert',
        'docs' => 'Die OpenAPI-Spezifikation ist erzeugt (docs/api/openapi.json)',
        'config_cache' => 'Die Konfiguration ist im Cache (php artisan config:cache)',
        'route_cache' => 'Die Routen sind im Cache (php artisan route:cache)',
    ],

    'summary' => ':failed fehlgeschlagen, :warnings Warnung(en), :ok bestanden.',
    'ready' => 'Bereit für das Release.',
    'not_ready' => 'Nicht bereit: Behebe die fehlgeschlagenen Prüfungen vor dem Deployment.',

];
