<?php

declare(strict_types=1);

return [

    'ui' => [
        'title' => 'Documentazione API di FatturaFacile ERP',
    ],

    'errors' => [
        'disabled' => 'La documentazione API non è disponibile.',
        'spec_missing' => 'La specifica OpenAPI non è ancora stata generata. Esegui: php artisan docs:export',
    ],

    'export' => [
        'yaml_missing' => 'Il pacchetto symfony/yaml è necessario per esportare la documentazione (installa le dipendenze di sviluppo).',
        'generation_failed' => 'Non è stato possibile generare la specifica OpenAPI.',
        'done' => 'Documentazione esportata (:paths percorsi) in :directory.',
    ],

    'info' => [
        'title' => 'API di FatturaFacile ERP',
        'summary' => 'API ERP multi-tenant per fatturazione, acquisti, magazzino, banca, contabilità e operatività.',
        'description' => 'API REST dell\'ERP FatturaFacile.

L\'API è servita solo tramite HTTPS (l\'HTTP non cifrato viene reindirizzato o rifiutato).

**Autenticazione: cookie di sessione.** Dall\'applicazione web: 1) `GET /sanctum/csrf-cookie` (imposta il cookie `XSRF-TOKEN`), 2) `POST /v1/auth/login` con le credenziali incluse e l\'header `X-XSRF-TOKEN` (valore del cookie `XSRF-TOKEN`): la risposta imposta il cookie di sessione HttpOnly + Secure e non contiene alcun token, 3) ogni richiesta successiva invia il cookie, e ogni scrittura invia anche `X-XSRF-TOKEN`. La richiesta deve provenire da un\'origine https presente nei domini stateful di Sanctum (header Origin o Referer). Un token CSRF mancante o errato produce un 419.

**Token Bearer: modalità token opzionale.** Solo quando il server abilita `auth.token_mode.enabled` (disattivato per impostazione predefinita) e il login invia `token_mode: true`, il login restituisce un token Bearer (`Authorization: Bearer <token>`); con l\'impostazione disattivata `token_mode: true` produce un 422 e i token Bearer non sono accettati come credenziali utente. Le chiavi API di integrazione sono un meccanismo separato.

Ogni richiesta, tranne il login e i webhook in ingresso, deve inoltre indicare l\'organizzazione su cui opera (`X-Tenant-Id: <uuid>`). Gli errori seguono la RFC 9457 (`application/problem+json`).',
        'contact' => 'Supporto API FatturaFacile',
    ],

    'servers' => [
        'default' => 'URL di base dell\'API',
    ],

    'security' => [
        'cookie' => 'Autenticazione utente predefinita: il cookie di sessione HttpOnly + Secure impostato da `POST /v1/auth/login`. Chiama prima `GET /sanctum/csrf-cookie` e invia il valore del cookie `XSRF-TOKEN` nell\'header `X-XSRF-TOKEN` a ogni scrittura.',
        'bearer' => 'Rilevante solo nella modalità token opzionale (impostazione del server `auth.token_mode.enabled`, disattivata per impostazione predefinita): il token restituito da `POST /v1/auth/login` quando viene chiamato con `token_mode: true`, inviato come `Authorization: Bearer <token>`.',
        'tenant' => 'UUID dell\'organizzazione (tenant) su cui opera la richiesta. L\'utente deve esserne membro.',
    ],

    'tags' => [
        'default' => 'Operazioni del modulo :tag.',
    ],

    'postman' => [
        'description' => 'Collezione Postman dell\'API FatturaFacile ERP. Imposta le variabili baseUrl e frontendUrl, poi esegui Auth > login: la collezione recupera da sola il cookie CSRF e invia Origin, Referer e X-XSRF-TOKEN; il cookie di sessione è mantenuto da Postman e il tenant attivo in tenantId. authToken è usato solo nella modalità token opzionale (login con token_mode true).',
        'folders' => [
            'auth' => 'Autenticazione',
            'invoicing' => 'Fatturazione',
            'banking' => 'Banca',
            'accounting' => 'Contabilità',
            'backups' => 'Backup',
        ],
    ],

];
