<?php

declare(strict_types=1);

return [
    'notifications' => [
        'passkey_registered' => ['title' => 'Passkey aggiunta', 'body' => 'È stata aggiunta una nuova passkey al tuo account. Se non sei stato tu, rimuovila e termina le sessioni.'],
        'passkey_revoked' => ['title' => 'Passkey rimossa', 'body' => 'Una passkey è stata rimossa dal tuo account.'],
        'mfa_disabled' => ['title' => 'Autenticazione a due fattori disattivata', 'body' => 'L\'autenticazione a due fattori è stata disattivata per il tuo account.'],
        'sessions_revoked' => ['title' => 'Sessioni terminate', 'body' => 'Tutte le tue sessioni e i tuoi token sono stati terminati. Accedi di nuovo.'],
        'role_changed' => ['title' => 'I tuoi ruoli sono cambiati', 'body' => 'Un amministratore ha modificato i ruoli assegnati al tuo account.'],
        'account_locked' => ['title' => 'Account bloccato', 'body' => 'Il tuo account è stato bloccato dopo troppi tentativi di accesso falliti.'],
        'impersonation_started' => ['title' => 'Sessione di supporto avviata', 'body' => 'È stata avviata una sessione di supporto sul tuo account con consenso registrato.'],
    ],
];
