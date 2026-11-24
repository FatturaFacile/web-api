<?php

declare(strict_types=1);

return [
    'obligation' => [
        'terminated_contract' => 'Un contratto terminato non accetta nuovi obblighi.',
        'item_locked' => 'Una voce in stato :status non può essere modificata.',
        'item_closed' => 'Una voce chiusa resta a registro.',
        'already_status' => 'Questa voce è già in stato :status.',
    ],
    'party' => [
        'role_exists' => 'Questa parte ha già questo ruolo nel contratto.',
    ],
    'clause' => [
        'retired_no_versions' => 'Una clausola ritirata non può avere nuove versioni.',
        'no_version_to_approve' => 'Una clausola senza versione non può essere approvata.',
        'retired_no_reuse' => 'Una clausola ritirata non può essere riutilizzata.',
        'rank_used' => 'Questo livello di alternativa è già in uso.',
        'no_fallback_rank' => 'La clausola non ha un\'alternativa con questo livello.',
    ],
    'lifecycle' => [
        'locked_by_workflow' => 'Questo contratto è bloccato da un flusso di approvazione: lo stato cambia al termine del flusso.',
        'status_outside' => 'Lo stato [:status] è fuori dal ciclo di vita del contratto e non può essere cambiato da qui.',
        'transition_invalid' => 'Un contratto non può passare da :from a :to.',
        'end_date_passed' => 'La data di fine del contratto è passata: rinnovalo con una nuova data di fine invece di attivarlo.',
    ],
    'register' => [
        'renew_status' => 'Un contratto in stato :status non può essere rinnovato.',
        'renew_no_end' => 'Questo contratto non ha una data di fine da prorogare.',
        'renew_no_term' => 'Questo contratto non ha una durata di rinnovo: indica la nuova data di fine.',
        'renew_end_after' => 'La nuova data di fine deve essere successiva a quella attuale.',
    ],
    'template' => [
        'unapproved_clauses' => 'In un modello attivo si possono usare solo clausole approvate: :codes.',
        'wrong_kind' => 'Il modello è per contratti di tipo :kind.',
        'inactive' => 'Si può usare solo un modello attivo.',
        'clause_not_approved' => 'Ogni clausola del modello deve essere approvata: :clause.',
        'undeclared_placeholders' => 'Segnaposto non dichiarati nelle clausole: :names.',
        'missing_variables' => 'Variabili obbligatorie mancanti: :names.',
    ],
    'redline' => [
        'same_contract' => 'Le due versioni devono appartenere allo stesso contratto.',
        'comment_resolved' => 'Il commento è già risolto.',
        'round_open' => 'Chiudi prima il giro di negoziazione aperto.',
        'round_closed' => 'Il giro è già chiuso.',
    ],
    'signature' => [
        'version_mismatch' => 'La versione non appartiene a questo contratto.',
        'pending_exists' => 'Questa versione ha già una richiesta di firma in corso.',
        'only_draft_send' => 'Si può inviare solo una richiesta in bozza.',
        'already_signed' => 'Questo firmatario ha già firmato.',
        'sign_in_order' => 'I firmatari firmano in ordine: un firmatario precedente non ha ancora firmato.',
        'only_open_cancel' => 'Si può annullare solo una richiesta in bozza o inviata.',
        'expired' => 'La richiesta è scaduta.',
        'not_open' => 'La richiesta non è in attesa di firme.',
        'token_invalid' => 'Il codice di firma non è valido.',
        'provider_unknown' => 'Fornitore di firma sconosciuto.',
        'link_invalid' => 'Questo link di firma non è valido.',
    ],
];
