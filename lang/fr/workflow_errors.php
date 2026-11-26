<?php

declare(strict_types=1);

return [
    'delegation' => [
        'same_user' => 'Le délégant et le délégué ne peuvent pas être le même utilisateur.',
        'circular' => 'Délégation circulaire refusée : l\'utilisateur délégué vous a déjà délégué ses tâches.',
        'cycle' => 'Délégation refusée : elle fermerait une chaîne de délégations (A vers B vers C et retour).',
        'overlap' => 'Vous avez déjà une délégation active couvrant une partie de cette période.',
    ],
    'control' => [
        'self_approval' => 'Celui qui demande une approbation ne peut pas l\'approuver.',
        'multi_level' => 'Le même utilisateur ne peut pas approuver deux niveaux du même flux.',
        'signing_limit' => 'Le montant dépasse votre limite de signature (:limit).',
    ],
    'lifecycle' => [
        'not_active' => 'L\'approbation est déjà :status et ne peut plus être modifiée.',
        'withdraw_not_requester' => 'Seul le demandeur peut retirer une approbation.',
        'withdraw_already_decided' => 'L\'approbation ne peut pas être retirée : une décision a déjà été prise.',
        'reason_required' => 'Un motif est obligatoire.',
        'reassign_target' => 'Le nouvel approbateur doit être membre de l\'entreprise.',
        'reassign_not_allowed' => 'Cette étape ne peut pas être réassignée.',
        'document_changed' => 'Le document a changé après l\'approbation (:fields) : l\'approbation a redémarré.',
        'blocked_no_approver' => 'Aucun approbateur ne peut être déterminé pour l\'étape « :node ».',
        'blocked_no_matrix_rule' => 'Aucune règle d\'approbation ne correspond au document et aucune règle par défaut n\'est configurée.',
    ],
    'token' => [
        'invalid' => 'Ce lien d\'approbation n\'est pas valide.',
        'used' => 'Ce lien d\'approbation a déjà été utilisé.',
        'expired' => 'Ce lien d\'approbation a expiré.',
        'wrong_user' => 'Ce lien d\'approbation a été émis pour un autre utilisateur.',
        'wrong_action' => 'Ce lien d\'approbation n\'autorise pas cette action.',
        'confirm_required' => 'Confirmez la décision sur la page de confirmation.',
        'reauth_required' => 'Reconnectez-vous pour approuver ce montant.',
        'reauth_failed' => 'Le mot de passe ou le code de vérification est incorrect.',
    ],
    'inbox' => [
        'period_too_long' => 'La période ne peut pas dépasser 366 jours.',
    ],
    'definition' => [
        'publish_to_activate' => 'Publiez la définition pour l\'activer.',
        'has_running_instances' => 'La définition a des instances en cours : annulez-les ou désactivez la définition.',
        'graph_locked' => 'Seule une définition en brouillon peut modifier son graphe : créez une nouvelle version.',
        'stale' => 'La définition a été modifiée par quelqu\'un d\'autre : rechargez-la et réappliquez vos modifications.',
    ],
    'matrix' => [
        'unknown_subject' => 'Type de document inconnu.',
        'tier_range' => 'La borne inférieure d\'un niveau ne peut pas dépasser la borne supérieure.',
        'needs_tier' => 'Une matrice nécessite au moins un niveau ou un approbateur de repli.',
    ],
    'calendar' => [
        'unknown_country' => 'Les jours fériés ne peuvent être générés que pour CH et IT.',
    ],
];
