<?php

declare(strict_types=1);

return [
    'report' => [
        'unknown_dataset' => 'Dataset sconosciuto.',
        'role_cannot_read' => 'Il tuo ruolo non può leggere i dati :module da cui deriva questo dataset.',
        'no_active_company' => 'Nessuna azienda attiva.',
        'timeout' => 'Il report ha impiegato più di :seconds secondi ed è stato annullato: restringere il periodo, aggiungere filtri o raggruppare per meno dimensioni.',
    ],
    'spec' => [
        'dimensions_list' => 'Le dimensioni devono essere un elenco di al massimo :max elementi.',
        'dimension_repeated' => 'La dimensione [:key] è ripetuta.',
        'measures_count' => 'Scegliere da 1 a :max misure.',
        'unknown_measure' => 'Misura sconosciuta per questo dataset.',
        'measure_repeated' => 'La misura [:measure] è ripetuta.',
        'unknown_comparison' => 'Confronto sconosciuto.',
        'comparison_needs_period' => 'Un confronto richiede un dataset con un periodo e un periodo.',
        'dimension_format' => 'Una dimensione è una chiave, eventualmente seguita da :granularity.',
        'unknown_dimension' => 'Dimensione sconosciuta per questo dataset.',
        'unknown_granularity' => 'Granularità sconosciuta per questa dimensione.',
        'filters_list' => 'I filtri devono essere un elenco di al massimo :max elementi.',
        'unknown_dimension_or_measure' => 'Dimensione o misura sconosciuta per questo dataset.',
        'operator_not_allowed' => 'Operatore non consentito per questo campo.',
        'invalid_filter_value' => 'Valore non valido per una dimensione di tipo :type e per l\'operatore :operator.',
        'sort_list' => 'L\'ordinamento deve essere un elenco di al massimo :max elementi.',
        'sort_field' => 'Ordinare solo per una dimensione o una misura scelta.',
        'sort_direction' => 'La direzione è asc o desc.',
        'sort_field_repeated' => 'Il campo è già usato per ordinare.',
        'limit_range' => 'Il limite top-N è un numero intero compreso tra 1 e :max.',
        'no_period' => 'Questo dataset descrive la situazione di oggi e non ha un periodo.',
        'period_format' => 'Il periodo è un oggetto con un preset, oppure con from e to.',
        'unknown_preset' => 'Preset sconosciuto.',
        'period_dates' => 'from e to sono date (Y-m-d).',
        'period_inverted' => 'L\'inizio del periodo è successivo alla sua fine.',
        'period_too_long' => 'Il periodo non può superare :days giorni.',
    ],
];
