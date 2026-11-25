<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Dati delle note spese caricati.',
        'saved' => 'Salvato.',
        'deleted' => 'Eliminato.',
        'submitted' => 'La nota spese è stata inviata in approvazione.',
        'approved' => 'La nota spese è stata approvata.',
        'rejected' => 'La nota spese è stata respinta.',
        'exported' => 'Il pagamento è stato registrato.',
        'scanned' => 'Ricevuta letta.',
    ],
    'errors' => [
        'report_not_editable' => 'La nota spese non è più modificabile.',
        'report_empty' => 'La nota spese non ha righe né indennità.',
        'blocking_violations' => ':count riga/righe con violazioni bloccanti della policy.',
        'missing_receipt' => 'La ricevuta è obbligatoria.',
        'invalid_transition' => 'La nota spese non può passare da :from a :to.',
        'self_approval_forbidden' => 'Non puoi approvare la tua nota spese.',
        'rejection_reason_required' => 'Per respingere una nota spese serve un motivo.',
        'no_reimbursable_amount' => 'Non c\'è nulla da rimborsare.',
        'employee_iban_missing' => 'Il dipendente non ha un IBAN nel profilo paghe.',
        'debtor_account_missing' => 'Nessun conto bancario aziendale attivo nella valuta della nota.',
        'card_transaction_mismatch' => 'Il movimento della carta non corrisponde alla riga di spesa (valuta o dati).',
        'card_transaction_already_matched' => 'Il movimento della carta o la riga di spesa sono già abbinati.',
        'amount_exceeds_transaction' => 'Gli importi abbinati superano il movimento della carta (:amount).',
        'vehicle_rate_not_found' => 'Nessuna tariffa al km per il veicolo :vehicle a quella data.',
        'currency_rate_missing' => 'Nessun cambio da :from a :to: inserisci il tasso.',
        'receipt_not_found' => 'Ricevuta non trovata.',
        'period_invalid' => 'La fine del periodo precede l\'inizio.',
        'category_not_found' => 'Categoria di spesa non trovata.',
        'approved_amount_exceeds' => 'L\'importo approvato supera l\'importo rimborsabile (:max).',
    ],
];
