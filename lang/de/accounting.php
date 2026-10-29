<?php

declare(strict_types=1);

return [
    'messages' => [
        'ledger_retrieved' => 'Hauptbuch erfolgreich abgerufen',
        'vendor_aging_retrieved' => 'Kreditoren-Fälligkeitsanalyse erfolgreich abgerufen',
        'customer_aging_retrieved' => 'Debitoren-Fälligkeitsanalyse erfolgreich abgerufen',
        'journal_created' => 'Buchungssatz erfolgreich erstellt',
        'journal_posted' => 'Buchungssatz erfolgreich verbucht',
        'journal_reversed' => 'Buchungssatz erfolgreich storniert',
        'period_closed' => 'Buchungsperiode erfolgreich geschlossen',
        'financial_statement_generated' => 'Finanzbericht erfolgreich erstellt',
    ],

    'errors' => [
        'invalid_iban' => 'Der Wert :iban ist keine gültige IBAN.',
        'unbalanced_journal' => 'Der Buchungssatz ist nicht ausgeglichen (Soll ungleich Haben).',
        'period_locked' => 'Die Periode ist für Hauptbuchungen gesperrt.',
    ],

    'aging' => [
        'buckets' => [
            'current' => 'Laufend',
            'overdue' => 'Fällig',
            'days_0_30' => 'Fällig innerhalb von 30 Tagen',
            'days_31_60' => 'Fällig in 31 bis 60 Tagen',
            'days_61_90' => 'Fällig in 61 bis 90 Tagen',
            'days_over_90' => 'Fällig in über 90 Tagen',
        ],
    ],

    'accounts' => [
        'receivables' => 'Forderungen aus Lieferungen und Leistungen',
        'payables' => 'Verbindlichkeiten aus Lieferungen und Leistungen',
        'revenue' => 'Umsatzerlöse',
        'output_vat' => 'Umsatzsteuer (Verbindlichkeit)',
        'cost' => 'Aufwendungen für Waren und Dienstleistungen',
        'input_vat' => 'Vorsteuer (Forderung)',
        'bank' => 'Bankguthaben',
        'rounding' => 'Rundungsdifferenzen und Nachlässe',
        'tax_payment' => 'Steuerzahlungen',
        'payroll' => 'Löhne und Gehälter',
        'bank_expense' => 'Bankgebühren und Spesen',
        'generic_expense' => 'Sonstige betriebliche Aufwendungen',
        'transfer_clearing' => 'Geldtransit',
        'other' => 'Sachkonto',
    ],
];
