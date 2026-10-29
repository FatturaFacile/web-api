<?php

declare(strict_types=1);

return [
    'messages' => [
        'instance_started' => 'Flusso di approvazione avviato con successo',
        'decision_recorded' => 'Decisione di approvazione registrata con successo',
        'step_approved' => 'Passaggio approvato con successo',
        'step_rejected' => 'Passaggio rifiutato',
        'instance_completed' => 'Flusso di approvazione completato con esito positivo',
        'instance_cancelled' => 'Flusso di approvazione annullato',
        'token_validated' => 'Token di approvazione remota convalidato',
    ],
    'errors' => [
        'unauthorized_approver' => 'L\'utente non dispone delle deleghe o permessi necessari per approvare questo passaggio.',
        'invalid_state' => 'Il flusso non si trova in uno stato valido per la decisione richiesta.',
    ],
];
