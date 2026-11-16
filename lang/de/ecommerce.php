<?php

declare(strict_types=1);

return [

    'errors' => [
        'unsupported_driver' => 'Der E-Commerce-Treiber ":driver" wird nicht unterstützt.',
        'missing_configuration' => 'Dem Kanal fehlt eine erforderliche Einstellung: :field.',
        'http_error' => 'Der Shop hat auf :operation mit :status geantwortet.',
        'invalid_response' => 'Der Shop hat eine nicht interpretierbare Antwort geliefert (:operation).',
        'invalid_url' => 'Die URL muss eine öffentliche http- oder https-Adresse sein.',
        'credentials_required' => 'Die Zugangsdaten ":field" sind für den Treiber :driver erforderlich.',
        'channel_inactive' => 'Dieser Kanal ist nicht aktiv.',
        'sync_failed' => 'Die Synchronisierung mit dem Shop ist fehlgeschlagen.',
        'unknown_channel' => 'Der Kanal existiert nicht oder ist nicht aktiv.',
        'invalid_signature' => 'Die Webhook-Signatur ist ungültig.',
        'webhook_expired' => 'Die Webhook-Zustellung ist zu alt, um akzeptiert zu werden.',
    ],

    'order' => [
        'note' => 'E-Commerce-Bestellung :number aus :channel importiert',
        'shipping' => 'Versand: :method',
        'shipping_fallback' => 'Versand',
        'line_fallback' => 'Artikel :id',
    ],

    'problem_titles' => [
        'sync' => 'Shop-Synchronisierung fehlgeschlagen',
        'webhook' => 'Webhook abgelehnt',
    ],

    'messages' => [
        'channels' => 'E-Commerce-Kanäle erfolgreich abgerufen',
        'channel' => 'E-Commerce-Kanal erfolgreich abgerufen',
        'channel_created' => 'E-Commerce-Kanal erfolgreich erstellt',
        'channel_updated' => 'E-Commerce-Kanal erfolgreich aktualisiert',
        'channel_deleted' => 'E-Commerce-Kanal erfolgreich gelöscht',
        'connection_ok' => 'Die Verbindung zum Shop funktioniert.',
        'connection_failed' => 'Der Shop hat die Verbindung nicht akzeptiert. Prüfen Sie die URL und die Zugangsdaten.',
        'sync_done' => 'Synchronisierung abgeschlossen',
        'webhook_processed' => 'Webhook verarbeitet',
        'webhook_replayed' => 'Webhook bereits empfangen: ignoriert',
    ],

];
