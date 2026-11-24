<?php

declare(strict_types=1);

return [

    'errors' => [
        'unsupported_driver' => 'Il driver e-commerce ":driver" non è supportato.',
        'missing_configuration' => 'Al canale manca un\'impostazione obbligatoria: :field.',
        'http_error' => 'Il negozio ha risposto :status a :operation.',
        'invalid_response' => 'Il negozio ha restituito una risposta non interpretabile (:operation).',
        'invalid_url' => 'L\'URL deve essere un indirizzo http o https pubblico.',
        'credentials_required' => 'La credenziale ":field" è obbligatoria per il driver :driver.',
        'channel_inactive' => 'Questo canale non è attivo.',
        'sync_failed' => 'La sincronizzazione con il negozio non è riuscita.',
        'unknown_channel' => 'Il canale non esiste o non è attivo.',
        'invalid_signature' => 'La firma del webhook non è valida.',
        'webhook_expired' => 'L\'invio del webhook è troppo vecchio per essere accettato.',
    ],

    'order' => [
        'note' => 'Ordine e-commerce :number importato da :channel',
        'shipping' => 'Spedizione: :method',
        'shipping_fallback' => 'Spedizione',
        'line_fallback' => 'Articolo :id',
    ],

    'problem_titles' => [
        'sync' => 'Sincronizzazione del negozio non riuscita',
        'webhook' => 'Webhook rifiutato',
    ],

    'messages' => [
        'channels' => 'Canali e-commerce recuperati con successo',
        'channel' => 'Canale e-commerce recuperato con successo',
        'channel_created' => 'Canale e-commerce creato con successo',
        'channel_updated' => 'Canale e-commerce aggiornato con successo',
        'channel_deleted' => 'Canale e-commerce eliminato con successo',
        'connection_ok' => 'La connessione al negozio funziona.',
        'connection_failed' => 'Il negozio non ha accettato la connessione. Controlla l\'URL e le credenziali.',
        'sync_done' => 'Sincronizzazione completata',
        'webhook_processed' => 'Webhook elaborato',
        'webhook_replayed' => 'Webhook già ricevuto: ignorato',
    ],

];
