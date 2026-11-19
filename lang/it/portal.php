<?php

declare(strict_types=1);

return [
    'mail' => [
        'magic_link' => [
            'subject' => 'Il tuo link di accesso per :company',
            'greeting' => 'Buongiorno :name',
            'intro' => 'Usa il pulsante qui sotto per aprire la tua area clienti presso :company. Il link funziona una sola volta e scade tra :minutes minuti.',
            'action' => 'Apri la mia area',
            'security' => 'Se non hai richiesto questo link, puoi ignorare questa email.',
        ],
        'otp' => [
            'subject' => 'Il tuo codice di verifica per :company',
            'greeting' => 'Buongiorno :name',
            'intro' => "Inserisci questo codice per completare l'accesso a :company. Scade tra :minutes minuti.",
            'security' => 'Non condividere mai questo codice. Se non hai tentato di accedere, ignora questa email.',
        ],
    ],
    'messages' => [
        'link_requested' => "Se l'indirizzo appartiene a un cliente, abbiamo inviato un link di accesso.",
        'otp_required' => 'Abbiamo inviato un codice di verifica al tuo indirizzo email.',
        'authenticated' => 'Accesso effettuato.',
        'logged_out' => 'Disconnessione effettuata.',
        'checkout_created' => 'Pagina di pagamento creata.',
        'ticket_created' => 'La tua richiesta è stata inviata.',
    ],
    'errors' => [
        'otp_invalid' => 'Il codice di verifica non è corretto.',
        'otp_locked' => 'Troppi codici errati. Richiedi un nuovo link di accesso.',
        'session_required' => "Accedi all'area clienti per continuare.",
        'invoice_not_payable' => 'Questa fattura non ha importi da pagare online.',
        'xml_not_available' => 'Non esiste un file di fattura elettronica per questo documento.',
        'ticket_target_invalid' => 'Il documento collegato non appartiene al tuo account.',
    ],
];
