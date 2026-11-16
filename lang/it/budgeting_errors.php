<?php

declare(strict_types=1);

return [
    'version' => [
        'locked' => 'Una versione bloccata non può essere modificata.',
        'not_draft' => 'Si può modificare solo una versione in bozza: questa è già stata inviata, approvata o bloccata.',
        'name_taken' => 'Esiste già una versione con questo nome per l\'esercizio :year.',
    ],
    'line' => [
        'duplicate' => 'Esiste già una riga per questo conto, centro di costo, progetto e periodo.',
        'period_range' => 'Il periodo deve essere compreso tra 1 e 12.',
        'account_missing' => 'Il conto non esiste in questa azienda.',
        'cost_center_missing' => 'Il centro di costo non esiste in questa azienda.',
        'project_missing' => 'Il progetto non esiste in questa azienda.',
    ],
    'spread' => [
        'range' => 'I periodi devono essere compresi tra 1 e 12 e la fine non può precedere l\'inizio.',
        'method' => 'La regola di ripartizione non è valida.',
        'no_seasonal_weights' => 'La versione non ha pesi stagionali: impostali prima di usare la regola stagionale.',
        'weights_count' => 'I pesi stagionali devono essere esattamente dodici.',
        'weights_invalid' => 'I pesi stagionali non possono essere negativi e la loro somma nei periodi usati deve essere maggiore di zero.',
        'percentages_count' => 'Inserisci esattamente :count percentuali, una per periodo dell\'intervallo.',
        'percentages_sum' => 'Le percentuali non possono essere negative e devono sommare a 100.',
    ],
    'approval' => [
        'empty' => 'Una versione senza righe non può essere inviata.',
        'reason_required' => 'Serve un commento con il motivo.',
        'budget_exists' => 'Il budget :name è già approvato per l\'esercizio :year: rifiutalo o sostituiscilo prima.',
        'invalid_transition' => 'La versione è :status: l\'azione :action non è consentita.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Si può riprevedere solo una versione approvata o bloccata.',
        'no_lines' => 'La versione non ha righe da riprevedere.',
    ],
];
