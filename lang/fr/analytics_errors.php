<?php

declare(strict_types=1);

return [
    'report' => [
        'unknown_dataset' => 'Jeu de données inconnu.',
        'role_cannot_read' => 'Votre rôle ne peut pas lire les données :module dont ce jeu de données est issu.',
        'no_active_company' => 'Aucune entreprise active.',
        'timeout' => 'Le rapport a pris plus de :seconds secondes et a été annulé : réduisez la période, ajoutez des filtres ou regroupez par moins de dimensions.',
    ],
    'spec' => [
        'dimensions_list' => 'Les dimensions doivent former une liste de :max éléments au maximum.',
        'dimension_repeated' => 'La dimension [:key] est répétée.',
        'measures_count' => 'Choisissez entre 1 et :max mesures.',
        'unknown_measure' => 'Mesure inconnue pour ce jeu de données.',
        'measure_repeated' => 'La mesure [:measure] est répétée.',
        'unknown_comparison' => 'Comparaison inconnue.',
        'comparison_needs_period' => 'Une comparaison nécessite un jeu de données avec une période et une période.',
        'dimension_format' => 'Une dimension est une clé, éventuellement suivie de :granularity.',
        'unknown_dimension' => 'Dimension inconnue pour ce jeu de données.',
        'unknown_granularity' => 'Granularité inconnue pour cette dimension.',
        'filters_list' => 'Les filtres doivent former une liste de :max éléments au maximum.',
        'unknown_dimension_or_measure' => 'Dimension ou mesure inconnue pour ce jeu de données.',
        'operator_not_allowed' => 'Opérateur non autorisé pour ce champ.',
        'invalid_filter_value' => 'Valeur non valide pour une dimension de type :type et l\'opérateur :operator.',
        'sort_list' => 'Le tri doit former une liste de :max éléments au maximum.',
        'sort_field' => 'Triez uniquement selon une dimension ou une mesure choisie.',
        'sort_direction' => 'Le sens est asc ou desc.',
        'sort_field_repeated' => 'Le champ est déjà utilisé pour le tri.',
        'limit_range' => 'La limite top-N est un nombre entier compris entre 1 et :max.',
        'no_period' => 'Ce jeu de données décrit la situation d\'aujourd\'hui et n\'a pas de période.',
        'period_format' => 'La période est un objet avec un préréglage, ou avec from et to.',
        'unknown_preset' => 'Préréglage inconnu.',
        'period_dates' => 'from et to sont des dates (Y-m-d).',
        'period_inverted' => 'Le début de la période est postérieur à sa fin.',
        'period_too_long' => 'La période ne peut pas dépasser :days jours.',
    ],
];
