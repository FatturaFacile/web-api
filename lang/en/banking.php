<?php

declare(strict_types=1);

return [

    'messages' => [
        'imported' => 'Bank statement imported successfully',
        'listed' => 'Bank statements retrieved successfully',
        'retrieved' => 'Bank statement retrieved successfully',
        'reconciled' => 'Bank statement reconciliation completed',
        'matched' => 'Statement line matched successfully',
        'unmatched' => 'Statement line unmatched successfully',
        'categorized' => 'Statement line categorized successfully',
    ],

    'errors' => [
        'duplicate_statement' => 'This bank statement has already been imported.',
        'line_not_credit' => 'Only incoming (credit) lines can be matched with an invoice.',
        'line_already_reconciled' => 'The line is already reconciled.',
        'line_not_reconciled' => 'The line is not reconciled, so there is nothing to undo.',
        'invoice_not_payable' => 'The invoice cannot receive this payment: it is settled, not payable or in another currency.',
        'overpayment' => 'The amount exceeds the balance of the invoice.',
        'document_direction_mismatch' => 'Incoming lines can only be matched with customer invoices and outgoing lines with supplier invoices.',
        'category_not_for_credit' => 'Incoming lines can only be categorized as an internal transfer.',
        'supplier_payment_exceeds_balance' => 'The payment exceeds the balance of the supplier invoice.',
        'difference_not_accepted' => 'The amount differs from the balance by :difference. Choose how to handle the difference: write_off or partial.',
    ],

    'payment_notes' => [
        'payment' => 'Bank statement payment',
        'bank_fee' => 'Bank fee withheld at source',
        'adjustment' => 'Rounding adjustment',
    ],

    'problem_titles' => [
        'import' => 'Bank statement import',
    ],

    'parse_errors' => [
        'unsupported_format' => 'The file is not a supported bank statement (CBI, CAMT.053 or CAMT.054).',
        'invalid_xml' => 'The file is not a valid XML document.',
        'unsafe_xml' => 'XML documents with a DOCTYPE or entity declarations are not accepted.',
        'no_statement' => 'The document does not contain a statement or notification.',
        'invalid_line_length' => 'Line :line has :length characters instead of :expected.',
        'invalid_record' => 'Line :line contains an invalid or unexpected :record record.',
        'missing_header' => 'The statement has no valid header (account IBAN).',
        'missing_trailer' => 'The statement is incomplete: the EF trailer record is missing.',
        'record_count_mismatch' => 'The trailer declares :expected records but :actual were read.',
        'empty_file' => 'The file is empty.',
    ],

];
