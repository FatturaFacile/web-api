<?php

declare(strict_types=1);

return [
    'password' => [
        'too_short' => 'La password deve avere almeno :min caratteri.',
        'mixed_case' => 'La password deve contenere lettere maiuscole e minuscole.',
        'numbers' => 'La password deve contenere almeno un numero.',
        'symbols' => 'La password deve contenere almeno un simbolo.',
        'reused' => 'La password è stata usata di recente: scegline una diversa dalle ultime :count.',
    ],
    'messages' => [
        'policy_updated' => 'Politica di sicurezza aggiornata.',
        'sso_saved' => 'Provider di single sign-on salvato.',
        'sso_removed' => 'Provider di single sign-on rimosso.',
        'scim_token_rotated' => 'Token SCIM creato. Copialo ora: non verrà più mostrato.',
        'scim_token_revoked' => 'Token SCIM revocato.',
    ],
    'errors' => [
        'account_locked_title' => 'Account bloccato',
        'account_locked_detail' => 'Troppe password errate. L\'account è bloccato per un po\': riprova più tardi.',
        'password_expired_title' => 'Password scaduta',
        'password_expired_detail' => 'La password è più vecchia dell\'età massima stabilita dalla tua organizzazione. Reimpostala con il link "password dimenticata" per accedere.',
        'sso_required_title' => 'Single sign-on obbligatorio',
        'sso_required_detail' => 'La tua organizzazione richiede il single sign-on: accedi tramite il provider di identità aziendale.',
        'session_expired_title' => 'Sessione scaduta',
        'session_expired_detail' => 'La sessione è stata chiusa dopo un periodo di inattività. Accedi di nuovo.',
        'session_tenant_mismatch_title' => 'Organizzazione errata',
        'session_tenant_mismatch_detail' => 'Questa sessione è stata aperta con il single sign-on di un\'altra organizzazione e non può essere usata qui. Accedi di nuovo.',
        'sso_not_ready_title' => 'Single sign-on non pronto',
        'sso_not_ready_detail' => 'La modalità solo SSO richiede un provider di identità attivo e il tuo account collegato ad esso, altrimenti resteresti fuori. Accedi prima una volta tramite SSO.',
        'sso_only_active_title' => 'Modalità solo SSO attiva',
        'sso_only_active_detail' => 'Disattiva la modalità solo SSO prima di disabilitare o rimuovere il provider di identità.',
        'sso_not_configured_title' => 'Nessun provider di identità',
        'sso_not_configured_detail' => 'Questa organizzazione non ha un provider di single sign-on configurato.',
        'sso_unavailable_title' => 'Single sign-on non disponibile',
        'sso_unavailable_detail' => 'Il single sign-on non è disponibile per questa organizzazione.',
        'sso_failed_title' => 'Accesso non riuscito',
        'sso_failed_detail' => 'Non è stato possibile completare l\'accesso con il provider di identità.',
        'roles_required' => 'Scegli almeno un ruolo.',
        'https_required' => 'L\'indirizzo deve usare https.',
        'endpoint_required' => 'Questo endpoint è obbligatorio quando non si usa la discovery.',
        'jit_needs_role' => 'Il provisioning just-in-time richiede un ruolo predefinito o una mappatura da claim a ruolo.',
        'endpoint_unsafe' => 'L\'endpoint non è consentito (:reason).',
        'discovery_failed' => 'Il documento di discovery non è utilizzabile (:reason).',
    ],
];
