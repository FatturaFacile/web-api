<?php

declare(strict_types=1);

return [
    'messages' => [
        'imported' => 'Relevé bancaire importé avec succès',
        'listed' => 'Relevés bancaires récupérés avec succès',
        'retrieved' => 'Relevé bancaire récupéré avec succès',
        'reconciled' => 'Rapprochement bancaire effectué avec succès',
        'matched' => 'Ligne de relevé rapprochée avec succès',
        'unmatched' => 'Rapprochement de ligne annulé avec succès',
        'categorized' => 'Ligne de relevé catégorisée avec succès',
        'payment_batch_created' => 'Lot de paiement créé avec succès',
        'payment_batch_executed' => 'Lot de paiement exécuté (XML ISO 20022 généré)',
        'collection_recorded' => 'Encaissement enregistré avec succès',
    ],

    'errors' => [
        'duplicate_statement' => 'Ce relevé bancaire a déjà été importé.',
        'line_not_credit' => 'Seules les lignes créditrices peuvent être rapprochées d\'une facture client.',
        'line_already_reconciled' => 'La ligne est déjà rapprochée.',
        'line_not_reconciled' => 'La ligne n\'est pas rapprochée.',
        'invoice_not_payable' => 'La facture ne peut pas recevoir ce paiement.',
        'overpayment' => 'Le montant dépasse le solde dû de la facture.',
        'document_direction_mismatch' => 'Les lignes créditrices concernent les factures clients, les débitrices les factures fournisseurs.',
        'category_not_for_credit' => 'Les entrées ne peuvent être catégorisées que comme virement interne.',
        'supplier_payment_exceeds_balance' => 'Le paiement dépasse le montant dû au fournisseur.',
        'difference_not_accepted' => 'Le montant diffère du solde de :difference.',
    ],

    'payment_notes' => [
        'payment' => 'Paiement par relevé bancaire',
        'bank_fee' => 'Frais bancaires prélevés à la source',
        'adjustment' => 'Écart d\'arrondi',
    ],

    'problem_titles' => [
        'import' => 'Importation de relevé bancaire',
    ],
];
