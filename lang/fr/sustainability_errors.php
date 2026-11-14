<?php

declare(strict_types=1);

return [
    'factor' => [
        'unit_not_allowed' => 'L\'unité :unit n\'est pas valide pour le type d\'activité :type.',
        'in_use' => 'Le facteur est utilisé par :count enregistrements d\'activité : créez une nouvelle version au lieu de modifier sa valeur, son unité ou son type d\'activité.',
        'missing_for_activity' => 'Aucun facteur d\'émission actif n\'existe pour :type dans la région :region.',
        'type_mismatch' => 'Le facteur d\'émission concerne :expected, pas :type.',
        'inactive' => 'Le facteur d\'émission n\'est pas actif.',
        'unit_mismatch' => 'Le facteur est exprimé par :factor_unit, la quantité en :unit.',
        'latest_version_only' => 'Seule la dernière version d\'un facteur peut être versionnée à nouveau.',
    ],
    'period' => [
        'overlap' => 'La période chevauche la période de reporting :name.',
        'closed' => 'La période de reporting :name est clôturée.',
        'status_expected' => 'La période de reporting est :status : attendu :expected.',
    ],
    'activity' => [
        'locked' => 'La période de reporting :name est clôturée : ses données d\'activité ne peuvent plus changer.',
    ],
    'target' => [
        'status_expected' => 'L\'objectif est :status : attendu :expected.',
        'locked' => 'Seul un objectif en brouillon peut changer de périmètre, d\'année de référence, d\'année cible et de pourcentage.',
        'year_order' => 'L\'année cible doit être postérieure à l\'année de référence.',
        'baseline_missing' => 'Aucune émission n\'est enregistrée pour :scope en :year : la référence ne peut pas être figée.',
        'not_yet_decidable' => 'L\'année cible :year n\'est pas terminée et l\'objectif n\'est pas encore atteint.',
    ],
    'kpi' => [
        'inactive' => 'Le KPI n\'est pas actif.',
        'period_closed' => 'La période de reporting :name est clôturée : les valeurs des KPI ne peuvent plus changer.',
    ],
    'materiality' => [
        'locked' => 'Une évaluation de matérialité approuvée ne peut pas être modifiée.',
        'no_topics' => 'L\'évaluation nécessite au moins un sujet avant l\'approbation.',
        'status_expected' => 'L\'évaluation est :status : attendu :expected.',
    ],
];
