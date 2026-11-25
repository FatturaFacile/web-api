<?php

declare(strict_types=1);

return [
    'quantity_positive' => 'La quantità deve essere maggiore di zero.',
    'transition' => 'Lo stato non può passare da :from a :to.',
    'reason' => [
        'duplicate' => 'Il codice motivo :code esiste già.',
        'unknown' => 'Il codice motivo :code non esiste o non è attivo.',
    ],
    'stock' => [
        'warehouse_required' => 'Serve un magazzino per registrare il movimento di magazzino e l\'azienda non ne ha.',
    ],
    'rma' => [
        'locked' => 'Un reso si può modificare solo finché è richiesto.',
        'customer_unknown' => 'Il cliente non esiste nell\'azienda.',
        'source_required' => 'Serve una fattura o un ordine di vendita del cliente.',
        'invoice_not_returnable' => 'La fattura non esiste, non è una fattura del cliente o non può essere resa (note di credito e proforma).',
        'order_not_returnable' => 'L\'ordine di vendita non esiste o non è un ordine del cliente.',
        'window_expired' => 'Il periodo di reso di :days giorni dalla data del documento è scaduto.',
        'override_not_allowed' => 'Solo un approvatore può accettare un reso oltre il periodo di reso.',
        'line_source_required' => 'Ogni riga deve indicare la riga di fattura o di ordine che restituisce.',
        'line_not_of_source' => 'La riga non appartiene al documento di origine del reso.',
        'quantity_exceeds' => 'Di ":line" si possono ancora rendere solo :available.',
        'line_not_of_return' => 'Una riga non appartiene a questo reso.',
        'received_exceeds' => 'La quantità ricevuta di ":line" è negativa o supera la quantità autorizzata.',
        'nothing_received' => 'Almeno una riga deve essere ricevuta.',
        'disposition_required' => 'La riga ":line" richiede una destinazione.',
        'inspected_exceeds' => 'La quantità ispezionata di ":line" deve essere positiva e non superare la quantità ricevuta.',
        'supplier_required' => 'La riga ":line" va resa a un fornitore: scegli un fornitore esistente.',
        'credit_note_reference_required' => 'Il riferimento della bozza di nota di credito è obbligatorio.',
        'stock_note' => 'Reso cliente :number',
        'replacement_note' => 'Sostituzione per il reso :number',
    ],
    'warranty' => [
        'void_locked' => 'Una garanzia annullata non si può modificare.',
        'product_unknown' => 'Il prodotto non esiste nell\'azienda.',
        'period_invalid' => 'La garanzia non può finire prima di iniziare.',
        'identifier_required' => 'Serve un numero di serie o un numero di lotto.',
        'serial_taken' => 'Il numero di serie :serial ha già una garanzia attiva per questo prodotto.',
    ],
    'claim' => [
        'warranty_unknown' => 'La garanzia non esiste nell\'azienda.',
        'rma_mismatch' => 'Il reso non esiste o appartiene a un cliente diverso da quello della garanzia.',
        'not_valid' => 'La garanzia non copriva la data del reclamo (:reason): solo un approvatore può accettarlo.',
        'status_expected' => 'Il reclamo è :status: atteso :expected.',
    ],
    'rtv' => [
        'locked' => 'Solo un reso a fornitore in bozza può cambiare fornitore, righe e nota di credito attesa.',
        'final' => 'Un reso a fornitore chiuso o annullato non si può modificare.',
        'no_lines' => 'Un reso a fornitore richiede almeno una riga.',
        'supplier_unknown' => 'Il fornitore non esiste nell\'azienda.',
        'product_unknown' => 'Il prodotto non esiste nell\'azienda.',
        'receipt_line_mismatch' => 'La riga del carico merce non esiste o è di un altro prodotto.',
        'quantity_exceeds' => 'Di ":line" si possono ancora rendere solo :available.',
        'stock_note' => 'Reso a fornitore :number',
    ],
    'advance_replacement' => [
        'refurbished_not_found' => 'Articolo ricondizionato B-Stock non trovato nell\'azienda.',
        'not_overdue' => 'La sostituzione anticipata non ha ancora superato la data di scadenza SLA.',
        'outbound_note' => 'Sostituzione anticipata (Cross-Shipping) :number',
        'penalty_invoice_line' => 'Addebito mancato reso unità difettosa (:number — :product, scadenza :due)',
        'penalty_invoice_note' => 'Fatturazione automatica penale per mancato rientro reso sostituzione anticipata :number',
    ],
    'grading' => [
        'photos_required' => 'È obbligatorio allegare almeno una foto del prodotto ricevuto alla stazione di controllo resi.',
        'restock_note' => 'Rientro Grado A da perizia reso :number',
        'rtv_note' => 'Rientro per reso a fornitore da perizia :number',
        'bstock_note' => 'Carico B-Stock / Ricondizionato :sku',
    ],
    'credit_note' => [
        'original_invoice_required' => 'Per il controllo fiscale e l\'emissione della Nota di Credito (TD04) è richiesta la fattura di vendita originaria.',
        'already_issued' => 'Per questo reso è già stata emessa una Nota di Credito.',
        'art26_over_one_year' => 'Attenzione Art. 26, commi 2 e 3 DPR 633/72: sono trascorsi :days giorni (oltre 1 anno) dalla fattura originaria :invoice del :date. Per accordo commerciale / reso merce non è consentito il recupero dell\'IVA: si propone l\'emissione della Nota di Credito TD04 per il solo imponibile con codice natura N2.2 e <DatiFattureCollegate>.',
        'art41_liva_notice' => 'Svizzera — Art. 41 LIVA: assicurare la tracciabilità documentale della rettifica della controprestazione con riferimento alla fattura originaria :invoice.',
        'default_note' => 'Nota di credito per reso :rma su fattura :invoice',
    ],
];
