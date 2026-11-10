<?php

declare(strict_types=1);

return [
    'messages' => [
        'requested' => 'Si l\'adresse e-mail existe dans notre système, vous recevrez un e-mail contenant les instructions pour récupérer votre mot de passe.',
        'token_valid' => 'Le lien est valide.',
        'updated' => 'Mot de passe mis à jour. Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.',
    ],
    'errors' => [
        'token_invalid_title' => 'Lien non valide',
        'token_invalid_detail' => 'Le lien de réinitialisation du mot de passe n\'est pas valide ou a déjà été utilisé.',
        'token_expired_title' => 'Lien expiré',
        'token_expired_detail' => 'Le lien de réinitialisation du mot de passe a expiré. Veuillez en demander un nouveau.',
        'locked_title' => 'Trop de tentatives',
        'locked_detail' => 'Trop de tentatives échouées. Veuillez réessayer plus tard.',
    ],
    'mail' => [
        'reset' => [
            'subject' => 'Réinitialisez votre mot de passe - :app',
            'intro' => 'Nous avons reçu une demande de réinitialisation du mot de passe de votre compte :app.',
            'action' => 'Réinitialiser le mot de passe',
            'expires' => 'Le lien est valable jusqu\'au :date et ne peut être utilisé qu\'une seule fois.',
            'security' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé. Ne partagez jamais ce lien.',
        ],
        'changed' => [
            'subject' => 'Votre mot de passe a été modifié - :app',
            'intro' => 'Le mot de passe de votre compte :app a été modifié. Pour votre sécurité, toutes les sessions actives ont été fermées.',
            'date' => 'Date et heure : :date',
            'ip' => 'Adresse IP : :ip',
            'device' => 'Navigateur et appareil : :device',
            'unknown' => 'non disponible',
            'security' => 'Si ce n\'était pas vous, contactez immédiatement le support : quelqu\'un a peut-être eu accès à votre boîte mail.',
            'security_profile' => 'Si ce n\'était pas vous, quelqu\'un connaît votre mot de passe : réinitialisez-le immédiatement avec « Mot de passe oublié » et contactez le support.',
        ],
        'alert' => [
            'subject' => 'Nombreuses demandes de récupération du mot de passe - :app',
            'intro' => 'Nous avons reçu de nombreuses demandes de récupération du mot de passe pour votre compte :app et les avons temporairement bloquées.',
            'security' => 'Votre mot de passe n\'a pas été modifié. Si ce n\'était pas vous, vous pouvez ignorer ce message ; si vous continuez à le recevoir, contactez le support.',
        ],
    ],
];
