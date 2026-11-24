<?php

declare(strict_types=1);

return [
    'messages' => [
        'calendar_created' => 'Calendario festività creato.',
        'calendar_updated' => 'Calendario festività aggiornato.',
        'calendar_deleted' => 'Calendario festività eliminato.',
        'policy_created' => 'Politica di maturazione creata.',
        'policy_updated' => 'Politica di maturazione aggiornata.',
        'policy_deleted' => 'Politica di maturazione eliminata.',
        'accruals_calculated' => 'Ratei mensili calcolati.',
        'request_submitted' => 'Richiesta di assenza inviata.',
        'sick_registered' => 'Malattia o infortunio registrato.',
        'request_approved' => 'Richiesta di assenza approvata.',
        'request_rejected' => 'Richiesta di assenza respinta.',
        'request_cancelled' => 'Richiesta di assenza annullata.',
        'threshold_saved' => 'Soglia minima di presidio reparto salvata.',
    ],
    'errors' => [
        'unsupported_country' => 'Giurisdizione non supportata :country (atteso CH o IT).',
        'invalid_swiss_canton' => 'Codice cantone svizzero non valido :canton.',
        'invalid_patron_saint_date' => 'Data del Santo Patrono non valida :date (atteso MM-GG).',
        'invalid_inps_puc' => 'Protocollo certificato medico INPS (PUC) ":puc" non valido (richiesti 9 caratteri alfanumerici).',
        'inps_puc_required' => 'Il numero di protocollo INPS (PUC) a 9 caratteri è obbligatorio per malattia Italia.',
        'end_date_before_start' => 'La data di fine non può precedere la data di inizio.',
        'overlapping_request' => 'Esiste già una richiesta di assenza sovrapposta tra :start_date e :end_date.',
        'insufficient_balance' => 'Saldo ferie/permessi insufficiente (disponibile: :available, richiesto: :requested).',
        'staffing_threshold_breached' => 'Soglia minima di presidio reparto violata il :date (:present presenti, :required richiesti).',
        'invalid_state_transition' => 'Impossibile eseguire :action su una richiesta in stato :status.',
        'locked_by_workflow' => 'La richiesta è gestita da un workflow di approvazione attivo (Mod 006).',
        'legge_104_limit_exceeded' => 'Limite mensile Legge 104/1992 superato (usate: :used h, richieste: :requested h, limite: :limit h).',
        'invalid_decision' => 'Decisione non valida :decision.',
        'employee_id_required' => 'Il parametro employee_id è obbligatorio.',
    ],
];
