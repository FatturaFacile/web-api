<?php

declare(strict_types=1);

return [
    'payment_export' => [
        'invoices_missing' => 'Alcune fatture fornitore non esistono.',
        'currency_mismatch' => 'Le fatture di un file di pagamento devono avere la stessa valuta.',
        'no_bank_account' => 'Nessun conto bancario attivo in :currency da addebitare.',
        'nothing_to_pay' => 'La fattura :number non ha importi da pagare.',
        'not_approved' => 'La fattura :number non è approvata per il pagamento.',
        'supplier_no_iban' => 'Il fornitore della fattura :number non ha un IBAN.',
    ],
    'reconciliation' => [
        'line_already_reconciled' => 'La riga dell\'estratto conto è già riconciliata.',
    ],
    'open_banking' => [
        'consent_inactive' => 'Questo consenso non è più attivo.',
    ],
];
