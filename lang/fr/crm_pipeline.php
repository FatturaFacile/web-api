<?php

declare(strict_types=1);

return [
    'pipeline_has_no_stage' => 'Le pipeline sélectionné n\'a aucune étape ouverte active.',
    'stage_in_other_pipeline' => 'L\'étape cible appartient à un autre pipeline que l\'opportunité.',
    'loss_reason_required' => 'Un motif de perte est obligatoire pour clôturer l\'opportunité comme perdue.',
    'loss_reason_invalid' => 'Le motif de perte sélectionné n\'existe pas ou n\'est pas actif.',
    'winning_competitor_required' => 'Le concurrent gagnant est obligatoire pour clôturer l\'opportunité comme perdue.',
    'pipeline_in_use' => 'Le pipeline est utilisé par des opportunités : désactivez-le plutôt que de le supprimer.',
    'pipeline_default_required' => 'Au moins un pipeline par défaut actif est requis.',
    'pipeline_default_not_deletable' => 'Le pipeline par défaut ne peut être ni supprimé ni désactivé.',
    'loss_reason_in_use' => 'Le motif de perte est utilisé par des opportunités : désactivez-le plutôt que de le supprimer.',
    'stage_pipeline_invalid' => 'Le pipeline n\'appartient pas au tenant actif.',
    'quota_period_invalid' => 'La fin de la période du quota ne peut pas précéder son début.',
];
