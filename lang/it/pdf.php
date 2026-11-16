<?php

declare(strict_types=1);

return [

    'messages' => [
        'unsupported_type' => "L'esportazione PDF non è disponibile per questo tipo di documento.",
    ],

    'titles' => [
        'invoice' => 'Fattura',
        'credit_note' => 'Nota di credito',
        'proforma' => 'Fattura proforma',
        'quote' => 'Preventivo',
        'sales_order' => "Conferma d'ordine",
        'delivery_note' => 'Documento di trasporto',
        'work_order' => 'Rapporto di intervento',
    ],

    'number' => 'N.',
    'date' => 'Data',
    'due_date' => 'Scadenza',
    'valid_until' => 'Valido fino al',
    'customer' => 'Cliente',
    'vat_number' => 'P. IVA',
    'fiscal_code' => 'Codice fiscale',
    'uid_number' => 'UID',
    'page' => 'Pagina',

    'columns' => [
        'description' => 'Descrizione',
        'type' => 'Tipo',
        'quantity' => 'Q.tà',
        'unit_price' => 'Prezzo unitario',
        'discount' => 'Sc. %',
        'tax' => 'IVA %',
        'total' => 'Totale',
    ],

    'line_types' => [
        'labor' => 'Manodopera',
        'material' => 'Materiale',
        'travel' => 'Trasferta',
    ],

    'totals' => [
        'net' => 'Imponibile',
        'tax' => 'IVA',
        'fund' => 'Contributo cassa previdenziale',
        'enasarco' => 'Contributo Enasarco',
        'split_payment' => 'IVA versata dal cliente (scissione dei pagamenti)',
        'due' => 'Netto a pagare',
        'rounding' => 'Arrotondamento',
        'stamp_duty' => 'Imposta di bollo',
        'withholding_tax' => "Ritenuta d'acconto",
        'gross' => 'Totale',
        'paid' => 'Pagato',
        'balance' => 'Saldo da pagare',
    ],

    'notes' => 'Note',

    // The wording the law asks for. These two strings also go in the XML, which is always in Italian.
    'legal' => [
        'forfettario' => "Operazione effettuata ai sensi dell'articolo 1, commi da 54 a 89, della Legge n. 190/2014.",
        'declaration_of_intent' => "Operazione non imponibile ai sensi dell'art. 8, c. 1, lett. c), D.P.R. 633/72. Vostra dichiarazione d'intento n. :protocol del :date.",
    ],

    'payment' => [
        'title' => 'Dati di pagamento',
        'method' => 'Modalità di pagamento',
        'terms' => 'Condizioni di pagamento',
        'bank' => 'Banca',
        'iban' => 'IBAN',
    ],

    'qr' => [
        'receipt' => 'Ricevuta',
        'payment_part' => 'Sezione pagamento',
        'account_payable_to' => 'Conto / Pagabile a',
        'reference' => 'Riferimento',
        'additional_information' => 'Informazioni aggiuntive',
        'payable_by' => 'Pagabile da',
        'payable_by_blank' => 'Pagabile da (nome/indirizzo)',
        'currency' => 'Valuta',
        'amount' => 'Importo',
        'acceptance_point' => 'Punto di accettazione',
        'unavailable' => "Non è stato possibile generare la polizza di versamento QR svizzera per questa fattura. Contatta l'emittente per i dati di pagamento.",
    ],

    'signature' => [
        'title' => 'Firma del cliente',
        'signed_by' => 'Firmato da :name',
        'signed_on' => 'il :date',
        'line' => 'Data e firma',
    ],

    'quote' => [
        'terms' => 'Termini e condizioni',
        'acceptance' => 'Accettazione del preventivo',
    ],

    'delivery' => [
        'ship_to' => 'Luogo di destinazione',
        'transport' => 'Dati di trasporto',
        'reason' => 'Causale del trasporto',
        'appearance' => 'Aspetto dei beni',
        'packages' => 'Colli',
        'weight' => 'Peso lordo (kg)',
        'carrier' => 'Vettore',
        'courier' => 'Corriere',
        'license_plate' => 'Targa',
        'departure' => 'Partenza',
        'receipt' => 'Merce ricevuta in buono stato',
    ],

    'work_order' => [
        'technician' => 'Tecnico',
        'address' => 'Luogo di intervento',
        'started' => 'Inizio',
        'ended' => 'Fine',
        'labor_hours' => 'Ore di lavoro',
        'acceptance' => 'Lavoro accettato dal cliente',
    ],

];
