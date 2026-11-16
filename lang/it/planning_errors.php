<?php

declare(strict_types=1);

return [
    'scenario' => [
        'locked' => 'Uno scenario bloccato non può essere modificato.',
        'not_draft' => 'Si può modificare solo uno scenario in bozza: questo è già in revisione, approvato o bloccato.',
        'horizon_order' => 'La fine dell\'orizzonte non può precedere l\'inizio.',
        'horizon_too_long' => 'L\'orizzonte non può superare :months mesi.',
        'lines_outside_horizon' => 'Alcune righe del piano sono fuori dal nuovo orizzonte: rimuovile prima.',
        'published' => 'Lo scenario è stato pubblicato e non può essere eliminato né riportato in bozza.',
        'baseline_exists' => 'L\'azienda ha già uno scenario di base: crea uno scenario what-if o copia quello di base.',
    ],
    'resource' => [
        'code_taken' => 'Il codice risorsa :code è già in uso.',
        'rate_exists' => 'L\'articolo ha già un carico su questa risorsa.',
        'in_use' => 'La risorsa ha vincoli di capacità in scenari non più in bozza e non può essere eliminata.',
    ],
    'line' => [
        'outside_horizon' => 'Il mese è fuori dall\'orizzonte dello scenario.',
        'duplicate' => 'Esiste già una riga per questo lato, articolo e mese.',
        'item_missing' => 'L\'articolo non esiste in questa azienda.',
        'item_required' => 'Scegli un articolo o inserisci un codice articolo.',
    ],
    'cycle' => [
        'empty' => 'Uno scenario senza righe di piano non può essere inviato.',
        'capacity_overloaded' => ':count mesi di risorse sono sovraccarichi: correggi il piano o accetta il sovraccarico con un commento.',
        'overload_comment' => 'Accettare un sovraccarico di capacità richiede un commento.',
        'reason_required' => 'Serve un commento con il motivo.',
        'invalid_transition' => 'Lo scenario è :status: l\'azione :action non è consentita.',
        'not_publishable' => 'Si può pubblicare solo uno scenario approvato o bloccato.',
    ],
];
