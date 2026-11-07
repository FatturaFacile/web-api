<?php

declare(strict_types=1);

return [
    'messages' => [
        'challenge_required' => 'Inserisci il codice dell\'app di autenticazione per completare l\'accesso.',
        'setup_started' => 'Scansiona il codice QR con l\'app di autenticazione e conferma con il primo codice.',
        'enabled' => 'Autenticazione a due fattori attivata. Conserva i codici di recupero in un luogo sicuro.',
        'disabled' => 'Autenticazione a due fattori disattivata.',
        'recovery_codes_regenerated' => 'Nuovi codici di recupero generati. Quelli precedenti non sono più validi.',
        'policy_updated' => 'Regola dell\'autenticazione a due fattori aggiornata.',
        'member_reset' => 'Autenticazione a due fattori dell\'utente azzerata.',
    ],
    'errors' => [
        'challenge_invalid_title' => 'Accesso scaduto',
        'challenge_invalid_detail' => 'La verifica è scaduta o non è valida. Accedi di nuovo con email e password.',
        'code_invalid_title' => 'Codice non valido',
        'code_invalid_detail' => 'Il codice inserito non è valido o è già stato usato.',
        'password_invalid' => 'La password non è corretta.',
        'already_enabled_title' => 'Già attiva',
        'already_enabled_detail' => 'L\'autenticazione a due fattori è già attiva. Disattivala prima di configurarla di nuovo.',
        'no_pending_setup_title' => 'Nessuna configurazione in corso',
        'no_pending_setup_detail' => 'Avvia prima la configurazione dell\'autenticazione a due fattori.',
        'not_enabled_title' => 'Non attiva',
        'not_enabled_detail' => 'L\'autenticazione a due fattori non è attiva.',
        'setup_required_title' => 'Autenticazione a due fattori obbligatoria',
        'setup_required_detail' => 'L\'azienda richiede l\'autenticazione a due fattori. Configurala per continuare.',
        'admin_setup_required_title' => 'Configura prima i tuoi due fattori',
        'admin_setup_required_detail' => 'Attiva l\'autenticazione a due fattori sul tuo account prima di renderla obbligatoria.',
        'member_not_found_title' => 'Utente non trovato',
        'member_not_found_detail' => 'L\'utente non fa parte di questa azienda.',
        'reset_not_allowed_title' => 'Azzeramento non consentito',
        'reset_not_allowed_detail' => 'Non puoi azzerare il tuo account, un amministratore della piattaforma o un utente che appartiene anche ad altre aziende.',
    ],
];
