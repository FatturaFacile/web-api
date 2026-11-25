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
        'formal_immutable' => 'Un budget approvato non viene mai riaperto: crea una revisione.',
        'baseline_not_approved' => 'Un budget rivisto si approva solo quando il budget che rivede è approvato o bloccato.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Si può riprevedere solo una versione approvata o bloccata.',
        'no_lines' => 'La versione non ha righe da riprevedere.',
    ],
    'revision' => [
        'baseline_not_approved' => 'Si può rivedere solo un budget originale o rivisto approvato o bloccato.',
        'already_open' => 'Per questo esercizio c\'è già un budget rivisto in preparazione: approvalo o eliminalo prima.',
        'suffix' => 'Revisione :number',
        'adjustment_range' => 'La rettifica non può portare gli importi sotto zero.',
        'transfer_same' => 'Le due righe di un trasferimento devono essere diverse.',
        'transfer_insufficient' => 'La riga da cui prelevare non ha abbastanza budget in questo periodo.',
        'roll_base' => 'Si può far scorrere solo un rolling forecast approvato o bloccato.',
    ],
    'grid' => [
        'slot_range' => 'La colonna deve essere compresa tra 1 e :max.',
    ],
    'settings' => [
        'thresholds' => 'La soglia ambra non può essere superiore alla soglia rossa.',
    ],
    'override' => [
        'source_required' => 'Una deroga per un documento richiede il documento.',
        'already_open' => 'Questo documento ha già una richiesta di deroga aperta.',
        'expiry_past' => 'La data di scadenza non può essere nel passato.',
        'not_open' => 'Si può annullare solo una deroga in attesa o approvata.',
        'not_pending' => 'La deroga è già stata decisa.',
        'four_eyes' => 'Principio dei quattro occhi: non puoi decidere la tua richiesta di deroga.',
    ],
    'spend' => [
        'blocked' => 'Il budget non consente questa spesa: disponibili :available :currency, richiesti :requested :currency. Chiedi una deroga.',
        'warning' => 'Questa spesa supera il budget (disponibili :available :currency, richiesti :requested :currency).',
        'overridden' => 'Questa spesa supera il budget e una deroga approvata la consente (disponibili :available :currency, richiesti :requested :currency).',
    ],
    'import' => [
        'missing_column' => 'Manca la colonna :column.',
        'account_unknown' => 'Il conto :code non esiste.',
        'cost_center_unknown' => 'Il centro di costo :code non esiste.',
        'project_unknown' => 'Il progetto :code non esiste.',
        'period_invalid' => 'Il periodo deve essere un numero intero da 1 a :max.',
        'amount_invalid' => 'L\'importo deve essere un numero maggiore o uguale a zero.',
        'number_invalid' => 'Il valore deve essere un numero maggiore o uguale a zero.',
        'duplicate' => 'Questo conto, centro di costo, progetto e periodo compaiono già alla riga :row.',
        'too_many_rows' => 'Il file ha più di :max righe: dividilo.',
        'empty' => 'Il file è vuoto.',
        'xls_unsupported' => 'Il vecchio formato .xls non è supportato: salva il file come .xlsx o .csv.',
    ],
];
