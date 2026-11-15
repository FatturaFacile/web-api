<?php

declare(strict_types=1);

return [
    'idempotency' => [
        'title' => 'Problema con la chiave di idempotenza',
        'invalid_key' => 'L\'intestazione Idempotency-Key deve essere una stringa non vuota di al massimo 255 caratteri.',
        'key_reused' => 'Questa Idempotency-Key è già stata usata per una richiesta diversa.',
        'in_progress' => 'Una richiesta con questa Idempotency-Key è ancora in elaborazione.',
    ],
    'conflict' => [
        'title' => 'Conflitto di modifica',
        'detail' => 'Il record è stato modificato da qualcun altro dopo che lo hai aperto. Ricaricalo e applica di nuovo le modifiche.',
    ],
    'recycle_bin' => [
        'not_purgeable' => 'Questo tipo di record va conservato per obbligo di legge e non può essere eliminato definitivamente.',
        'in_use' => 'Il record è ancora referenziato da altri record e non può essere eliminato definitivamente.',
    ],
];
