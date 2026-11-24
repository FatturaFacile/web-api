<?php

declare(strict_types=1);

return [
    'cycle_dates_invalid' => 'La fin du cycle ne peut pas précéder son début.',
    'cycle_closed' => 'Le cycle d\'objectifs est clôturé : ses objectifs ne peuvent plus être modifiés.',
    'goal_not_open' => 'L\'objectif est terminé ou annulé et n\'accepte plus de mise à jour.',
    'value_required' => 'La valeur du résultat clé est obligatoire.',
    'progress_required' => 'L\'avancement de l\'objectif est obligatoire.',
    'progress_is_derived' => 'L\'avancement de cet objectif découle de ses résultats clés ou de ses objectifs enfants : mettez-les à jour.',
    'employee_required' => 'Un objectif individuel nécessite un employé.',
    'team_required' => 'Un objectif d\'équipe nécessite une équipe.',
    'parent_other_cycle' => 'L\'objectif parent appartient à un autre cycle.',
    'parent_level_invalid' => 'L\'objectif parent doit être d\'un niveau supérieur (entreprise, puis équipe, puis employé).',
    'parent_loop' => 'Un objectif ne peut pas descendre de lui-même.',
];
