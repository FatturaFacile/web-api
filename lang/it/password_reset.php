<?php

declare(strict_types=1);

return [
    'messages' => [
        'requested' => 'Se l\'indirizzo email esiste nel sistema, riceverai una email con le istruzioni per il recupero della password.',
        'token_valid' => 'Il link è valido.',
        'updated' => 'Password aggiornata. Ora puoi accedere con la nuova password.',
    ],
    'errors' => [
        'token_invalid_title' => 'Link non valido',
        'token_invalid_detail' => 'Il link per reimpostare la password non è valido o è già stato usato.',
        'token_expired_title' => 'Link scaduto',
        'token_expired_detail' => 'Il link per reimpostare la password è scaduto. Richiedine uno nuovo.',
        'locked_title' => 'Troppi tentativi',
        'locked_detail' => 'Troppi tentativi non riusciti. Riprova più tardi.',
    ],
    'mail' => [
        'reset' => [
            'subject' => 'Reimposta la tua password - :app',
            'intro' => 'Abbiamo ricevuto una richiesta per reimpostare la password del tuo account su :app.',
            'action' => 'Reimposta password',
            'expires' => 'Il link è valido fino al :date e può essere usato una sola volta.',
            'security' => 'Se non hai richiesto tu il recupero, ignora questo messaggio: la tua password resta invariata. Non condividere mai questo link.',
        ],
        'changed' => [
            'subject' => 'La tua password è stata modificata - :app',
            'intro' => 'La password del tuo account su :app è stata modificata. Per sicurezza, tutte le sessioni attive sono state chiuse.',
            'date' => 'Data e ora: :date',
            'ip' => 'Indirizzo IP: :ip',
            'device' => 'Browser e dispositivo: :device',
            'unknown' => 'non disponibile',
            'security' => 'Se non sei stato tu, contatta subito il supporto: qualcuno potrebbe aver avuto accesso alla tua casella di posta.',
            'security_profile' => 'Se non sei stato tu, qualcuno conosce la tua password: reimpostala subito con «Password dimenticata» e contatta il supporto.',
        ],
        'alert' => [
            'subject' => 'Molte richieste di recupero password - :app',
            'intro' => 'Abbiamo ricevuto molte richieste di recupero password per il tuo account su :app e le abbiamo bloccate temporaneamente.',
            'security' => 'La tua password non è stata modificata. Se non sei stato tu, puoi ignorare questo messaggio; se continui a riceverlo, contatta il supporto.',
        ],
    ],
];
