<?php

declare(strict_types=1);

return [
    'installation' => [
        'unknown_connector' => 'Ce connecteur n\'est pas dans le catalogue.',
        'limit' => 'Une entreprise peut avoir au plus :max installations du même connecteur.',
        'name_taken' => 'Une installation de ce connecteur avec ce nom existe déjà.',
        'scopes_missing' => 'Le connecteur exige que ces périmètres soient accordés : :scopes.',
        'scope_not_allowed' => 'Le périmètre ":scope" n\'est pas valide ou vous ne pouvez pas l\'accorder.',
        'not_configured' => 'Configurez d\'abord l\'installation.',
        'unhealthy' => 'Le contrôle d\'état a échoué, l\'installation n\'a donc pas été activée : :message',
        'up_to_date' => 'L\'installation utilise déjà la dernière version du connecteur.',
        'run_in_progress' => 'Une synchronisation est en cours : attendez qu\'elle se termine avant de désinstaller.',
        'rate_limit_above_max' => 'La limite ne peut pas dépasser :max appels par minute, le maximum de ce connecteur.',
    ],
    'config' => [
        'unknown_field' => 'Ce champ ne fait pas partie de la configuration du connecteur.',
        'required' => 'Le champ ":field" est obligatoire.',
        'invalid_text' => 'Le champ ":field" doit être un texte d\'au plus 255 caractères.',
        'invalid_secret' => 'Le champ ":field" doit être un secret d\'au moins 4 caractères.',
        'invalid_number' => 'Le champ ":field" doit être un nombre.',
        'invalid_boolean' => 'Le champ ":field" doit être vrai ou faux.',
        'invalid_option' => 'Le champ ":field" a une valeur non autorisée.',
        'invalid_url' => 'Le champ ":field" doit être une adresse https publique.',
    ],
    'health' => [
        'unreachable' => 'Le système externe n\'a pas répondu.',
        'address_refused' => 'L\'adresse du système externe n\'est pas autorisée.',
        'egress_blocked' => 'La politique de résidence des données de l\'entreprise n\'autorise pas l\'envoi de données vers le pays du système externe.',
        'failed' => 'Le contrôle d\'état n\'a pas pu être terminé.',
        'credentials_rejected' => 'Le système externe a refusé les identifiants.',
        'rate_limited' => 'Le système externe limite les requêtes.',
        'server_error' => 'Le système externe a répondu par une erreur (:status).',
        'unexpected_status' => 'Le système externe a répondu avec un statut inattendu (:status).',
    ],
    'sync' => [
        'not_enabled' => 'Activez l\'installation avant de la synchroniser.',
        'unknown_resource' => 'Ce connecteur n\'a pas de ressource ":resource".',
        'rate_limited' => 'La limite de requêtes de l\'installation est atteinte : réessayez dans :seconds secondes.',
        'already_running' => 'Cette ressource est déjà en cours de synchronisation.',
        'stale' => 'L\'exécution ne s\'est jamais terminée et a été fermée par la suivante.',
        'failed' => 'La synchronisation a échoué.',
        'status' => 'Le système externe a répondu par une erreur (:status).',
        'credential_missing' => 'L\'identifiant ":field" est manquant : configurez à nouveau l\'installation.',
        'fetched' => ':count enregistrements de :resource récupérés.',
        'delivered' => 'L\'événement a été livré.',
    ],
    'mapping' => [
        'missing_field' => 'Le champ ":field" est manquant.',
        'invalid_amount' => 'Le montant ":field" n\'est pas un nombre.',
        'invalid_currency' => 'La devise n\'est pas un code ISO valide.',
        'invalid_date' => 'La date n\'est pas valide.',
        'invalid_lines' => 'Les lignes de la commande ne sont pas valides.',
    ],
];
