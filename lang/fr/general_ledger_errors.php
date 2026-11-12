<?php

declare(strict_types=1);

return [
    'account' => [
        'type_locked' => 'Le compte :code porte déjà des écritures : son type et son solde normal ne peuvent pas changer. Créez un nouveau compte et reclassez avec une écriture.',
        'has_postings' => 'Le compte :code porte des écritures : désactivez-le (is_active = false) au lieu de le supprimer.',
        'has_sub_accounts' => 'Le compte :code a des sous-comptes : déplacez-les ou supprimez-les d\'abord.',
        'is_default_posting' => 'Le compte :code est un compte d\'imputation par défaut : choisissez d\'abord un autre compte dans les paramètres de la comptabilité.',
        'parent_cycle' => 'Un compte ne peut pas être placé sous lui-même ni sous l\'un de ses sous-comptes.',
    ],
    'accrual' => [
        'cutoff_outside_coverage' => 'La date d\'arrêté doit tomber dans la période couverte, avant son dernier jour.',
        'nothing_to_post' => 'La quote à la date d\'arrêté est nulle : rien à comptabiliser.',
        'income_account_type' => 'Pour :type, le compte de résultat doit être de type :types.',
        'balance_account_type' => 'Pour :type, le compte de bilan doit être de type :types.',
    ],
    'settings' => [
        'posting_account_type' => 'Le compte pour [:role] doit être un compte de type :types de ce locataire.',
        'fx_provision_account_type' => 'Le compte de provision pour gains de change latents doit être de type :types de ce locataire.',
    ],
    'journal' => [
        'submit_only_draft' => 'Seul un brouillon peut être soumis à approbation ; cette écriture est [:status].',
        'approve_only_pending' => 'Seule une écriture en attente d\'approbation peut être approuvée ; cette écriture est [:status].',
        'four_eyes' => 'Principe des quatre yeux : l\'écriture doit être approuvée par une personne autre que celle qui l\'a saisie ou soumise.',
        'reject_only_pending' => 'Seule une écriture en attente d\'approbation peut être rejetée ; cette écriture est [:status].',
        'needs_second_approval' => 'Cette écriture nécessite l\'approbation d\'une seconde personne : soumettez-la et faites-la approuver.',
        'account_not_in_chart' => 'Le compte n\'appartient pas au plan comptable de ce locataire.',
        'account_inactive' => 'Le compte :code n\'est pas actif.',
        'account_automatic_only' => 'Le compte :code n\'accepte que des écritures automatiques.',
        'cost_center_required' => 'Le compte :code exige un centre de coûts.',
    ],
];
