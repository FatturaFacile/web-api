<?php

declare(strict_types=1);

return [
    'messages' => [
        'registered' => 'Registrazione ricevuta. Controlla la posta per confermare l\'indirizzo email.',
        'verified' => 'Indirizzo email confermato. Il tuo account è ora in attesa di approvazione.',
        'resent' => 'Se l\'indirizzo richiede una conferma, riceverai una nuova email.',
        'requests_listed' => 'Richieste di accesso recuperate',
        'request_decided' => 'Richiesta di accesso aggiornata',
    ],
    'errors' => [
        'email_not_verified_title' => 'Email non confermata',
        'email_not_verified_detail' => 'Conferma il tuo indirizzo email prima di accedere.',
        'approval_pending_title' => 'Account in attesa di approvazione',
        'approval_pending_detail' => 'Un amministratore deve ancora approvare il tuo accesso all\'azienda.',
        'access_rejected_title' => 'Accesso non approvato',
        'access_rejected_detail' => 'La tua richiesta di accesso non è stata approvata.',
        'token_invalid_title' => 'Link non valido',
        'token_invalid_detail' => 'Il link di conferma non è valido o è già stato usato.',
        'token_expired_title' => 'Link scaduto',
        'token_expired_detail' => 'Il link di conferma è scaduto. Richiedine uno nuovo.',
        'forbidden_title' => 'Operazione non consentita',
        'forbidden_detail' => 'Questa operazione è riservata agli amministratori della piattaforma.',
        'not_decidable_title' => 'Richiesta non modificabile',
        'not_decidable_detail' => 'La richiesta è già stata gestita oppure l\'utente non ha ancora confermato l\'email.',
        'invalid_tax_id' => 'La partita IVA o l\'UID non è valido per il paese selezionato.',
    ],
    'mail' => [
        'greeting' => 'Ciao :name,',
        'support' => 'Per assistenza scrivi a :email.',
        'signature' => 'Il team di :app',
        'verify' => [
            'subject' => 'Conferma il tuo indirizzo email - :app',
            'intro' => 'Grazie per esserti registrato su :app. Conferma il tuo indirizzo email per proseguire.',
            'action' => 'Conferma indirizzo email',
            'expires' => 'Il link è valido fino al :date.',
            'approval' => 'Dopo la conferma, un amministratore approverà il tuo accesso all\'azienda e riceverai un\'email.',
            'security' => 'Se non hai richiesto tu questa registrazione, ignora questo messaggio: nessun account verrà attivato.',
        ],
        'exists' => [
            'subject' => 'Tentativo di registrazione sul tuo account - :app',
            'intro' => 'Qualcuno ha provato a registrarsi su :app con questo indirizzo email, che è già associato a un account.',
            'security' => 'Se sei stato tu, accedi con le tue credenziali. Altrimenti puoi ignorare questo messaggio: il tuo account non è stato modificato.',
        ],
        'decision' => [
            'approved_subject' => 'Accesso approvato - :app',
            'approved_body' => 'Il tuo accesso su :app è stato approvato. Ora puoi accedere.',
            'rejected_subject' => 'Accesso non approvato - :app',
            'rejected_body' => 'La tua richiesta di accesso su :app non è stata approvata.',
            'action' => 'Accedi',
        ],
    ],
];
