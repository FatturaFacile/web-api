<?php

declare(strict_types=1);

return [
    'agreement' => [
        'not_active' => 'L’accordo non è attivo.',
        'locked' => 'Di un accordo non più in bozza si possono modificare solo il nome, le note e la data di fine.',
        'period' => 'La data di fine non può precedere la data di inizio.',
        'not_draft' => 'Solo un accordo in bozza può essere attivato.',
        'tiers_required' => 'Aggiungi almeno uno scaglione prima di attivare l’accordo.',
        'delete_draft_only' => 'Si può eliminare solo un accordo in bozza senza accantonamenti.',
        'code_taken' => 'Il codice :code è già usato da un altro accordo.',
        'tiers_distinct' => 'Ogni scaglione richiede una soglia diversa.',
    ],
    'accrual' => [
        'date_outside' => 'La data deve rientrare nel periodo dell’accordo (da :from a :to).',
    ],
    'settlement' => [
        'nothing_to_settle' => 'Non ci sono premi maturati da liquidare.',
        'tax_rate_required' => 'Indica l’aliquota IVA della nota di credito.',
        'credit_note_note' => 'Premio di fine anno :name (:code)',
        'credit_note_line' => 'Premio di fine anno :name, periodo dal :from al :to',
    ],
    'promotion' => [
        'percentage_max' => 'Uno sconto percentuale non può superare 100.',
        'period' => 'La data di fine non può precedere la data di inizio.',
        'code_taken' => 'Il codice :code è già usato da un’altra promozione.',
    ],
];
