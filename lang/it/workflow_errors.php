<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Delegante e delegato non possono essere lo stesso utente.',
        'circular' => 'Delega circolare rifiutata: l\'utente delegato ti ha già delegato.',
        'cycle' => 'Delega rifiutata: chiuderebbe una catena di deleghe (A a B a C e ritorno).',
        'overlap' => 'Hai già una delega attiva che copre una parte di questo periodo.',
    ],
    'control' => [
        'self_approval' => 'Chi richiede un\'approvazione non può approvarla.',
        'multi_level' => 'Lo stesso utente non può approvare due livelli dello stesso flusso.',
        'signing_limit' => 'L\'importo supera il tuo limite di firma (:limit).',
    ],
    'lifecycle' => [
        'not_active' => 'L\'approvazione è già :status e non può più essere modificata.',
        'withdraw_not_requester' => 'Solo chi ha richiesto l\'approvazione può ritirarla.',
        'withdraw_already_decided' => 'L\'approvazione non può essere ritirata: è già stata presa una decisione.',
        'reason_required' => 'Il motivo è obbligatorio.',
        'reassign_target' => 'Il nuovo approvatore deve essere membro dell\'azienda.',
        'reassign_not_allowed' => 'Questo passo non può essere riassegnato.',
        'document_changed' => 'Il documento è cambiato dopo l\'approvazione (:fields): l\'approvazione è ripartita.',
        'blocked_no_approver' => 'Nessun approvatore risolvibile per il passo ":node".',
        'blocked_no_matrix_rule' => 'Nessuna regola di approvazione corrisponde al documento e non è configurata una regola predefinita.',
    ],
    'token' => [
        'invalid' => 'Questo link di approvazione non è valido.',
        'used' => 'Questo link di approvazione è già stato usato.',
        'expired' => 'Questo link di approvazione è scaduto.',
        'wrong_user' => 'Questo link di approvazione è stato emesso per un altro utente.',
        'wrong_action' => 'Questo link di approvazione non consente questa azione.',
        'confirm_required' => 'Conferma la decisione nella pagina di conferma.',
        'reauth_required' => 'Accedi di nuovo per approvare questo importo.',
        'reauth_failed' => 'La password o il codice di verifica non è corretto.',
    ],
    'inbox' => [
        'period_too_long' => 'Il periodo non può superare i 366 giorni.',
    ],
    'definition' => [
        'publish_to_activate' => 'Pubblica la definizione per attivarla.',
        'has_running_instances' => 'La definizione ha istanze in corso: annullale oppure disattiva la definizione.',
        'graph_locked' => 'Solo una definizione in bozza può modificare il proprio grafo: crea una nuova versione.',
        'stale' => 'La definizione è stata modificata da qualcun altro: ricaricala e riapplica le modifiche.',
    ],
    'matrix' => [
        'unknown_subject' => 'Tipo di documento sconosciuto.',
        'tier_range' => 'Il limite inferiore di un livello non può superare il superiore.',
        'needs_tier' => 'Una matrice richiede almeno un livello o un approvatore di ripiego.',
    ],
    'calendar' => [
        'unknown_country' => 'I giorni festivi si possono generare solo per CH e IT.',
    ],
];
