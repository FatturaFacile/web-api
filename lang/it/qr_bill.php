<?php

declare(strict_types=1);

return [

    'additional_message' => 'Fattura :number',

    'errors' => [
        'invalid_qr_iban' => 'Il QR-IBAN non è valido: deve essere un IBAN svizzero o del Liechtenstein con identificativo istituto tra 30000 e 31999.',
        'missing_tenant' => "La fattura non ha un'azienda emittente.",
        'invalid_currency' => 'La QR-fattura svizzera supporta solo fatture in CHF ed EUR.',
        'invalid_amount' => "Non c'è alcun importo residuo da pagare.",
        'missing_creditor_iban' => "Nessun IBAN o QR-IBAN è configurato per l'azienda emittente.",
        'invalid_creditor_iban' => 'Il creditore deve avere un IBAN o QR-IBAN svizzero o del Liechtenstein valido.',
        'missing_creditor_address' => "L'indirizzo dell'azienda emittente è incompleto: sono obbligatori via, CAP e città.",
        'invalid_reference' => 'Il riferimento di pagamento non è un riferimento QR valido (27 cifre, modulo 10) né un riferimento del creditore (ISO 11649).',
        'reference_mismatch' => 'Il riferimento di pagamento non corrisponde al conto configurato: i riferimenti QR richiedono un QR-IBAN e i riferimenti del creditore un IBAN standard.',
        'validation_failed' => 'I dati della QR-fattura non rispettano la specifica SIX.',
    ],

    'parse_errors' => [
        'qr_not_found' => "Il file non contiene una QR-fattura svizzera leggibile. Carica un PDF o un'immagine in cui il codice QR è una bitmap.",
        'invalid_payload' => 'Il contenuto non è una QR-fattura svizzera (SPC).',
        'unsupported_file' => 'File non supportato o vuoto.',
    ],

    'messages' => [
        'parsed' => 'QR-fattura svizzera letta correttamente',
        'reference_checked' => 'Riferimento verificato',
        'retrieved' => 'QR-fattura svizzera generata con successo',
    ],

    'problem_title' => 'QR-fattura svizzera non disponibile',

];
