<?php

declare(strict_types=1);

return [
    'module_name' => 'Enterprise Asset Management',
    'statuses' => [
        'draft' => 'Draft',
        'registered' => 'Registered',
        'capitalized' => 'Capitalized',
        'commissioned' => 'Commissioned',
        'in_service' => 'In Service',
        'active' => 'Active',
        'under_maintenance' => 'Under Maintenance',
        'revalued' => 'Revalued',
        'impaired' => 'Impaired',
        'retired' => 'Retired',
        'disposed' => 'Disposed',
        'sold' => 'Sold',
    ],
    'depreciation_methods' => [
        'straight_line' => 'Straight Line',
        'declining_balance' => 'Declining Balance',
        'double_declining_balance' => 'Double Declining Balance',
        'units_of_production' => 'Units of Production',
        'custom' => 'Custom Enterprise Method',
    ],
];
