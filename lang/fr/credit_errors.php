<?php

declare(strict_types=1);

return [
    'customer' => [
        'missing' => 'Le client n\'existe pas dans cette entreprise.',
    ],
    'scorecard' => [
        'already_configured' => 'L\'entreprise a déjà des facteurs de scorecard ou des classes de notation : modifiez-les au lieu de créer ceux par défaut.',
        'missing_factors' => 'La scorecard n\'a aucun facteur actif : ajoutez au moins un facteur avant de calculer le score.',
        'no_band' => 'Aucune classe de notation ne contient le score :score : corrigez les plages de score des classes.',
        'manual_missing' => 'Saisissez une valeur de 0 à 100 pour le facteur manuel :code.',
    ],
    'band' => [
        'overlap' => 'La plage de score chevauche une autre classe de notation.',
        'code_taken' => 'Une autre classe de notation utilise déjà ce code.',
    ],
    'score' => [
        'missing' => 'Le client n\'a pas encore de score : calculez-le avant de demander une proposition.',
    ],
    'limit' => [
        'pending_exists' => 'Le client a déjà une demande de limite de crédit en attente de décision.',
        'amount_missing' => 'Saisissez la limite demandée : il n\'y a pas de score pour en proposer une.',
        'not_pending' => 'Seule une demande en attente peut être décidée ou annulée.',
        'same_user' => 'L\'auteur de la demande ne peut pas la décider.',
        'note_required' => 'La limite dépasse la proposition de :proposed : justifiez la décision dans une note.',
        'valid_until_past' => 'La limite ne peut pas être valable jusqu\'à un jour passé.',
    ],
    'insurance' => [
        'dates' => 'La police doit se terminer après son début.',
        'policy_not_active' => 'La police n\'est pas en vigueur aujourd\'hui.',
        'period' => 'La couverture ne peut pas dépasser la fin de la police.',
        'duplicate' => 'Le client a déjà une couverture demandée ou accordée sous cette police.',
        'total_exceeded' => 'Les couvertures accordées sous cette police (:granted) dépasseraient sa couverture totale.',
        'policy_has_covers' => 'La police a des couvertures : annulez-la au lieu de la supprimer.',
    ],
    'strategy' => [
        'days_order' => 'Les jours après l\'échéance ne peuvent pas être inférieurs à ceux de l\'étape précédente.',
        'in_use' => 'Un dossier ouvert travaille déjà avec les étapes de cette stratégie.',
        'default_required' => 'Définissez plutôt une autre stratégie par défaut.',
    ],
    'case' => [
        'no_overdue' => 'Le client n\'a aucune facture échue à recouvrer.',
        'invoice_not_overdue' => 'Certaines factures ne sont pas des factures ouvertes et échues du client.',
        'invoice_in_case' => 'Certaines factures sont déjà dans un dossier de recouvrement ouvert.',
        'closed' => 'Le dossier est clos.',
        'disputed' => 'Le dossier a un litige ouvert : tranchez-le d\'abord.',
        'promise_pending' => 'Le dossier a une promesse de paiement en attente : clôturez-la d\'abord.',
        'no_strategy' => 'Le dossier n\'a pas de stratégie de relance active.',
        'no_more_steps' => 'La stratégie n\'a plus d\'étape.',
        'step_not_due' => 'La prochaine étape est due :days jours après l\'échéance : la facture la plus ancienne est échue depuis :overdue jours.',
        'open_disputes' => 'Le dossier a des litiges ouverts : tranchez-les avant de le clore.',
        'still_outstanding' => 'Le dossier ne peut pas être résolu : :amount restent à encaisser.',
    ],
    'promise' => [
        'pending_exists' => 'Le dossier a déjà une promesse de paiement en attente.',
        'past_date' => 'La date promise ne peut pas être dans le passé.',
        'amount_exceeds' => 'Le montant promis dépasse le montant restant dû de :outstanding.',
        'not_pending' => 'La promesse est déjà clôturée.',
        'paid_required' => 'Saisissez le montant payé pour marquer la promesse comme tenue.',
        'paid_below' => 'Le montant payé est inférieur au promis :promised : marquez la promesse comme non tenue.',
    ],
    'dispute' => [
        'invoice_not_open' => 'La facture n\'est pas une facture ouverte de ce client.',
        'amount_exceeds' => 'Le montant contesté dépasse ce qui reste dû sur la facture (:outstanding).',
        'case_mismatch' => 'Le dossier n\'est pas un dossier ouvert de ce client.',
        'not_open' => 'Le litige est déjà tranché ou n\'est pas ouvert.',
        'credit_required' => 'Saisissez le montant crédité au client.',
        'credit_exceeds' => 'Le montant crédité dépasse le contesté de :disputed.',
    ],
];
