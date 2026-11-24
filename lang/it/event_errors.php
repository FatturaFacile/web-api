<?php

declare(strict_types=1);

return [
    'event' => [
        'not_published' => 'L’evento non è aperto alle iscrizioni.',
        'full' => 'L’evento è al completo.',
        'period' => 'La fine non può precedere l’inizio.',
        'capacity_below_confirmed' => 'La capienza non può essere inferiore alle iscrizioni confermate.',
        'not_draft' => 'Solo un evento in bozza può essere pubblicato.',
        'ticket_required' => 'Aggiungi almeno un tipo di biglietto attivo prima di pubblicare.',
        'cannot_cancel' => 'Solo un evento in bozza o pubblicato può essere annullato.',
        'delete_draft_only' => 'Si può eliminare solo un evento in bozza senza iscrizioni.',
        'code_taken' => 'Il codice :code è già usato da un altro evento.',
    ],
    'ticket' => [
        'unavailable' => 'Il tipo di biglietto non esiste o non è in vendita.',
        'has_registrations' => 'Il tipo di biglietto ha iscrizioni: disattivalo invece di eliminarlo.',
    ],
    'registration' => [
        'already_cancelled' => 'L’iscrizione è già annullata.',
        'checked_in' => 'Un’iscrizione con check-in effettuato non può essere annullata.',
        'not_confirmed' => 'Solo un’iscrizione confermata può essere pagata.',
    ],
    'checkin' => [
        'unknown_token' => 'Nessuna iscrizione corrisponde a questo codice QR.',
        'not_confirmed' => 'Solo un’iscrizione confermata può fare il check-in.',
        'already' => 'Questo partecipante ha già fatto il check-in alle :at.',
    ],
];
