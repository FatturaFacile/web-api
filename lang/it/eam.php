<?php

declare(strict_types=1);

return [
    'module_name' => 'Gestione Cespiti e Asset Aziendali',
    'statuses' => [
        'draft' => 'Bozza',
        'registered' => 'Registrato',
        'capitalized' => 'Capitalizzato',
        'commissioned' => 'Collaudato',
        'in_service' => 'In Esercizio',
        'active' => 'Attivo',
        'under_maintenance' => 'In Manutenzione',
        'revalued' => 'Rivalutato',
        'impaired' => 'Svalutato',
        'retired' => 'Ritirato',
        'disposed' => 'Dismesso',
        'sold' => 'Venduto',
    ],
    'depreciation_methods' => [
        'straight_line' => 'Quote Costanti (Lineare)',
        'declining_balance' => 'Quote Decrescenti',
        'double_declining_balance' => 'Doppia Quota Decrescente',
        'units_of_production' => 'Unità di Produzione',
        'custom' => 'Metodo Personalizzato',
    ],
];
