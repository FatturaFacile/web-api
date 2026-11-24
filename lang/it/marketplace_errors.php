<?php

declare(strict_types=1);

return [
    'installation' => [
        'unknown_connector' => 'Questo connettore non è nel catalogo.',
        'limit' => 'Un\'azienda può avere al massimo :max installazioni dello stesso connettore.',
        'name_taken' => 'Esiste già un\'installazione di questo connettore con questo nome.',
        'scopes_missing' => 'Il connettore richiede che questi ambiti siano concessi: :scopes.',
        'scope_not_allowed' => 'L\'ambito ":scope" non è valido o non puoi concederlo.',
        'not_configured' => 'Configura prima l\'installazione.',
        'unhealthy' => 'Il controllo di stato è fallito, quindi l\'installazione non è stata attivata: :message',
        'up_to_date' => 'L\'installazione usa già l\'ultima versione del connettore.',
        'run_in_progress' => 'C\'è una sincronizzazione in corso: attendi che finisca prima di disinstallare.',
        'rate_limit_above_max' => 'Il limite di richieste non può superare :max chiamate al minuto, il massimo di questo connettore.',
    ],
    'config' => [
        'unknown_field' => 'Questo campo non fa parte della configurazione del connettore.',
        'required' => 'Il campo ":field" è obbligatorio.',
        'invalid_text' => 'Il campo ":field" deve essere un testo di al massimo 255 caratteri.',
        'invalid_secret' => 'Il campo ":field" deve essere un segreto di almeno 4 caratteri.',
        'invalid_number' => 'Il campo ":field" deve essere un numero.',
        'invalid_boolean' => 'Il campo ":field" deve essere vero o falso.',
        'invalid_option' => 'Il campo ":field" ha un valore non consentito.',
        'invalid_url' => 'Il campo ":field" deve essere un indirizzo https pubblico.',
    ],
    'health' => [
        'unreachable' => 'Il sistema esterno non ha risposto.',
        'address_refused' => 'L\'indirizzo del sistema esterno non è consentito.',
        'egress_blocked' => 'La policy di residenza dei dati dell\'azienda non consente di inviare dati verso il paese del sistema esterno.',
        'failed' => 'Non è stato possibile completare il controllo di stato.',
        'credentials_rejected' => 'Il sistema esterno ha rifiutato le credenziali.',
        'rate_limited' => 'Il sistema esterno sta limitando le richieste.',
        'server_error' => 'Il sistema esterno ha risposto con un errore (:status).',
        'unexpected_status' => 'Il sistema esterno ha risposto con uno stato inatteso (:status).',
    ],
    'sync' => [
        'not_enabled' => 'Attiva l\'installazione prima di sincronizzarla.',
        'unknown_resource' => 'Questo connettore non ha la risorsa ":resource".',
        'rate_limited' => 'Il limite di richieste dell\'installazione è esaurito: riprova tra :seconds secondi.',
        'already_running' => 'Questa risorsa è già in corso di sincronizzazione.',
        'stale' => 'L\'esecuzione non è mai terminata ed è stata chiusa da quella successiva.',
        'failed' => 'La sincronizzazione è fallita.',
        'status' => 'Il sistema esterno ha risposto con un errore (:status).',
        'credential_missing' => 'La credenziale ":field" manca: configura di nuovo l\'installazione.',
        'fetched' => 'Letti :count record di :resource.',
        'delivered' => 'L\'evento è stato consegnato.',
    ],
    'mapping' => [
        'missing_field' => 'Il campo ":field" manca.',
        'invalid_amount' => 'L\'importo ":field" non è un numero.',
        'invalid_currency' => 'La valuta non è un codice ISO valido.',
        'invalid_date' => 'La data non è valida.',
        'invalid_lines' => 'Le righe dell\'ordine non sono valide.',
    ],
];
