<?php

declare(strict_types=1);

return [
    'audit' => [
        'only_planned_editable' => 'L\'audit est :status : seul un audit planifié peut être modifié.',
        'checklist_pending' => ':count point(s) de la liste de contrôle n\'ont pas encore de résultat.',
        'results_in_progress_only' => 'Les résultats peuvent être enregistrés tant que l\'audit est en cours.',
        'findings_in_progress_only' => 'Les constats sont émis tant que l\'audit est en cours.',
        'status_expected' => 'L\'audit est :status : :expected attendu.',
    ],
    'capa' => [
        'nonconformity_closed' => 'La non-conformité est :status : aucune nouvelle action ne peut être créée.',
        'action_locked' => 'L\'action est :status et ne peut pas être modifiée.',
        'status_expected' => 'L\'action est :status : :expected attendu.',
    ],
    'nonconformity' => [
        'actions_unsettled' => 'Chaque action corrective et préventive doit être vérifiée ou annulée avant la clôture : :numbers.',
        'root_cause_required' => 'Une non-conformité majeure ou critique nécessite une cause racine avant sa clôture.',
        'verified_action_required' => 'Une non-conformité majeure ou critique nécessite au moins une action corrective vérifiée avant sa clôture.',
        'status_expected' => 'La non-conformité est :status : :expected attendu.',
        'locked' => 'La non-conformité est :status et ne peut pas être modifiée.',
        'mfg_reference_source' => 'Une référence de fabrication n\'est acceptée que pour la source fabrication.',
        'mfg_not_found' => 'La non-conformité de fabrication n\'existe pas dans cette société.',
    ],
    'procedure' => [
        'code_exists' => 'Une procédure avec ce code existe déjà : révisez-la pour créer une nouvelle version.',
        'not_editable' => 'La procédure est :status : seule une procédure en brouillon ou rejetée peut être modifiée.',
        'submit_status' => 'La procédure est :status : seule une procédure en brouillon ou rejetée est soumise.',
        'document_required' => 'Liez le document de la procédure avant de la soumettre à approbation.',
        'workflow_start_failed' => 'Le workflow d\'approbation ne peut pas être démarré : :reason',
        'decided_by_workflow' => 'Cette procédure est décidée par son workflow d\'approbation : utilisez la boîte de réception des workflows.',
        'obsolete_only_approved' => 'Seule une procédure approuvée peut être rendue obsolète.',
        'revise_only_approved' => 'Seule une procédure approuvée est révisée.',
        'author_cannot_approve' => 'L\'auteur d\'une procédure ne peut pas l\'approuver.',
        'not_pending' => 'La procédure est :status, pas en attente d\'approbation.',
        'document_not_found' => 'Le document n\'existe pas dans cette société.',
    ],
    'spc' => [
        'code_exists' => 'Une caractéristique avec ce code existe déjà.',
        'subgroup_locked' => 'La taille du sous-groupe ne change plus une fois des échantillons saisis.',
        'archived' => 'La caractéristique est archivée : aucun nouvel échantillon.',
        'limits_order' => 'La limite supérieure doit être supérieure à la limite inférieure.',
        'subgroup_size' => 'Un échantillon exige exactement :size mesures.',
    ],
    'instrument' => [
        'code_exists' => 'Un instrument avec ce code existe déjà.',
        'not_found' => 'Instrument introuvable dans cette entreprise.',
        'retired' => 'L\'instrument est réformé.',
        'due_before_calibration' => 'La prochaine échéance doit suivre la date d\'étalonnage.',
        'error_exceeds_tolerance' => 'L\'écart constaté dépasse la tolérance : enregistrer le résultat comme out_of_tolerance.',
        'nonconformity_title' => 'Instrument :code hors tolérance',
        'nonconformity_description' => 'L\'étalonnage de :code du :date était hors tolérance : revoir les résultats mesurés depuis l\'étalonnage précédent.',
    ],
    'complaint' => [
        'locked' => 'La réclamation est :status et ne peut pas être modifiée.',
        'status_expected' => 'La réclamation est :status : attendu :expected.',
        'already_linked' => 'Une non-conformité est déjà liée.',
        'capa_other_nonconformity' => 'L\'action CAPA appartient à une autre non-conformité.',
        'root_cause_required' => 'Une cause racine est requise avant la clôture.',
        'nonconformity_open' => 'La non-conformité liée est encore ouverte.',
        'nonconformity_title' => 'Réclamation :number - :customer',
    ],
];
