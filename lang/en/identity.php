<?php

declare(strict_types=1);

return [
    'notifications' => [
        'passkey_registered' => ['title' => 'Passkey added', 'body' => 'A new passkey was added to your account. If this was not you, remove it and end your sessions.'],
        'passkey_revoked' => ['title' => 'Passkey removed', 'body' => 'A passkey was removed from your account.'],
        'mfa_disabled' => ['title' => 'Two-factor authentication turned off', 'body' => 'Two-factor authentication was turned off for your account.'],
        'sessions_revoked' => ['title' => 'Sessions ended', 'body' => 'All your sessions and tokens were ended. Sign in again.'],
        'role_changed' => ['title' => 'Your roles changed', 'body' => 'An administrator changed the roles assigned to you.'],
        'account_locked' => ['title' => 'Account locked', 'body' => 'Your account was locked after too many failed sign-in attempts.'],
        'impersonation_started' => ['title' => 'Support session started', 'body' => 'A support session on your account was started with recorded consent.'],
    ],
];
