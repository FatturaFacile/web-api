<?php

declare(strict_types=1);

return [
    'scenario' => [
        'locked' => 'Un scénario verrouillé ne peut pas être modifié.',
        'not_draft' => 'Seul un scénario en brouillon peut être modifié : celui-ci est déjà en revue, approuvé ou verrouillé.',
        'horizon_order' => 'La fin de l\'horizon ne peut pas précéder son début.',
        'horizon_too_long' => 'L\'horizon ne peut pas dépasser :months mois.',
        'lines_outside_horizon' => 'Certaines lignes du plan sont hors du nouvel horizon : supprimez-les d\'abord.',
        'published' => 'Le scénario a été publié et ne peut être ni supprimé ni remis en brouillon.',
        'baseline_exists' => 'L\'entreprise a déjà un scénario de base : créez un scénario what-if ou copiez le scénario de base.',
    ],
    'resource' => [
        'code_taken' => 'Le code de ressource :code est déjà utilisé.',
        'rate_exists' => 'L\'article a déjà une charge sur cette ressource.',
        'in_use' => 'La ressource a des contraintes de capacité dans des scénarios qui ne sont plus en brouillon et ne peut pas être supprimée.',
    ],
    'line' => [
        'outside_horizon' => 'Le mois est hors de l\'horizon du scénario.',
        'duplicate' => 'Une ligne existe déjà pour ce côté, cet article et ce mois.',
        'item_missing' => 'L\'article n\'existe pas dans cette entreprise.',
        'item_required' => 'Choisissez un article ou saisissez un code article.',
    ],
    'cycle' => [
        'empty' => 'Un scénario sans lignes de plan ne peut pas être soumis.',
        'capacity_overloaded' => ':count mois de ressources sont surchargés : corrigez le plan ou acceptez la surcharge avec un commentaire.',
        'overload_comment' => 'Accepter une surcharge de capacité nécessite un commentaire.',
        'reason_required' => 'Un commentaire avec le motif est obligatoire.',
        'invalid_transition' => 'Le scénario est :status : l\'action :action n\'est pas autorisée.',
        'not_publishable' => 'Seul un scénario approuvé ou verrouillé peut être publié.',
    ],
];
