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
        'ledger_account_not_configured' => 'Configurez le compte :setting dans les préférences bancaires (banking.ledger_defaults) avant de comptabiliser ce mouvement.',
        'ledger_account_unknown' => 'Le compte :code n\'est pas dans le plan comptable.',
        'bank_account_not_linked' => 'Le compte bancaire :iban n\'est lié à aucun compte du plan comptable.',
        'posting_period_unavailable' => 'Le mouvement ne peut pas être comptabilisé : :reason',
    ],

    'payment_notes' => [
        'payment' => 'Paiement par relevé bancaire',
        'bank_fee' => 'Frais bancaires prélevés à la source',
        'adjustment' => 'Écart d\'arrondi',
    ],

    'problem_titles' => [
        'import' => 'Importation de relevé bancaire',
    ],

    'parse_errors' => [
        'unsupported_format' => 'Le fichier n\'est pas un relevé bancaire pris en charge (CBI, CAMT.053 ou CAMT.054).',
        'invalid_xml' => 'Le fichier n\'est pas un document XML valide.',
        'unsafe_xml' => 'Les documents XML contenant un DOCTYPE ou des déclarations d\'entités ne sont pas acceptés.',
        'no_statement' => 'Le document ne contient ni relevé ni notification.',
        'invalid_line_length' => 'La ligne :line comporte :length caractères au lieu de :expected.',
        'invalid_record' => 'La ligne :line contient un enregistrement :record invalide ou inattendu.',
        'missing_header' => 'Le relevé n\'a pas d\'en-tête valide (IBAN du compte).',
        'missing_trailer' => 'Le relevé est incomplet : l\'enregistrement de fin EF est manquant.',
        'record_count_mismatch' => 'L\'enregistrement de fin déclare :expected enregistrements mais :actual ont été lus.',
        'empty_file' => 'Le fichier est vide.',
    ],

];
