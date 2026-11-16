<?php

declare(strict_types=1);

return [
    'dunning' => [
        'invoice_not_unpaid' => 'La fattura non è una fattura emessa e non pagata di questo abbonamento.',
    ],
    'subscription' => [
        'start_date_locked' => 'La data di inizio di un abbonamento già fatturato non può cambiare.',
        'no_quantity_item' => 'L\'abbonamento non ha alcuna voce di piano la cui quantità possa cambiare.',
        'already_closed' => 'L\'abbonamento è già chiuso.',
        'pending_approval' => 'L\'abbonamento è in attesa di approvazione e non può cambiare.',
    ],
    'plan' => [
        'not_active' => 'Il piano non è un piano attivo di questa azienda.',
        'currency_interval_mismatch' => 'Il nuovo piano deve avere la valuta e l\'intervallo di fatturazione dell\'abbonamento.',
        'use_downgrade' => 'Il nuovo piano costa meno: usare un downgrade.',
        'use_upgrade' => 'Il nuovo piano costa di più: usare un upgrade.',
    ],
    'usage' => [
        'item_not_metered' => 'La voce non è una voce a consumo di un abbonamento di questa azienda.',
        'subscription_closed' => 'L\'abbonamento della voce è chiuso.',
        'metric_mismatch' => 'La metrica non corrisponde alla metrica della voce.',
    ],
];
