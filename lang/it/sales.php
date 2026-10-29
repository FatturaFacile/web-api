<?php

declare(strict_types=1);

return [
    'order_already_invoiced' => 'Questo ordine è già stato fatturato.',
    'order_cancelled' => 'Un ordine annullato non può essere fatturato.',
    'deposit_line' => 'Acconto per ordine :number (:percentage%)',
    'deposit_deduction' => 'Meno: fattura di acconto :number',
    'deposit_exceeds_order' => 'Il totale delle fatture di acconto non può superare il totale dell\'ordine.',
    'delivery_note_already_invoiced' => 'Questo documento di trasporto è già stato fatturato.',

    'quote_not_convertible' => 'Un preventivo rifiutato o scaduto non può essere convertito.',
    'quote_already_invoiced' => 'Questo preventivo è già stato fatturato.',
    'quote_already_signed' => 'Questo preventivo è già stato firmato.',
    'quote_not_signable' => 'Un preventivo rifiutato o scaduto non può essere firmato.',

    'quote_already_converted' => 'Questo preventivo è già stato convertito in ordine.',
    'order_not_deliverable' => 'Solo gli ordini confermati possono essere spediti.',
    'nothing_to_deliver' => 'Nessun articolo rimasto da spedire.',
    'proforma_only' => 'Solo le fatture proforma possono essere convertite.',
    'proforma_already_converted' => 'Questa proforma è già stata convertita.',
    'payment_on_proforma' => 'Non è possibile registrare pagamenti su una fattura proforma.',
    'payment_exceeds_balance' => 'Il pagamento supera il saldo dovuto.',
    'import_line_matched' => 'Questa riga è già stata abbinata.',

    'signature_invalid' => 'La firma deve essere un\'immagine PNG non superiore a :max KB.',
    'reminder_not_payable' => 'Solo le fatture insolute e pagabili possono ricevere solleciti.',
    'reminder_no_email' => 'Il contatto non dispone di un indirizzo email.',
    'bank_import_note' => 'Importazione bancaria: :detail',

    'messages' => [
        'quotes_list' => 'Preventivi recuperati con successo',
        'quote_created' => 'Preventivo creato con successo',
        'quote_retrieved' => 'Preventivo recuperato con successo',
        'quote_updated' => 'Preventivo aggiornato con successo',
        'quote_deleted' => 'Preventivo eliminato con successo',
        'quote_to_order' => 'Preventivo convertito in ordine',
        'quote_submitted' => 'Preventivo inviato per approvazione',
        'order_created' => 'Ordine di vendita creato con successo',
        'order_confirmed' => 'Ordine confermato con successo',
        'order_hold_placed' => 'Blocco fido applicato all\'ordine',
        'order_hold_released' => 'Blocco fido rilasciato con successo',
        'delivery_note_created' => 'Documento di trasporto (DDT) creato con successo',
        'goods_dispatched' => 'Merce spedita con successo',
        'invoice_created' => 'Fattura creata con successo',
        'invoice_posted' => 'Fattura contabilizzata con successo',
        'credit_note_created' => 'Nota di credito emessa con successo',
        'credit_applied' => 'Nota di credito applicata con successo',
    ],
];
