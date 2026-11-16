<?php

declare(strict_types=1);

return [
    'payment_export' => [
        'invoices_missing' => 'Einige Lieferantenrechnungen existieren nicht.',
        'currency_mismatch' => 'Die Rechnungen einer Zahlungsdatei müssen dieselbe Währung haben.',
        'no_bank_account' => 'Kein aktives Bankkonto in :currency zur Belastung vorhanden.',
        'nothing_to_pay' => 'Die Rechnung :number hat keinen offenen Betrag.',
        'not_approved' => 'Die Rechnung :number ist nicht zur Zahlung freigegeben.',
        'supplier_no_iban' => 'Der Lieferant der Rechnung :number hat keine IBAN.',
    ],
    'reconciliation' => [
        'line_already_reconciled' => 'Die Kontoauszugszeile ist bereits abgestimmt.',
    ],
    'open_banking' => [
        'consent_inactive' => 'Diese Einwilligung ist nicht mehr aktiv.',
    ],
];
