<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Le délégant et le délégué ne peuvent pas être le même utilisateur.',
        'circular' => 'Délégation circulaire refusée : l\'utilisateur délégué vous a déjà délégué ses tâches.',
        'overlap' => 'Vous avez déjà une délégation active couvrant une partie de cette période.',
    ],
    'inbox' => [
        'period_too_long' => 'La période ne peut pas dépasser 366 jours.',
    ],
    'definition' => [
        'publish_to_activate' => 'Publiez la définition pour l\'activer.',
        'has_running_instances' => 'La définition a des instances en cours : annulez-les ou désactivez la définition.',
        'graph_locked' => 'Seule une définition en brouillon peut modifier son graphe : créez une nouvelle version.',
    ],
];
