<?php

declare(strict_types=1);

return [

    'locked' => 'Un rapporto di intervento firmato o fatturato non può più essere modificato.',
    'already_signed' => 'Questo rapporto di intervento è già stato firmato.',
    'not_signable' => 'Solo i rapporti di intervento in bozza o completati possono essere firmati.',
    'not_invoiceable' => 'Solo i rapporti di intervento completati o firmati possono essere fatturati.',
    'no_lines' => 'Un rapporto di intervento senza righe non può essere fatturato.',
    'not_completable' => 'Solo i rapporti di intervento in bozza possono essere completati.',
    'internal_not_invoiceable' => 'Un ordine di lavoro interno non ha un cliente e non può essere fatturato.',
    'visit_requires_technician' => "Una visita richiede un tecnico: indicane uno o assegna prima l'ordine di lavoro.",
    'part_unknown_product' => "Nessun prodotto dell'azienda ha questo codice articolo.",
    'part_not_open' => 'I ricambi possono essere consumati solo su un ordine di lavoro pianificato, assegnato o in corso.',
    'part_quantity_positive' => 'La quantità deve essere maggiore di zero.',
    'billing_not_completed' => 'Solo un ordine di lavoro completato può essere fatturato.',
    'billing_not_billable' => 'Questo ordine di lavoro non è fatturabile.',
    'billing_already_invoiced' => 'Questo ordine di lavoro è già stato fatturato.',
    'billing_nothing_to_invoice' => "Non c'è nulla da fatturare su questo ordine di lavoro.",

    'messages' => [
        'created' => 'Rapporto di intervento creato con successo',
        'updated' => 'Rapporto di intervento aggiornato con successo',
        'retrieved' => 'Rapporto di intervento recuperato con successo',
        'completed' => 'Rapporto di intervento completato',
        'signed' => 'Rapporto di intervento firmato con successo',
        'invoiced' => 'Fattura creata dal rapporto di intervento',
    ],

];
