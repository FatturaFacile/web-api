<?php

declare(strict_types=1);

return [
    'module_name' => 'Gestion des Immobilisations et Actifs',
    'statuses' => [
        'draft' => 'Brouillon',
        'registered' => 'Enregistré',
        'capitalized' => 'Capitalisé',
        'commissioned' => 'Mis en service',
        'in_service' => 'En exploitation',
        'active' => 'Actif',
        'under_maintenance' => 'En maintenance',
        'revalued' => 'Réévalué',
        'impaired' => 'Déprécié',
        'retired' => 'Retiré',
        'disposed' => 'Cédé',
        'sold' => 'Vendu',
    ],
    'depreciation_methods' => [
        'straight_line' => 'Amortissement linéaire',
        'declining_balance' => 'Amortissement dégressif',
        'double_declining_balance' => 'Double amortissement dégressif',
        'units_of_production' => 'Unités de production',
        'custom' => 'Méthode personnalisée',
    ],
];
