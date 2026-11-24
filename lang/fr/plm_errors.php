<?php

declare(strict_types=1);

return [
    'order' => [
        'approvers_required' => 'Ajoutez au moins un approbateur avant l’envoi.',
        'affected_required' => 'Ajoutez au moins un article ou une nomenclature concernés avant l’envoi.',
        'not_in_review' => 'La modification n’est pas en révision.',
        'cannot_cancel' => 'Seule une modification en brouillon, en révision ou approuvée peut être annulée.',
        'not_approved' => 'Seule une modification approuvée peut être mise en œuvre.',
        'not_draft' => 'Seule une modification en brouillon peut être changée.',
        'code_taken' => 'Le code :code est déjà utilisé par une autre modification.',
    ],
    'approval' => [
        'not_your_turn' => 'Ce n’est pas votre tour de décider sur cette modification.',
        'reject_comment_required' => 'Un rejet nécessite un commentaire.',
    ],
    'revision' => [
        'exists' => 'La révision :revision existe déjà pour cet article.',
        'change_order_required' => 'L’article a déjà une révision publiée : publiez la nouvelle en mettant en œuvre une modification.',
        'not_released' => 'Seule une révision publiée peut devenir obsolète.',
        'not_draft' => 'Seule une révision en brouillon peut être modifiée ou publiée.',
    ],
];
