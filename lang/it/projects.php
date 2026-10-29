<?php

declare(strict_types=1);

return [
    'messages' => [
        'project_created' => 'Commessa creata con successo',
        'project_updated' => 'Commessa aggiornata con successo',
        'project_retrieved' => 'Commessa recuperata con successo',
        'timesheet_logged' => 'Rapportino ore registrato con successo',
        'expense_recorded' => 'Spesa di commessa registrata con successo',
        'milestone_billed' => 'Stato avanzamento lavori (SAL) fatturato con successo',
        'wip_capitalized' => 'Lavori in corso (WIP) capitalizzati con successo',
        'revenue_recognized' => 'Ricavi di commessa rilevati secondo IFRS 15',
    ],
    'errors' => [
        'budget_exceeded' => 'L\'operazione supera il budget approvato di commessa.',
        'project_closed' => 'Non è possibile imputare costi a una commessa chiusa.',
    ],
];
