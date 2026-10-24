<?php

declare(strict_types=1);

return [

    'order_locked' => 'Only draft or sent purchase orders can be edited.',
    'order_not_confirmable' => 'Only draft purchase orders can be confirmed.',
    'order_not_receivable' => 'Goods can only be received against sent or partially received orders.',
    'receipt_exceeds_order' => 'The quantity for ":description" exceeds the open quantity.',
    'nothing_to_receive' => 'There is nothing left to receive.',

    'invalid_fatturapa_title' => 'Invalid FatturaPA file',
    'unknown_recipient_title' => 'Unknown recipient',
    'invalid_fatturapa' => 'The file is not a valid FatturaPA document.',
    'recipient_mismatch' => 'The invoice is addressed to a different VAT number.',
    'unknown_recipient' => 'No tenant matches the invoice recipient.',
    'credit_note_not_payable' => 'Credit notes cannot be paid.',
    'already_paid' => 'This supplier invoice is already paid.',

    'messages' => [
        'order_created' => 'Purchase order created successfully',
        'order_updated' => 'Purchase order updated successfully',
        'order_retrieved' => 'Purchase order retrieved successfully',
        'order_confirmed' => 'Purchase order confirmed',
        'goods_received' => 'Goods received successfully',
        'receipts' => 'Goods receipts retrieved successfully',
        'receipt' => 'Goods receipt retrieved successfully',
        'supplier_invoices' => 'Supplier invoices retrieved successfully',
        'supplier_invoice' => 'Supplier invoice retrieved successfully',
        'invoices_received' => 'Supplier invoices received',
        'marked_paid' => 'Supplier invoice marked as paid',
        'webhook_received' => 'Incoming invoice processed',
    ],

];
