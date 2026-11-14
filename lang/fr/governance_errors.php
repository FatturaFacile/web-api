<?php

declare(strict_types=1);

return [
    'status' => [
        'unknown' => 'Statut :status inconnu.',
        'not_allowed' => 'Le statut ne peut pas passer de :from à :to : autorisés :allowed.',
    ],
    'risk' => [
        'closed' => 'Le risque est clos : rouvrez-le pour le modifier.',
        'owner_required' => 'Un risque a besoin d\'un responsable avant son évaluation.',
        'active_control_required' => 'Un risque n\'est traité que si un contrôle actif l\'atténue : liez d\'abord un contrôle.',
        'acceptance_notes_required' => 'L\'acceptation d\'un risque exige la justification dans les notes.',
        'residual_exceeds_inherent' => 'La probabilité et l\'impact résiduels ne peuvent pas dépasser les valeurs inhérentes.',
        'open_findings' => 'Un constat ouvert se rapporte à ce risque : clôturez ou réaffectez d\'abord le constat.',
    ],
    'control' => [
        'test_needs_active' => 'Le contrôle est :status : seul un contrôle actif est testé.',
        'finding_title' => 'Test du contrôle non réussi : :code :title',
    ],
    'plan' => [
        'closed' => 'Le plan d\'audit est clos.',
        'year_locked' => 'L\'année d\'un plan qui a des audits ne peut pas changer.',
        'audit_required' => 'Un plan a besoin d\'au moins un audit avant son approbation.',
        'audits_unfinished' => 'Chaque audit doit être clos ou annulé avant la clôture du plan : :codes.',
    ],
    'audit' => [
        'locked' => 'L\'audit est :status et ne peut pas être modifié.',
        'only_team_and_conclusion' => 'L\'audit est :status : seuls l\'équipe et la conclusion peuvent changer.',
        'dates_inverted' => 'La fin prévue ne peut pas précéder le début prévu.',
        'plan_not_approved' => 'Le plan d\'audit est :status : il doit être approuvé avant le démarrage d\'un audit.',
        'conclusion_required' => 'Un audit est clos avec une conclusion.',
        'untreated_findings' => 'Constats élevés et critiques encore ouverts : :codes.',
        'cancellation_reason_required' => 'L\'annulation d\'un audit exige le motif dans les notes.',
        'outside_plan_year' => 'L\'audit doit commencer dans l\'année du plan (:year).',
    ],
    'finding' => [
        'audit_not_in_fieldwork' => 'L\'audit est :status : les constats sont enregistrés pendant qu\'il est en cours ou en rapport.',
        'locked' => 'Le constat est :status et ne peut pas être modifié.',
        'action_required' => 'Enregistrez d\'abord au moins une action corrective.',
        'acceptance_reason_required' => 'L\'acceptation du risque d\'un constat exige le motif dans les notes.',
        'closure_notes_required' => 'La clôture d\'un constat exige les notes de clôture.',
        'live_actions' => 'Annulez ou terminez les actions correctives avant de rouvrir le constat.',
        'actions_unverified' => 'Chaque action corrective doit être vérifiée ou annulée (au moins une vérifiée) : :codes.',
    ],
    'action' => [
        'finding_closed' => 'Le constat est :status : aucune nouvelle action ne peut être enregistrée.',
        'locked' => 'L\'action est :status et ne peut pas être modifiée.',
        'segregation_of_duties' => 'L\'action ne peut pas être vérifiée par la personne qui l\'a terminée.',
        'rejection_notes_required' => 'Renvoyer une action exige le motif dans les notes.',
        'cancellation_notes_required' => 'L\'annulation d\'une action exige le motif dans les notes.',
        'term_too_long' => 'L\'échéance est trop lointaine pour un constat :severity : le délai est de :days jours (jusqu\'au :limit).',
    ],
    'obligation' => [
        'locked' => 'L\'obligation est :status et ne peut pas être modifiée.',
        'not_open' => 'L\'obligation est :status : seule une obligation à faire ou en cours est terminée.',
    ],
];
