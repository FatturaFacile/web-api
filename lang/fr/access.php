<?php

declare(strict_types=1);

return [
    'mail' => [
        'invitation' => [
            'subject' => 'Invitation à rejoindre :tenant',
            'title' => 'Rejoindre :tenant',
            'intro' => 'Vous êtes invité à rejoindre :tenant sur :app.',
            'intro_by' => ':name vous invite à rejoindre :tenant sur :app.',
            'action' => 'Accepter l\'invitation',
            'expires' => 'L\'invitation expire le :date.',
            'ignore' => 'Si vous n\'attendiez pas cette invitation, vous pouvez ignorer cet e-mail.',
        ],
    ],
    'errors' => [
        'plan_limit_title' => 'Limite du forfait atteinte',
        'plan_limit_detail' => 'Le forfait d\'abonnement autorise au maximum :max :limit. Passez à un forfait supérieur pour en ajouter.',
        'invitation_invalid_title' => 'Invitation non valide',
        'role_in_use_title' => 'Rôle utilisé',
        'delegation_invalid_title' => 'Délégation non valide',
        'import_invalid_title' => 'Fichier d\'importation non valide',
    ],
];
