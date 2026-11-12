<?php

declare(strict_types=1);

return [
    'project' => [
        'number_already_used' => 'Ce numéro de projet est déjà utilisé.',
        'cannot_start' => 'Le projet est :status : il ne peut pas être démarré.',
        'cannot_pause' => 'Le projet est :status : il ne peut pas être suspendu.',
        'cannot_resume' => 'Le projet est :status : il ne peut pas être repris.',
        'cannot_complete' => 'Le projet est :status : il ne peut pas être terminé.',
        'no_longer_changeable' => 'Le projet est :status : il ne peut plus être modifié.',
        'contract_value_via_change_orders' => 'La valeur contractuelle d\'un projet démarré ne change que par des avenants.',
        'recognition_method_locked' => 'La méthode de comptabilisation ne peut plus changer une fois que des coûts sont enregistrés sur le projet.',
        'cannot_delete_with_bookings' => 'Du temps ou des coûts sont enregistrés sur le projet : il ne peut pas être supprimé.',
    ],
    'rate_card' => [
        'overlaps' => 'Un autre barème de tarifs de même périmètre chevauche cette période de validité.',
        'managers_only' => 'Seuls les responsables de l\'entreprise peuvent modifier les tarifs et la capacité valables pour toute l\'entreprise.',
    ],
    'cost' => [
        'project_closed' => 'Le projet :number est :status : aucun coût supplémentaire ne peut être enregistré.',
        'approve_only_pending' => 'La saisie de coût est :status : seules les saisies en attente peuvent être approuvées.',
        'reject_only_pending' => 'La saisie de coût est :status : seules les saisies en attente peuvent être rejetées.',
        'category_invalid' => 'La catégorie de coût n\'est pas valide.',
        'total_negative' => 'Le coût total ne peut pas être négatif.',
        'allocation_needs_task' => 'Chaque répartition doit désigner une tâche du projet.',
        'allocation_not_100' => 'Les pourcentages de répartition doivent totaliser 100.',
    ],
    'task' => [
        'not_in_project' => 'La tâche n\'appartient pas au projet.',
        'cannot_delete_with_bookings' => 'Des heures ou des coûts sont enregistrés sur cette tâche : elle ne peut pas être supprimée.',
        'has_subtasks' => 'La tâche a des sous-tâches : supprimez-les ou déplacez-les d\'abord.',
        'parent_invalid' => 'Le parent doit être une autre tâche du même projet.',
    ],
    'phase' => [
        'not_in_project' => 'La phase n\'appartient pas au projet.',
    ],
    'billing' => [
        'milestone_not_completed' => 'Le jalon n\'est pas encore terminé : terminez-le avant de facturer.',
        'milestone_already_billed' => 'Le jalon est déjà facturé.',
        'milestone_no_amount' => 'Le jalon n\'a ni montant ni pourcentage de facturation.',
        'nothing_to_invoice' => 'Il n\'y a aucune heure ou aucun coût facturable approuvé à facturer jusqu\'à cette date.',
        'project_closed' => 'Le projet :number est :status : il ne peut plus être facturé.',
    ],
    'change_order' => [
        'project_closed' => 'Le projet :number est :status : aucun avenant ne peut être ajouté.',
        'number_already_used' => 'Ce numéro d\'avenant est déjà utilisé sur le projet.',
        'already_decided' => 'L\'avenant est :status : il a déjà fait l\'objet d\'une décision.',
        'project_closed_apply' => 'Le projet :number est :status : les avenants ne peuvent plus être appliqués.',
        'cost_impact_mismatch' => 'L\'impact sur les coûts doit être égal à la somme des variations de coût des lignes.',
        'revenue_impact_mismatch' => 'L\'impact sur le chiffre d\'affaires doit être égal à la somme des variations de chiffre d\'affaires des lignes.',
    ],
    'closing' => [
        'open_timesheets' => ':count feuilles de temps sont encore en brouillon, soumises ou rejetées.',
        'pending_expenses' => ':count dépenses sont en attente d\'approbation.',
        'unbilled_time_and_materials' => 'Des heures et coûts facturables approuvés pour :amount ne sont pas facturés.',
        'unbilled_milestones' => ':count jalons terminés ne sont pas facturés.',
        'draft_invoices' => ':count factures brouillon du projet ne sont pas encore émises.',
        'unbilled_recognised_revenue' => 'Le chiffre d\'affaires comptabilisé dépasse le chiffre d\'affaires facturé de :amount : facturez-le avant la clôture.',
        'open_commitments' => 'Des commandes fournisseur pour :amount sont encore ouvertes sur le projet.',
        'already_closed' => 'Le projet :number est :status : il ne peut pas être clôturé à nouveau.',
    ],
    'milestone' => [
        'billed_locked' => 'Le jalon est déjà facturé et ne peut plus être modifié.',
        'already_completed' => 'Le jalon est déjà terminé.',
        'project_closed' => 'Le projet :number est :status : ses jalons ne peuvent plus être modifiés.',
        'not_in_project' => 'Le jalon n\'appartient pas au projet.',
    ],
    'revenue' => [
        'project_closed' => 'Le projet :number est :status : le chiffre d\'affaires n\'y est plus comptabilisé.',
        'no_method' => 'Le projet n\'a pas de méthode de comptabilisation du chiffre d\'affaires : son chiffre d\'affaires est le chiffre d\'affaires facturé.',
        'percentage_only_poc' => 'Un pourcentage d\'avancement ne peut être indiqué qu\'avec la méthode de l\'avancement.',
        'budget_required' => 'La comptabilisation cost-to-cost nécessite un budget approuvé pour estimer le coût total.',
        'already_recognised_for_date' => 'Le chiffre d\'affaires a déjà été comptabilisé pour cette date.',
        'already_recognised_later' => 'Le chiffre d\'affaires a déjà été comptabilisé pour une date ultérieure : les traitements doivent suivre l\'ordre des dates.',
    ],
    'schedule' => [
        'end_before_start' => 'La date de fin prévue doit être égale ou postérieure à la date de début prévue.',
        'dependency_cycle' => 'Les dépendances des tâches forment un cycle : le chemin critique ne peut pas être calculé.',
        'project_closed' => 'Le projet :number est :status : son planning ne peut plus être modifié.',
    ],
    'dependency' => [
        'predecessor_other_project' => 'Le prédécesseur doit être une tâche du même projet.',
        'self' => 'Une tâche ne peut pas dépendre d\'elle-même.',
        'already_exists' => 'Cette dépendance existe déjà.',
        'cycle' => 'Cette dépendance créerait un cycle dans le planning.',
    ],
    'timesheet' => [
        'approve_only_submitted' => 'La feuille de temps est :status : seules les feuilles de temps soumises peuvent être approuvées.',
        'reject_only_submitted' => 'La feuille de temps est :status : seules les feuilles de temps soumises peuvent être rejetées.',
        'locked' => 'La feuille de temps est :status et verrouillée : seules les feuilles de temps en brouillon ou rejetées peuvent être modifiées.',
        'project_closed' => 'Le projet :number est :status : il n\'accepte plus de temps.',
        'hours_range' => 'Les heures travaillées doivent être supérieures à 0 et au maximum 24.',
        'overtime_range' => 'Les heures supplémentaires doivent être comprises entre 0 et les heures travaillées.',
        'day_exceeds_24' => 'La personne a déjà :hours heures enregistrées le :date : une journée ne peut pas dépasser 24 heures.',
        'rate_missing' => 'Aucun taux de coût et de facturation n\'est configuré pour cette personne à cette date : ajoutez un barème de tarifs ou une ressource de projet.',
    ],
];
