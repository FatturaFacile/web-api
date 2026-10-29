<?php

declare(strict_types=1);

return [
    'messages' => [
        'ledger_retrieved' => 'Grand livre récupéré avec succès',
        'vendor_aging_retrieved' => 'Balance âgée fournisseurs récupérée avec succès',
        'customer_aging_retrieved' => 'Balance âgée clients récupérée avec succès',
        'journal_created' => 'Écriture comptable créée avec succès',
        'journal_posted' => 'Écriture comptable validée avec succès',
        'journal_reversed' => 'Écriture comptable extournée avec succès',
        'period_closed' => 'Période comptable clôturée avec succès',
        'financial_statement_generated' => 'États financiers générés avec succès',
    ],

    'errors' => [
        'invalid_iban' => 'La valeur :iban n\'est pas un IBAN valide.',
        'unbalanced_journal' => 'L\'écriture comptable n\'est pas équilibrée (Débit != Crédit).',
        'period_locked' => 'La période comptable est verrouillée pour les écritures.',
    ],

    'aging' => [
        'buckets' => [
            'current' => 'Courant',
            'overdue' => 'Échu',
            'days_0_30' => 'Échéance sous 30 jours',
            'days_31_60' => 'Échéance de 31 à 60 jours',
            'days_61_90' => 'Échéance de 61 à 90 jours',
            'days_over_90' => 'Échéance à plus de 90 jours',
        ],
    ],

    'accounts' => [
        'receivables' => 'Créances clients',
        'payables' => 'Dettes fournisseurs',
        'revenue' => 'Chiffre d\'affaires',
        'output_vat' => 'TVA collectée (dette)',
        'cost' => 'Achats de biens et prestations',
        'input_vat' => 'TVA déductible (créance)',
        'bank' => 'Banque',
        'rounding' => 'Arrondis et écarts de règlement',
        'tax_payment' => 'Règlements d\'impôts et taxes',
        'payroll' => 'Rémunérations du personnel',
        'bank_expense' => 'Frais et commissions bancaires',
        'generic_expense' => 'Charges générales',
        'transfer_clearing' => 'Virements internes',
        'other' => 'Compte général',
    ],
];
