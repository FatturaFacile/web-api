<?php

declare(strict_types=1);

return [
    'status' => [
        'unknown' => 'Stato :status sconosciuto.',
        'not_allowed' => 'Lo stato non può passare da :from a :to: consentiti :allowed.',
    ],
    'risk' => [
        'closed' => 'Il rischio è chiuso: riaprilo per modificarlo.',
        'owner_required' => 'Un rischio ha bisogno di un responsabile prima della valutazione.',
        'active_control_required' => 'Un rischio entra in trattamento solo se mitigato da un controllo attivo: collega prima un controllo.',
        'acceptance_notes_required' => 'Accettare un rischio richiede la motivazione nelle note.',
        'residual_exceeds_inherent' => 'Probabilità e impatto residui non possono superare quelli inerenti.',
        'open_findings' => 'Un rilievo aperto fa riferimento a questo rischio: chiudi o riassegna prima il rilievo.',
    ],
    'control' => [
        'test_needs_active' => 'Il controllo è :status: si testa solo un controllo attivo.',
        'finding_title' => 'Test del controllo non superato: :code :title',
    ],
    'plan' => [
        'closed' => 'Il piano di audit è chiuso.',
        'year_locked' => 'L\'anno di un piano che ha audit non può cambiare.',
        'audit_required' => 'Un piano ha bisogno di almeno un audit prima dell\'approvazione.',
        'audits_unfinished' => 'Ogni audit deve essere chiuso o annullato prima di chiudere il piano: :codes.',
    ],
    'audit' => [
        'locked' => 'L\'audit è :status e non può essere modificato.',
        'only_team_and_conclusion' => 'L\'audit è :status: possono cambiare solo il team e la conclusione.',
        'dates_inverted' => 'La fine prevista non può precedere l\'inizio previsto.',
        'plan_not_approved' => 'Il piano di audit è :status: deve essere approvato prima dell\'avvio di un audit.',
        'conclusion_required' => 'Un audit si chiude con una conclusione.',
        'untreated_findings' => 'Rilievi alti e critici ancora aperti: :codes.',
        'cancellation_reason_required' => 'Annullare un audit richiede il motivo nelle note.',
        'outside_plan_year' => 'L\'audit deve iniziare nell\'anno del piano (:year).',
    ],
    'finding' => [
        'audit_not_in_fieldwork' => 'L\'audit è :status: i rilievi si registrano mentre è in corso o in rendicontazione.',
        'locked' => 'Il rilievo è :status e non può essere modificato.',
        'action_required' => 'Registra prima almeno un\'azione correttiva.',
        'acceptance_reason_required' => 'Accettare il rischio di un rilievo richiede il motivo nelle note.',
        'closure_notes_required' => 'Chiudere un rilievo richiede le note di chiusura.',
        'live_actions' => 'Annulla o completa le azioni correttive prima di riaprire il rilievo.',
        'actions_unverified' => 'Ogni azione correttiva deve essere verificata o annullata (almeno una verificata): :codes.',
    ],
    'action' => [
        'finding_closed' => 'Il rilievo è :status: non si possono registrare nuove azioni.',
        'locked' => 'L\'azione è :status e non può essere modificata.',
        'segregation_of_duties' => 'L\'azione non può essere verificata da chi l\'ha completata.',
        'rejection_notes_required' => 'Rimandare indietro un\'azione richiede il motivo nelle note.',
        'cancellation_notes_required' => 'Annullare un\'azione richiede il motivo nelle note.',
        'term_too_long' => 'La scadenza è troppo lontana per un rilievo :severity: il termine è :days giorni (fino al :limit).',
    ],
    'obligation' => [
        'locked' => 'L\'adempimento è :status e non può essere modificato.',
        'not_open' => 'L\'adempimento è :status: si completa solo un adempimento da fare o in corso.',
    ],
    'sod_document' => [
        'conflict' => 'Segregazione dei compiti: chi ha preparato :document non può anche approvarlo o rilasciarlo.',
        'documents' => [
            'payment' => 'un pagamento',
            'purchase_order' => 'un ordine di acquisto',
            'journal' => 'una scrittura contabile',
            'payroll_run' => 'un\'elaborazione paghe',
        ],
    ],
];
