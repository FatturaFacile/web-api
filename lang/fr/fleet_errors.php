<?php

declare(strict_types=1);

return [
    'vehicle' => [
        'plate_taken' => 'La plaque :plate est déjà utilisée par un autre véhicule.',
        'odometer_decrease' => 'Le compteur ne peut pas diminuer : le véhicule est déjà à :current km.',
        'retired' => 'Le véhicule est :status : il ne peut plus recevoir d\'affectations ni de pleins.',
        'open_assignment' => 'Le véhicule a encore une affectation ouverte : terminez-la avant de vendre ou de mettre le véhicule au rebut.',
        'asset_missing' => 'L\'immobilisation n\'existe pas dans cette entreprise.',
    ],
    'odometer' => [
        'below_earlier' => 'Le relevé de :km km est inférieur à celui de :earlier km du :date.',
        'above_later' => 'Le relevé de :km km est supérieur à celui de :later km du :date.',
    ],
    'assignment' => [
        'overlap' => 'Le véhicule est déjà affecté du :from au :to.',
        'driver_required' => 'Choisissez un employé ou indiquez le nom du conducteur externe.',
        'employee_missing' => 'L\'employé n\'existe pas dans cette entreprise.',
        'end_odometer_low' => 'Le kilométrage au retour ne peut pas être inférieur à celui de la remise.',
        'already_ended' => 'L\'affectation est déjà terminée.',
    ],
    'fuel' => [
        'unit_electric' => 'Un véhicule électrique se recharge en kWh.',
        'unit_not_electric' => 'Le véhicule n\'est pas électrique : saisissez des litres ou des kg.',
        'price_or_total' => 'Indiquez le prix unitaire ou le coût total.',
    ],
    'service' => [
        'schedule_mismatch' => 'L\'entretien planifié appartient à un autre véhicule.',
    ],
    'schedule' => [
        'due_required' => 'Indiquez un intervalle, ou une date ou un kilométrage d\'échéance.',
    ],
    'deadline' => [
        'not_open' => 'L\'échéance a déjà été renouvelée.',
        'renewal_date' => 'La nouvelle date d\'expiration doit être postérieure au :date.',
    ],
];
