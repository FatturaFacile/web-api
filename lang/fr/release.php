<?php

declare(strict_types=1);

return [

    'title' => 'Préparation à la production de la version :version',

    'status' => [
        'ok' => 'OK',
        'warning' => 'AVERTISSEMENT',
        'fail' => 'ÉCHEC',
    ],

    'checks' => [
        'environment' => "L'environnement est la production (APP_ENV=:value)",
        'debug' => 'Le mode debug est désactivé (APP_DEBUG)',
        'key' => "La clé de l'application est définie (APP_KEY)",
        'queue' => "La connexion de la file d'attente est asynchrone (QUEUE_CONNECTION=:value)",
        'cache' => 'Le magasin de cache est partagé entre les processus (CACHE_STORE=:value)',
        'scheduler' => 'Le planificateur inclut la sauvegarde nocturne (tenants:backup --all)',
        'backup_disk' => 'Le disque de sauvegarde est configuré (:disk)',
        'storage' => 'Les répertoires de stockage sont accessibles en écriture',
        'database' => 'La base de données est joignable et migrée',
        'docs' => 'La spécification OpenAPI est générée (docs/api/openapi.json)',
        'config_cache' => 'La configuration est en cache (php artisan config:cache)',
        'route_cache' => 'Les routes sont en cache (php artisan route:cache)',
    ],

    'summary' => ':failed échec(s), :warnings avertissement(s), :ok réussi(s).',
    'ready' => 'Prête pour la mise en production.',
    'not_ready' => 'Pas prête : corrigez les contrôles en échec avant le déploiement.',

];
