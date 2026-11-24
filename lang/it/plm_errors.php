<?php

declare(strict_types=1);

return [
    'order' => [
        'approvers_required' => 'Aggiungi almeno un approvatore prima dell’invio.',
        'affected_required' => 'Aggiungi almeno un articolo o una distinta base interessati prima dell’invio.',
        'not_in_review' => 'La modifica tecnica non è in revisione.',
        'cannot_cancel' => 'Si può annullare solo una modifica tecnica in bozza, in revisione o approvata.',
        'not_approved' => 'Solo una modifica tecnica approvata può essere implementata.',
        'not_draft' => 'Solo una modifica tecnica in bozza può essere cambiata.',
        'code_taken' => 'Il codice :code è già usato da un’altra modifica tecnica.',
    ],
    'approval' => [
        'not_your_turn' => 'Non è il tuo turno di decidere su questa modifica tecnica.',
        'reject_comment_required' => 'Un rifiuto richiede un commento.',
    ],
    'revision' => [
        'exists' => 'La revisione :revision esiste già per questo articolo.',
        'change_order_required' => 'L’articolo ha già una revisione rilasciata: rilascia la nuova implementando una modifica tecnica.',
        'not_released' => 'Solo una revisione rilasciata può essere resa obsoleta.',
        'not_draft' => 'Solo una revisione in bozza può essere modificata o rilasciata.',
    ],
];
