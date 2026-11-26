<?php

declare(strict_types=1);

return [
    'password' => [
        'breached' => 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre.',
    ],
    'messages' => [
        'member_unlocked' => 'Le compte a été déverrouillé.',
        'scim_mapping_saved' => 'Correspondance du groupe enregistrée.',
        'scim_mapping_removed' => 'Correspondance du groupe supprimée.',
        'security_audit_retrieved' => 'Journal d’audit de sécurité récupéré.',
    ],
    'errors' => [
        'lockout_cap_too_small' => 'La durée maximale du verrouillage ne peut pas être inférieure à la durée de base.',
        'unknown_event_type' => 'Type d’événement inconnu : :type.',
    ],
    'mail' => [
        'unknown' => 'inconnu',
        'locked' => [
            'subject' => ':app : votre compte a été verrouillé',
            'intro' => 'Votre compte :app a été verrouillé pendant :minutes minutes après trop de mots de passe erronés.',
            'until' => 'Verrouillé jusqu’à : :date',
            'ip' => 'Adresse IP des tentatives : :ip',
            'device' => 'Appareil : :device',
            'security' => 'Si ce n’était pas vous, quelqu’un essaie peut-être de deviner votre mot de passe : changez-le dès la fin du verrouillage et activez l’authentification à deux facteurs. Des verrouillages répétés allongent le suivant.',
        ],
        'unlocked' => [
            'subject' => ':app : votre compte a été déverrouillé',
            'intro' => 'Un administrateur de votre organisation a déverrouillé votre compte :app : vous pouvez vous reconnecter.',
            'security' => 'Si vous n’en êtes pas à l’origine, contactez l’administrateur de votre organisation.',
        ],
    ],
];
