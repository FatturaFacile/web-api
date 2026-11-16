<?php

declare(strict_types=1);

return [

    'ui' => [
        'title' => 'API-Dokumentation von FatturaFacile ERP',
    ],

    'errors' => [
        'disabled' => 'Die API-Dokumentation ist nicht verfügbar.',
        'spec_missing' => 'Die OpenAPI-Spezifikation wurde noch nicht generiert. Führen Sie aus: php artisan docs:export',
    ],

    'export' => [
        'yaml_missing' => 'Das Paket symfony/yaml wird für den Export der Dokumentation benötigt (Entwicklungsabhängigkeiten installieren).',
        'generation_failed' => 'Die OpenAPI-Spezifikation konnte nicht generiert werden.',
        'done' => 'Dokumentation exportiert (:paths Pfade) nach :directory.',
    ],

    'info' => [
        'title' => 'FatturaFacile ERP API',
        'summary' => 'Mandantenfähige ERP-API für Fakturierung, Einkauf, Lager, Bankwesen, Buchhaltung und Betrieb.',
        'description' => 'REST-API des FatturaFacile ERP.

Die API wird ausschließlich über HTTPS bereitgestellt (unverschlüsseltes HTTP wird umgeleitet oder abgelehnt).

**Authentifizierung: Sitzungs-Cookie.** Aus der Webanwendung: 1) `GET /sanctum/csrf-cookie` (setzt das Cookie `XSRF-TOKEN`), 2) `POST /v1/auth/login` mit eingeschlossenen Anmeldedaten und dem Header `X-XSRF-TOKEN` (Wert des Cookies `XSRF-TOKEN`): die Antwort setzt das HttpOnly- und Secure-Sitzungs-Cookie und enthält kein Token, 3) jede weitere Anfrage sendet das Cookie, und jede schreibende Anfrage sendet zusätzlich `X-XSRF-TOKEN`. Die Anfrage muss von einem https-Origin stammen, der in den Sanctum-Stateful-Domains aufgeführt ist (Header Origin oder Referer). Ein fehlendes oder falsches CSRF-Token führt zu einem 419.

**Bearer-Token: optionaler Token-Modus.** Nur wenn der Server `auth.token_mode.enabled` aktiviert (standardmäßig deaktiviert) und der Login `token_mode: true` sendet, liefert der Login ein Bearer-Token (`Authorization: Bearer <token>`); bei deaktivierter Einstellung führt `token_mode: true` zu einem 422, und Bearer-Tokens werden nicht als Benutzer-Anmeldedaten akzeptiert. Integrations-API-Schlüssel sind ein separater Mechanismus.

Jede Anfrage außer Login und eingehenden Webhooks muss außerdem die Organisation angeben, in der sie arbeitet (`X-Tenant-Id: <uuid>`). Fehler folgen RFC 9457 (`application/problem+json`).',
        'contact' => 'FatturaFacile API-Support',
    ],

    'servers' => [
        'default' => 'Basis-URL der API',
    ],

    'security' => [
        'cookie' => 'Standard-Benutzerauthentifizierung: das HttpOnly- und Secure-Sitzungs-Cookie, das von `POST /v1/auth/login` gesetzt wird. Rufen Sie zuerst `GET /sanctum/csrf-cookie` auf und senden Sie den Wert des Cookies `XSRF-TOKEN` bei jeder schreibenden Anfrage im Header `X-XSRF-TOKEN`.',
        'bearer' => 'Nur im optionalen Token-Modus relevant (Servereinstellung `auth.token_mode.enabled`, standardmäßig deaktiviert): das Token, das `POST /v1/auth/login` bei Aufruf mit `token_mode: true` zurückgibt, gesendet als `Authorization: Bearer <token>`.',
        'tenant' => 'UUID der Organisation (Mandant), in der die Anfrage arbeitet. Der Benutzer muss Mitglied davon sein.',
    ],

    'tags' => [
        'default' => 'Operationen des Moduls :tag.',
    ],

    'postman' => [
        'description' => 'Postman-Collection der FatturaFacile ERP API. Setzen Sie die Variablen baseUrl und frontendUrl und führen Sie dann Auth > login aus: Die Collection holt das CSRF-Cookie selbst und sendet Origin, Referer und X-XSRF-TOKEN; das Sitzungs-Cookie wird von Postman verwaltet, der aktive Mandant steht in tenantId. authToken wird nur im optionalen Token-Modus verwendet (Login mit token_mode true).',
        'folders' => [
            'auth' => 'Authentifizierung',
            'invoicing' => 'Fakturierung',
            'banking' => 'Bankwesen',
            'accounting' => 'Buchhaltung',
            'backups' => 'Backups',
        ],
    ],

];
