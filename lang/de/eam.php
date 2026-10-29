<?php

declare(strict_types=1);

return [
    'module_name' => 'Anlagenbuchhaltung und Asset Management',
    'statuses' => [
        'draft' => 'Entwurf',
        'registered' => 'Registriert',
        'capitalized' => 'Aktiviert',
        'commissioned' => 'In Betrieb genommen',
        'in_service' => 'Im Einsatz',
        'active' => 'Aktiv',
        'under_maintenance' => 'In Wartung',
        'revalued' => 'Neubewertet',
        'impaired' => 'Wertgemindert',
        'retired' => 'Ausgemustert',
        'disposed' => 'Entsorgt',
        'sold' => 'Verkauft',
    ],
    'depreciation_methods' => [
        'straight_line' => 'Lineare Abschreibung',
        'declining_balance' => 'Degressive Abschreibung',
        'double_declining_balance' => 'Doppelt-degressive Abschreibung',
        'units_of_production' => 'Leistungsabschreibung',
        'custom' => 'Benutzerdefinierte Methode',
    ],
];
