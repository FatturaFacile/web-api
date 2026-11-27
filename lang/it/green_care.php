<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Record recuperati correttamente.',
        'loaded' => 'Record recuperato correttamente.',
        'site_saved' => 'Il sito è stato salvato.',
        'site_deleted' => 'Il sito è stato eliminato.',
        'contract_saved' => 'Il contratto stagionale è stato salvato.',
        'contract_deleted' => 'Il contratto stagionale è stato eliminato.',
        'contract_activated' => 'Il contratto è attivo.',
        'contract_suspended' => 'Il contratto è stato sospeso.',
        'billing_previewed' => 'Anteprima di fatturazione calcolata.',
        'invoice_generated' => 'La bozza di fattura è stata generata.',
        'crew_saved' => 'La squadra è stata salvata.',
        'crew_deleted' => 'La squadra è stata eliminata.',
        'license_saved' => 'L\'abilitazione dell\'operatore è stata salvata.',
        'license_deleted' => 'L\'abilitazione dell\'operatore è stata eliminata.',
        'license_checked' => 'L\'abilitazione dell\'operatore è stata verificata.',
        'route_saved' => 'Il giro è stato salvato.',
        'route_deleted' => 'Il giro è stato eliminato.',
        'routes_optimized' => 'I giri settimanali sono stati pianificati.',
        'routes_rescheduled' => 'Le tappe colpite dall\'allerta meteo sono state riprogrammate.',
        'weather_refreshed' => 'Le previsioni meteo sono state aggiornate.',
        'intervention_logged' => 'L\'intervento è stato registrato.',
        'photos_saved' => 'Le foto sono state salvate.',
        'treatment_recorded' => 'Il trattamento è stato scritto nel quaderno fitosanitario.',
        'slip_saved' => 'Il formulario del verde è stato salvato.',
        'slip_deleted' => 'Il formulario del verde è stato eliminato.',
    ],

    'errors' => [
        'operator_license_missing' => 'L\'operatore non ha un\'abilitazione fitosanitaria valida in :jurisdiction (PER / PNRP / OPF in Svizzera, patentino PAN in Italia).',
        'operator_license_expired' => 'L\'abilitazione fitosanitaria dell\'operatore non è valida il :date (scadenza :expiry).',
        'registration_number_invalid' => 'Il numero di registrazione del prodotto non è valido per :jurisdiction (esempio :hint).',
        'contract_not_active' => 'Il contratto non è attivo.',
        'contract_not_billable' => 'Un contratto in bozza non si può fatturare: attivalo prima.',
        'contract_invalid_transition' => 'Il contratto non può cambiare stato da :status.',
        'nothing_to_bill' => 'Non c\'è nulla da fatturare su questo contratto.',
        'stop_already_completed' => 'La tappa è già stata chiusa.',
        'route_locked' => 'Il giro è già iniziato o chiuso e non si può modificare.',
        'week_start_invalid' => 'La settimana deve iniziare di lunedì.',
        'no_crews_available' => 'Non c\'è nessuna squadra attiva da pianificare.',
        'warehouse_required' => 'Serve un magazzino per scaricare il prodotto.',
        'condominium_data_missing' => 'Il codice fiscale del condominio è obbligatorio per fatturare con la ritenuta.',
        'weather_unavailable' => 'Le previsioni meteo non sono disponibili ora.',
        'file_not_found' => 'Il file non è stato trovato.',
    ],

    'validation' => [
        'polygon' => 'L\'area deve essere un poligono GeoJSON con almeno quattro posizioni.',
        'waste_code' => 'Il codice rifiuto non è valido per :jurisdiction.',
    ],

    'waste' => [
        'default_description' => 'Rifiuti verdi da manutenzione di giardini',
    ],

    'billing' => [
        'installment' => 'Contratto di manutenzione :contract - rata :number di :total',
        'snow_standby' => 'Contratto :contract - canone di reperibilità sgombero neve invernale',
        'extra_passage' => 'Passaggio extra n. :number (:included inclusi) del :date',
        'time_and_material' => 'Regia e materiali :date - :site',
        'waste' => 'Smaltimento verde :code - :kg kg (formulario :slip)',
        'invoice_note' => 'Contratto di manutenzione del verde :contract - :site',
    ],

    'register' => [
        'title_it' => 'Quaderno di Campagna - registro dei trattamenti fitosanitari',
        'title_ch' => 'Registro dei trattamenti fitosanitari (UFAG / BLW)',
        'company' => 'Azienda',
        'period' => 'Periodo',
        'generated' => 'Generato',
        'entries' => ':count registrazioni',
        'columns' => [
            'date' => 'Data',
            'site' => 'Sito',
            'crop' => 'Coltura / superficie',
            'area' => 'Area (m²)',
            'pest' => 'Avversità / infestante',
            'product' => 'Prodotto',
            'registration' => 'N. reg.',
            'ingredient' => 'Sostanza attiva',
            'dose' => 'Dose',
            'water' => 'Acqua (l)',
            'operator' => 'Operatore',
            'license' => 'Abilitazione',
            'reentry' => 'Rientro',
        ],
    ],
];
