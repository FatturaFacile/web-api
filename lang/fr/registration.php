<?php

declare(strict_types=1);

return [
    'messages' => [
        'registered' => 'Inscription reçue. Consultez votre messagerie pour confirmer votre adresse e-mail.',
        'verified' => 'Adresse e-mail confirmée. Votre compte est maintenant en attente d\'approbation.',
        'resent' => 'Si l\'adresse doit être confirmée, vous recevrez un nouvel e-mail.',
        'requests_listed' => 'Demandes d\'accès récupérées',
        'request_decided' => 'Demande d\'accès mise à jour',
    ],
    'errors' => [
        'email_not_verified_title' => 'E-mail non confirmé',
        'email_not_verified_detail' => 'Veuillez confirmer votre adresse e-mail avant de vous connecter.',
        'approval_pending_title' => 'Compte en attente d\'approbation',
        'approval_pending_detail' => 'Un administrateur doit encore approuver votre accès à l\'entreprise.',
        'access_rejected_title' => 'Accès non approuvé',
        'access_rejected_detail' => 'Votre demande d\'accès n\'a pas été approuvée.',
        'token_invalid_title' => 'Lien non valide',
        'token_invalid_detail' => 'Le lien de confirmation n\'est pas valide ou a déjà été utilisé.',
        'token_expired_title' => 'Lien expiré',
        'token_expired_detail' => 'Le lien de confirmation a expiré. Veuillez en demander un nouveau.',
        'forbidden_title' => 'Opération non autorisée',
        'forbidden_detail' => 'Cette opération est réservée aux administrateurs de la plateforme.',
        'not_decidable_title' => 'Demande non modifiable',
        'not_decidable_detail' => 'La demande a déjà été traitée ou l\'utilisateur n\'a pas encore confirmé son adresse e-mail.',
        'invalid_tax_id' => 'Le numéro de TVA ou l\'IDE n\'est pas valide pour le pays sélectionné.',
    ],
    'mail' => [
        'greeting' => 'Bonjour :name,',
        'support' => 'Pour toute assistance, écrivez à :email.',
        'signature' => 'L\'équipe :app',
        'verify' => [
            'subject' => 'Confirmez votre adresse e-mail - :app',
            'intro' => 'Merci de votre inscription sur :app. Veuillez confirmer votre adresse e-mail pour continuer.',
            'action' => 'Confirmer l\'adresse e-mail',
            'expires' => 'Ce lien est valable jusqu\'au :date.',
            'approval' => 'Après la confirmation, un administrateur approuvera votre accès à l\'entreprise et vous recevrez un e-mail.',
            'security' => 'Si vous n\'êtes pas à l\'origine de cette inscription, ignorez ce message : aucun compte ne sera activé.',
        ],
        'exists' => [
            'subject' => 'Tentative d\'inscription sur votre compte - :app',
            'intro' => 'Quelqu\'un a essayé de s\'inscrire sur :app avec cette adresse e-mail, qui appartient déjà à un compte.',
            'security' => 'Si c\'était vous, connectez-vous avec vos identifiants. Sinon, vous pouvez ignorer ce message : votre compte n\'a pas été modifié.',
        ],
        'decision' => [
            'approved_subject' => 'Accès approuvé - :app',
            'approved_body' => 'Votre accès à :app a été approuvé. Vous pouvez maintenant vous connecter.',
            'rejected_subject' => 'Accès non approuvé - :app',
            'rejected_body' => 'Votre demande d\'accès à :app n\'a pas été approuvée.',
            'action' => 'Se connecter',
        ],
    ],
];
