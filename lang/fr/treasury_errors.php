<?php

declare(strict_types=1);

return [
    'payment_export' => [
        'invoices_missing' => 'Certaines factures fournisseur n\'existent pas.',
        'currency_mismatch' => 'Les factures d\'un fichier de paiement doivent avoir la même devise.',
        'no_bank_account' => 'Aucun compte bancaire actif en :currency à débiter.',
        'nothing_to_pay' => 'La facture :number n\'a rien à payer.',
        'not_approved' => 'La facture :number n\'est pas approuvée pour le paiement.',
        'supplier_no_iban' => 'Le fournisseur de la facture :number n\'a pas d\'IBAN.',
    ],
    'reconciliation' => [
        'line_already_reconciled' => 'La ligne du relevé bancaire est déjà rapprochée.',
    ],
    'open_banking' => [
        'consent_inactive' => 'Ce consentement n\'est plus actif.',
    ],
];
