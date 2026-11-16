<?php

declare(strict_types=1);

return [
    'vehicle' => [
        'plate_taken' => 'La targa :plate è già usata da un altro veicolo.',
        'odometer_decrease' => 'Il contachilometri non può diminuire: il veicolo è già a :current km.',
        'retired' => 'Il veicolo è :status: non può ricevere nuove assegnazioni o rifornimenti.',
        'open_assignment' => 'Il veicolo ha ancora un\'assegnazione aperta: chiudila prima di vendere o dismettere il veicolo.',
        'asset_missing' => 'Il cespite non esiste in questa azienda.',
    ],
    'odometer' => [
        'below_earlier' => 'La lettura di :km km è inferiore a quella di :earlier km del :date.',
        'above_later' => 'La lettura di :km km è superiore a quella di :later km del :date.',
    ],
    'assignment' => [
        'overlap' => 'Il veicolo è già assegnato dal :from al :to.',
        'driver_required' => 'Scegli un dipendente o indica il nome del conducente esterno.',
        'employee_missing' => 'Il dipendente non esiste in questa azienda.',
        'end_odometer_low' => 'I km alla riconsegna non possono essere inferiori a quelli alla consegna.',
        'already_ended' => 'L\'assegnazione è già terminata.',
    ],
    'fuel' => [
        'unit_electric' => 'Un veicolo elettrico si ricarica in kWh.',
        'unit_not_electric' => 'Il veicolo non è elettrico: registra litri o kg.',
        'price_or_total' => 'Indica il prezzo unitario o il costo totale.',
    ],
    'service' => [
        'schedule_mismatch' => 'La scadenza di manutenzione appartiene a un altro veicolo.',
    ],
    'schedule' => [
        'due_required' => 'Indica un intervallo, oppure una data o dei km di scadenza.',
    ],
    'deadline' => [
        'not_open' => 'La scadenza è già stata rinnovata.',
        'renewal_date' => 'La nuova data di scadenza deve essere successiva al :date.',
    ],
];
