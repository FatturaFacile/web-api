<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Enregistrements récupérés avec succès.',
        'loaded' => 'Enregistrement récupéré avec succès.',
        'saved' => 'Les paramètres d\'observabilité ont été enregistrés.',
        'probed' => 'Les connecteurs institutionnels ont été vérifiés.',
    ],
    'errors' => [
        'unknown_connector_title' => 'Connecteur inconnu',
        'unknown_connector' => 'Le connecteur institutionnel n\'existe pas.',
    ],
    'hints' => [
        'not_postgres' => 'pg_stat_statements n\'est disponible que sur PostgreSQL ; cet environnement utilise un autre moteur de base de données.',
        'extension_missing' => 'L\'extension pg_stat_statements n\'est pas activée. Ajoutez pg_stat_statements à shared_preload_libraries, redémarrez PostgreSQL et exécutez CREATE EXTENSION pg_stat_statements; dans la base de données de l\'application.',
        'permission_denied' => 'L\'utilisateur de la base de données ne peut pas lire pg_stat_statements. Attribuez le rôle pg_read_all_stats à l\'utilisateur de l\'application.',
    ],
];
