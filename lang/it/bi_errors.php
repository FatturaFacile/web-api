<?php

declare(strict_types=1);

return [
    'formula' => [
        'length' => 'La formula non può essere vuota e può avere al massimo :max caratteri.',
        'number' => 'Il numero ":token" non è valido: usa cifre con al massimo 8 decimali.',
        'character' => 'Il carattere ":character" non è ammesso in una formula: usa numeri, chiavi di misura, + - * / e parentesi.',
        'incomplete' => 'La formula è incompleta.',
        'parenthesis' => 'Una parentesi non è chiusa.',
        'unexpected' => '":token" non è atteso nella formula.',
        'too_deep' => 'La formula ha troppi livelli annidati.',
        'no_reference' => 'La formula deve usare almeno una misura del dataset.',
        'unknown_measure' => 'La formula usa ":measure", che non è una misura del dataset.',
    ],
    'calculated' => [
        'key_taken_by_dataset' => 'La chiave ":key" è già una misura o una dimensione del dataset.',
        'key_exists' => 'Esiste già una misura calcolata con la chiave ":key" su questo dataset.',
        'limit' => 'Un dataset può avere al massimo :max misure calcolate.',
        'in_use' => 'La misura è ancora usata da: :usage. Toglila prima da lì.',
    ],
    'query' => [
        'unknown_calculated' => 'Questa misura calcolata non esiste sul dataset.',
    ],
    'pivot' => [
        'measure_missing' => 'Il valore della pivot deve essere una misura della query.',
        'dimension_missing' => 'La riga o la colonna della pivot deve essere una dimensione della query.',
        'dimension_repeated' => 'La dimensione ":key" è usata due volte nella pivot.',
        'no_values' => 'Scegli almeno una misura per le celle della pivot.',
        'no_axis' => 'Scegli almeno una dimensione per le righe o le colonne della pivot.',
        'too_large' => 'La pivot avrebbe più di :max celle: filtra o raggruppa ulteriormente i dati.',
    ],
    'widget' => [
        'limit' => 'Una dashboard può avere al massimo :max widget.',
        'source_required' => 'Scegli una query salvata oppure indica un dataset e una specifica.',
        'source_conflict' => 'Usa una query salvata oppure un dataset con una specifica, non entrambi.',
        'saved_query_missing' => 'La query salvata non esiste o non puoi aprirla.',
        'grid_bounds' => 'Il widget deve restare dentro le :columns colonne della griglia.',
        'overlap' => 'Il widget si sovrappone a un altro widget della dashboard.',
        'not_in_dashboard' => 'Questo widget non appartiene alla dashboard.',
        'drill_too_deep' => 'Non c\'è un altro livello in cui scendere.',
        'drill_mismatch' => 'Il drill-down deve seguire il percorso: era attesa la dimensione ":expected".',
        'drill_path_unknown' => 'Il percorso di drill-down deve indicare dimensioni del dataset.',
        'drill_path_date' => 'La dimensione data ":key" non può stare in un percorso di drill-down.',
        'drill_path_repeated' => 'La dimensione ":key" è ripetuta nel percorso di drill-down.',
    ],
    'share' => [
        'not_member' => 'L\'utente non è membro di questa azienda.',
        'owner' => 'Il proprietario ha già accesso completo.',
    ],
    'snapshot' => [
        'owner_gone' => 'Il proprietario di questa query non esiste più.',
        'owner_left' => 'Il proprietario di questa query non è più membro dell\'azienda.',
        'not_comparable' => 'Solo un\'istantanea riuscita di una query salvata può essere confrontata con oggi.',
    ],
];
