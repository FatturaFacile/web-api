<?php

declare(strict_types=1);

return [

    'greeting' => 'Gentile :name,',
    'closing' => 'Cordiali saluti',

    'payment_reminder' => [
        'subject' => [
            'level_1' => 'Promemoria di pagamento - fattura :number',
            'level_2' => 'Sollecito di pagamento - fattura :number',
            'level_3' => 'Ultimo sollecito di pagamento - fattura :number',
        ],
        'body' => [
            'level_1' => 'La fattura <strong>:number</strong>, scaduta il :due_date, risulta ancora aperta. Se ha già provveduto al pagamento, non consideri questo messaggio.',
            'level_2' => 'Nonostante il nostro precedente promemoria, la fattura <strong>:number</strong>, scaduta il :due_date, risulta ancora non pagata. La preghiamo di provvedere al più presto.',
            'level_3' => "Questo è l'ultimo sollecito per la fattura <strong>:number</strong>, scaduta il :due_date. In mancanza di pagamento potremo avviare il recupero del credito.",
        ],
        'amount_due' => 'Importo da pagare: <strong>:amount :currency</strong>',
    ],

    'document' => [
        'subject' => [
            'invoice' => 'Fattura :number',
            'quote' => 'Preventivo :number',
            'sales_order' => "Conferma d'ordine :number",
            'delivery_note' => 'Documento di trasporto :number',
            'work_order' => 'Rapporto di intervento :number',
        ],
        'intro' => [
            'invoice' => 'Le inviamo i dati della fattura :number.',
            'quote' => 'Le inviamo i dati del preventivo :number.',
            'sales_order' => "Le inviamo i dati dell'ordine :number.",
            'delivery_note' => 'Le inviamo i dati del documento di trasporto :number.',
            'work_order' => 'Le inviamo i dati del rapporto di intervento :number.',
            'document' => 'Le inviamo i dati del documento :number.',
        ],
        'number' => 'Numero',
        'date' => 'Data',
        'total' => 'Totale',
    ],

    'appointment_reminder' => [
        'subject' => 'Promemoria appuntamento :number del :date',
        'body' => 'Le ricordiamo l\'appuntamento <strong>:title</strong> (:number) previsto per il :date alle :time.',
        'address' => 'Indirizzo: :address',
    ],

    'portal_activity' => [
        'quote_approved' => [
            'subject' => 'Preventivo :number approvato dal cliente',
            'body' => ':customer ha approvato il preventivo :number dal portale clienti.',
        ],
        'quote_rejected' => [
            'subject' => 'Preventivo :number rifiutato dal cliente',
            'body' => ':customer ha rifiutato il preventivo :number dal portale clienti.',
            'reason' => 'Motivazione: :reason',
        ],
        'ticket_replied' => [
            'subject' => 'Nuova risposta del cliente al ticket :number',
            'body' => ':customer ha risposto al ticket :number dal portale clienti.',
        ],
    ],

];
