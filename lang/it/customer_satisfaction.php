<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Elenco recuperato correttamente.',
        'loaded' => 'Dato recuperato correttamente.',
        'saved' => 'Salvato correttamente.',
        'deleted' => 'Eliminato correttamente.',
        'defaults_installed' => 'I modelli di sondaggio predefiniti sono stati installati.',
        'no_matching_rule' => 'Nessuna regola di sondaggio attiva corrisponde a questo evento.',
        'dispatch_recorded' => 'L\'invio del sondaggio è stato registrato.',
        'dispatches_processed' => 'Gli inviti ai sondaggi in scadenza sono stati elaborati.',
        'report_generated' => 'Il report ISO 9001 è stato generato.',
        'score_recorded' => 'Grazie, la sua valutazione è stata registrata.',
        'feedback_recorded' => 'Grazie, il suo commento è stato registrato.',
        'opted_out' => 'Non riceverà più sondaggi di soddisfazione.',
    ],

    'errors' => [
        'survey_not_found' => 'Questo link del sondaggio non è valido.',
        'survey_expired' => 'Questo link del sondaggio è scaduto.',
        'survey_not_available' => 'Questo sondaggio non è disponibile.',
        'invalid_score' => 'Il punteggio deve essere compreso tra :min e :max.',
        'score_required' => 'Indichi prima un punteggio, poi aggiunga il commento.',
        'comment_required' => 'Scriva un commento o registri una nota vocale.',
        'voice_not_allowed' => 'I commenti vocali non sono abilitati per questo sondaggio.',
        'invalid_status_transition' => 'Un caso non può passare da ":from" a ":to".',
        'resolution_requires_root_cause' => 'Per risolvere o chiudere un caso servono la causa radice e il riepilogo della risoluzione.',
        'unsupported_export_format' => 'Formato di esportazione non supportato.',
    ],

    'crm_task_subject' => 'Recupero detrattore: chiamare :customer entro 24 ore',

    'report' => [
        'title' => 'Report di soddisfazione del cliente',
        'period' => 'Periodo',
        'kpis' => 'Indicatori principali',
        'response_rate' => 'Tasso di risposta %',
        'sla_compliance' => 'Rispetto SLA 24h %',
        'open_cases' => 'Casi detrattori aperti',
        'breakdown' => 'Dettaglio',
        'dimension' => 'Dimensione',
        'responses' => 'Risposte',
        'corrective_actions' => 'Non conformità e azioni correttive',
        'resolution_rate' => 'Tasso di risoluzione closed-loop %',
        'management_review_notes' => 'Note del Riesame della Direzione',
        'columns' => [
            'dimension' => 'Dimensione',
            'month' => 'Mese',
            'responses' => 'Risposte',
            'nps' => 'NPS',
            'promoters_pct' => 'Promotori %',
            'passives_pct' => 'Passivi %',
            'detractors_pct' => 'Detrattori %',
            'csat_pct' => 'CSAT %',
            'csat_average' => 'CSAT medio',
            'ces_average' => 'CES medio',
            'ces_low_effort_rate' => 'CES basso sforzo %',
        ],
    ],
];
