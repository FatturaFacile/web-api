<?php

declare(strict_types=1);

return [
    'notifications' => [
        'passkey_registered' => ['title' => 'Passkey ajoutée', 'body' => 'Une nouvelle passkey a été ajoutée à votre compte. Si ce n\'était pas vous, supprimez-la et terminez vos sessions.'],
        'passkey_revoked' => ['title' => 'Passkey supprimée', 'body' => 'Une passkey a été supprimée de votre compte.'],
        'mfa_disabled' => ['title' => 'Authentification à deux facteurs désactivée', 'body' => 'L\'authentification à deux facteurs a été désactivée pour votre compte.'],
        'sessions_revoked' => ['title' => 'Sessions terminées', 'body' => 'Toutes vos sessions et tous vos jetons ont été terminés. Reconnectez-vous.'],
        'role_changed' => ['title' => 'Vos rôles ont changé', 'body' => 'Un administrateur a modifié les rôles attribués à votre compte.'],
        'account_locked' => ['title' => 'Compte verrouillé', 'body' => 'Votre compte a été verrouillé après trop de tentatives de connexion échouées.'],
        'impersonation_started' => ['title' => 'Session de support démarrée', 'body' => 'Une session de support a été démarrée sur votre compte avec un consentement enregistré.'],
    ],
];
