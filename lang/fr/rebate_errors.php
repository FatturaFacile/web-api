<?php

declare(strict_types=1);

return [
    'agreement' => [
        'not_active' => 'L’accord n’est pas actif.',
        'locked' => 'Seuls le nom, les notes et la date de fin d’un accord qui n’est plus un brouillon peuvent être modifiés.',
        'period' => 'La date de fin ne peut pas précéder la date de début.',
        'not_draft' => 'Seul un accord en brouillon peut être activé.',
        'tiers_required' => 'Ajoutez au moins un palier avant d’activer l’accord.',
        'delete_draft_only' => 'Seul un accord en brouillon sans provisions peut être supprimé.',
        'code_taken' => 'Le code :code est déjà utilisé par un autre accord.',
        'tiers_distinct' => 'Chaque palier nécessite un seuil différent.',
    ],
    'accrual' => [
        'date_outside' => 'La date doit se situer dans la période de l’accord (:from au :to).',
    ],
    'settlement' => [
        'nothing_to_settle' => 'Aucune remise provisionnée à régler.',
        'tax_rate_required' => 'Indiquez le taux de TVA de l’avoir.',
        'credit_note_note' => 'Remise de volume :name (:code)',
        'credit_note_line' => 'Remise de volume :name, période du :from au :to',
    ],
    'promotion' => [
        'percentage_max' => 'Une remise en pourcentage ne peut pas dépasser 100.',
        'period' => 'La date de fin ne peut pas précéder la date de début.',
        'code_taken' => 'Le code :code est déjà utilisé par une autre promotion.',
    ],
];
