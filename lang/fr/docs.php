<?php

declare(strict_types=1);

return [

    'ui' => [
        'title' => 'Documentation de l\'API FatturaFacile ERP',
    ],

    'errors' => [
        'disabled' => 'La documentation de l\'API n\'est pas disponible.',
        'spec_missing' => 'La spécification OpenAPI n\'a pas encore été générée. Exécutez : php artisan docs:export',
    ],

    'export' => [
        'yaml_missing' => 'Le paquet symfony/yaml est requis pour exporter la documentation (installez les dépendances de développement).',
        'generation_failed' => 'La spécification OpenAPI n\'a pas pu être générée.',
        'done' => 'Documentation exportée (:paths chemins) vers :directory.',
    ],

    'info' => [
        'title' => 'API FatturaFacile ERP',
        'summary' => 'API ERP multi-tenant pour la facturation, les achats, les stocks, la banque, la comptabilité et les opérations.',
        'description' => 'API REST de l\'ERP FatturaFacile.

L\'API est servie uniquement en HTTPS (le HTTP non chiffré est redirigé ou refusé).

**Authentification : cookie de session.** Depuis l\'application web : 1) `GET /sanctum/csrf-cookie` (définit le cookie `XSRF-TOKEN`), 2) `POST /v1/auth/login` avec les identifiants inclus et l\'en-tête `X-XSRF-TOKEN` (valeur du cookie `XSRF-TOKEN`) : la réponse définit le cookie de session HttpOnly + Secure et ne contient aucun jeton, 3) chaque requête suivante envoie le cookie, et chaque écriture envoie aussi `X-XSRF-TOKEN`. La requête doit provenir d\'une origine https figurant dans les domaines stateful de Sanctum (en-tête Origin ou Referer). Un jeton CSRF manquant ou incorrect produit un 419.

**Jeton Bearer : mode jeton optionnel.** Uniquement lorsque le serveur active `auth.token_mode.enabled` (désactivé par défaut) et que la connexion envoie `token_mode: true`, la connexion renvoie un jeton Bearer (`Authorization: Bearer <token>`) ; avec le paramètre désactivé, `token_mode: true` produit un 422 et les jetons Bearer ne sont pas acceptés comme identifiants utilisateur. Les clés d\'API d\'intégration constituent un mécanisme distinct.

Chaque requête, sauf la connexion et les webhooks entrants, doit en outre indiquer l\'organisation sur laquelle elle opère (`X-Tenant-Id: <uuid>`). Les erreurs suivent la RFC 9457 (`application/problem+json`).',
        'contact' => 'Support API FatturaFacile',
    ],

    'servers' => [
        'default' => 'URL de base de l\'API',
    ],

    'security' => [
        'cookie' => 'Authentification utilisateur par défaut : le cookie de session HttpOnly + Secure défini par `POST /v1/auth/login`. Appelez d\'abord `GET /sanctum/csrf-cookie` et envoyez la valeur du cookie `XSRF-TOKEN` dans l\'en-tête `X-XSRF-TOKEN` à chaque écriture.',
        'bearer' => 'Pertinent uniquement dans le mode jeton optionnel (paramètre serveur `auth.token_mode.enabled`, désactivé par défaut) : le jeton renvoyé par `POST /v1/auth/login` lorsqu\'il est appelé avec `token_mode: true`, envoyé sous la forme `Authorization: Bearer <token>`.',
        'tenant' => 'UUID de l\'organisation (tenant) sur laquelle la requête opère. L\'utilisateur doit en être membre.',
    ],

    'tags' => [
        'default' => 'Opérations du module :tag.',
    ],

    'postman' => [
        'description' => 'Collection Postman de l\'API FatturaFacile ERP. Définissez les variables baseUrl et frontendUrl, puis exécutez Auth > login : la collection récupère elle-même le cookie CSRF et envoie Origin, Referer et X-XSRF-TOKEN ; le cookie de session est conservé par Postman et le tenant actif dans tenantId. authToken n\'est utilisé que dans le mode jeton optionnel (connexion avec token_mode true).',
        'folders' => [
            'auth' => 'Authentification',
            'invoicing' => 'Facturation',
            'banking' => 'Banque',
            'accounting' => 'Comptabilité',
            'backups' => 'Sauvegardes',
        ],
    ],

];
