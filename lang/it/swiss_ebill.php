<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Registri recuperati con successo.',
        'loaded' => 'Registro recuperato con successo.',
        'saved' => 'Le impostazioni eBill svizzero sono state salvate.',
        'connection_ok' => 'Il gateway eBill è raggiungibile e ha accettato le credenziali.',
        'connection_failed' => 'Il test del gateway eBill non è riuscito.',
        'secret_rotated' => 'È stato emesso un nuovo segreto del webhook. Copialo ora: non verrà mostrato di nuovo.',
        'participant_found' => 'Il partecipante è iscritto alla directory eBill.',
        'participant_not_found' => 'Nessun partecipante corrisponde a questo identificativo nella directory eBill.',
        'customer_synced' => 'Lo stato eBill del cliente è stato verificato.',
        'customer_linked' => 'Il cliente è stato collegato al partecipante eBill.',
        'bulk_verify_queued' => 'La verifica dei clienti prosegue in background.',
        'transmission_queued' => 'La fattura è stata messa in coda per l’invio eBill.',
        'transmission_cancelled' => 'L’eBill è stato richiamato.',
        'webhook_processed' => 'Le notifiche sono state elaborate.',
    ],

    'errors' => [
        'ebill_disabled' => 'L’eBill svizzero non è attivo per questa azienda.',
        'not_configured' => 'L’eBill svizzero non è configurato per questa azienda.',
        'already_transmitted' => 'Questa fattura ha già una trasmissione eBill attiva.',
        'cannot_revise' => 'Solo una trasmissione rifiutata, fallita o annullata può essere rivista.',
        'cannot_retry' => 'Solo una trasmissione fallita per un errore tecnico può essere ritentata.',
        'cannot_cancel' => 'Questo eBill non può più essere richiamato.',
        'gateway_error' => 'Il gateway eBill ha rifiutato la richiesta.',
        'gateway_unavailable' => 'Il gateway eBill non è raggiungibile in questo momento. Riprova più tardi.',
        'payload_invalid' => 'L’eBill non può essere costruito: mancano dati obbligatori o non sono validi.',
        'invalid_transition' => 'L’eBill non può passare a questo stato da quello attuale.',
        'invalid_signature' => 'La firma del webhook non è valida.',
        'unknown_webhook' => 'Endpoint webhook sconosciuto.',
        'unknown_transaction' => 'Nessun eBill ha questo identificativo di transazione.',
        'payload_unreadable' => 'Il corpo del webhook non è leggibile.',
        'participant_not_found' => 'Il partecipante non è nella directory eBill.',
        'invalid_lookup_value' => 'L’identificativo non è valido per questo tipo di ricerca.',
        'payload_unavailable' => 'Nessun payload è archiviato per questo eBill.',
        'sandbox_not_allowed' => 'Il simulatore sandbox non è disponibile in produzione.',
    ],

    'notification' => [
        'rejected_subject' => 'eBill rifiutato dal cliente: fattura :number',
        'rejected_line' => 'Il cliente :customer ha rifiutato nell’e-banking l’eBill della fattura :number.',
        'rejected_code' => 'Codice di rifiuto: :code',
        'rejected_reason' => 'Motivo indicato dal cliente: :reason',
        'rejected_action' => 'Apri la fattura, correggi quanto serve e usa «Rivedi e reinvia eBill».',
    ],
];
