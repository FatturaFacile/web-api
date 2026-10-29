<?php

declare(strict_types=1);

return [
    'order_locked' => 'Solo gli ordini di acquisto in bozza o inviati possono essere modificati.',
    'order_not_confirmable' => 'Solo gli ordini di acquisto in bozza possono essere confermati.',
    'order_not_receivable' => 'La merce può essere ricevuta solo per ordini inviati o parzialmente ricevuti.',
    'receipt_exceeds_order' => 'La quantità per ":description" supera la quantità ordinata residua.',
    'nothing_to_receive' => 'Non è rimasto nulla da ricevere.',

    'invalid_fatturapa_title' => 'File FatturaPA non valido',
    'unknown_recipient_title' => 'Destinatario sconosciuto',
    'invalid_fatturapa' => 'Il file non è un documento FatturaPA valido.',
    'recipient_mismatch' => 'La fattura è intestata a una partita IVA differente.',
    'unknown_recipient' => 'Nessun tenant corrisponde al destinatario della fattura.',
    'credit_note_not_payable' => 'Le note di credito non possono essere pagate direttamente.',
    'already_paid' => 'Questa fattura fornitore risulta già saldata.',

    'messages' => [
        'order_created' => 'Ordine di acquisto creato con successo',
        'order_updated' => 'Ordine di acquisto aggiornato con successo',
        'order_retrieved' => 'Ordine di acquisto recuperato con successo',
        'order_confirmed' => 'Ordine di acquisto confermato',
        'goods_received' => 'Merce ricevuta con successo',
        'receipts' => 'Entrate merci recuperate con successo',
        'receipt' => 'Entrata merce recuperata con successo',
        'supplier_invoices' => 'Fatture fornitore recuperate con successo',
        'supplier_invoice' => 'Fattura fornitore recuperata con successo',
        'invoices_received' => 'Fatture fornitore ricevute',
        'marked_paid' => 'Fattura fornitore contrassegnata come pagata',
        'webhook_received' => 'Fattura passiva elaborata',
        'three_way_match_passed' => 'Abbinamento a tre vie completato con successo',
        'three_way_match_discrepant' => 'Rilevata discrepanza nell\'abbinamento a tre vie',
    ],
];
