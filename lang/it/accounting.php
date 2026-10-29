<?php

declare(strict_types=1);

return [
    'messages' => [
        'ledger_retrieved' => 'Libro mastro recuperato con successo',
        'vendor_aging_retrieved' => 'Scadenzario fornitori recuperato con successo',
        'customer_aging_retrieved' => 'Scadenzario clienti recuperato con successo',
        'journal_created' => 'Prima nota contabile creata con successo',
        'journal_posted' => 'Prima nota contabile registrata con successo',
        'journal_reversed' => 'Prima nota contabile stornata con successo',
        'period_closed' => 'Periodo contabile chiuso con successo',
        'financial_statement_generated' => 'Bilancio d\'esercizio generato con successo',
    ],

    'errors' => [
        'invalid_iban' => 'Il valore :iban non è un IBAN valido.',
        'unbalanced_journal' => 'La registrazione contabile non è in pareggio tra dare e avere.',
        'period_locked' => 'Il periodo contabile è bloccato per le registrazioni.',
    ],

    'aging' => [
        'buckets' => [
            'current' => 'Corrente',
            'overdue' => 'Scaduto',
            'days_0_30' => 'Scadenza entro 30 giorni',
            'days_31_60' => 'Scadenza da 31 a 60 giorni',
            'days_61_90' => 'Scadenza da 61 a 90 giorni',
            'days_over_90' => 'Scadenza oltre 90 giorni',
        ],
    ],

    'accounts' => [
        'receivables' => 'Crediti verso clienti',
        'payables' => 'Debiti verso fornitori',
        'revenue' => 'Ricavi delle vendite',
        'output_vat' => 'IVA su vendite (debito)',
        'cost' => 'Acquisti di merci e servizi',
        'input_vat' => 'IVA su acquisti (credito)',
        'bank' => 'Banca c/c',
        'rounding' => 'Arrotondamenti e abbuoni',
        'tax_payment' => 'Versamenti tributari (F24)',
        'payroll' => 'Dipendenti c/retribuzioni',
        'bank_expense' => 'Oneri e commissioni bancarie',
        'generic_expense' => 'Spese generali',
        'transfer_clearing' => 'Giroconti bancari',
        'other' => 'Conto generico',
    ],
];
