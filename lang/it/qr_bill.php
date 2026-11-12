<?php

declare(strict_types=1);

return [

    'additional_message' => 'Fattura :number',

    'errors' => [
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

    'messages' => [
        'retrieved' => 'QR-fattura svizzera generata con successo',
    ],

    'problem_title' => 'QR-fattura svizzera non disponibile',

];
