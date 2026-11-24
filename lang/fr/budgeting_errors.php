<?php

declare(strict_types=1);

return [
    'version' => [
        'locked' => 'Une version verrouillée ne peut pas être modifiée.',
        'not_draft' => 'Seule une version en brouillon peut être modifiée : celle-ci a déjà été soumise, approuvée ou verrouillée.',
        'name_taken' => 'Une version portant ce nom existe déjà pour l\'exercice :year.',
    ],
    'line' => [
        'duplicate' => 'Une ligne existe déjà pour ce compte, ce centre de coûts, ce projet et cette période.',
        'period_range' => 'La période doit être comprise entre 1 et 12.',
        'account_missing' => 'Le compte n\'existe pas dans cette entreprise.',
        'cost_center_missing' => 'Le centre de coûts n\'existe pas dans cette entreprise.',
        'project_missing' => 'Le projet n\'existe pas dans cette entreprise.',
    ],
    'spread' => [
        'range' => 'Les périodes doivent être comprises entre 1 et 12 et la fin ne peut pas précéder le début.',
        'method' => 'La règle de répartition n\'est pas valide.',
        'no_seasonal_weights' => 'La version n\'a pas de poids saisonniers : définissez-les avant d\'utiliser la règle saisonnière.',
        'weights_count' => 'Les poids saisonniers doivent être exactement douze.',
        'weights_invalid' => 'Les poids saisonniers ne peuvent pas être négatifs et leur somme sur les périodes utilisées doit être supérieure à zéro.',
        'percentages_count' => 'Saisissez exactement :count pourcentages, un par période de la plage.',
        'percentages_sum' => 'Les pourcentages ne peuvent pas être négatifs et doivent totaliser 100.',
    ],
    'approval' => [
        'empty' => 'Une version sans lignes ne peut pas être soumise.',
        'reason_required' => 'Un commentaire avec le motif est obligatoire.',
        'budget_exists' => 'Le budget :name est déjà approuvé pour l\'exercice :year : rejetez-le ou remplacez-le d\'abord.',
        'invalid_transition' => 'La version est :status : l\'action :action n\'est pas autorisée.',
    ],
    'reforecast' => [
        'base_not_approved' => 'Seule une version approuvée ou verrouillée peut être re-prévue.',
        'no_lines' => 'La version n\'a pas de lignes à re-prévoir.',
    ],
];
