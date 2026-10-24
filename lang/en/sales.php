<?php

declare(strict_types=1);

return [

    'order_already_invoiced' => 'This order has already been invoiced.',
    'order_cancelled' => 'A cancelled order cannot be invoiced.',
    'deposit_line' => 'Deposit for order :number (:percentage%)',
    'deposit_deduction' => 'Less: deposit invoice :number',
    'deposit_exceeds_order' => 'The total of deposit invoices cannot exceed the order total.',
    'delivery_note_already_invoiced' => 'This delivery note has already been invoiced.',

    'quote_not_convertible' => 'A rejected or expired quote cannot be converted.',
    'quote_already_invoiced' => 'This quote has already been invoiced.',
    'quote_already_signed' => 'This quote has already been signed.',
    'quote_not_signable' => 'A rejected or expired quote cannot be signed.',

    'quote_already_converted' => 'This quote has already been converted to an order.',
    'order_not_deliverable' => 'Only confirmed orders can be delivered.',
    'nothing_to_deliver' => 'Nothing left to deliver.',
    'proforma_only' => 'Only proforma invoices can be converted.',
    'proforma_already_converted' => 'This proforma has already been converted.',
    'payment_on_proforma' => 'Payments cannot be recorded on a proforma invoice.',
    'payment_exceeds_balance' => 'Payment exceeds the outstanding balance.',
    'import_line_matched' => 'This line is already matched.',

    'signature_invalid' => 'The signature must be a PNG image no larger than :max KB.',

    'reminder_not_payable' => 'Only unpaid, payable invoices can be reminded.',
    'reminder_no_email' => 'The contact has no email address.',
    'bank_import_note' => 'Bank import: :detail',

    'messages' => [
        'quotes_list' => 'Quotes retrieved successfully',
        'quote_created' => 'Quote created successfully',
        'quote_retrieved' => 'Quote retrieved successfully',
        'quote_updated' => 'Quote updated successfully',
        'quote_deleted' => 'Quote deleted successfully',
        'quote_to_order' => 'Quote converted to order',
        'orders_list' => 'Orders retrieved successfully',
        'order_created' => 'Order created successfully',
        'order_retrieved' => 'Order retrieved successfully',
        'order_updated' => 'Order updated successfully',
        'order_deleted' => 'Order deleted successfully',
        'order_delivery_note' => 'Delivery note created',
        'order_invoiced' => 'Invoice created from order',
        'delivery_notes_list' => 'Delivery notes retrieved successfully',
        'delivery_note_retrieved' => 'Delivery note retrieved successfully',
        'delivery_note_deleted' => 'Delivery note deleted successfully',
        'delivery_note_invoiced' => 'Invoice created from delivery note',
        'payments_list' => 'Payments retrieved successfully',
        'payment_recorded' => 'Payment recorded successfully',
        'proforma_converted' => 'Proforma converted to invoice',
        'reminders_list' => 'Reminders retrieved successfully',
        'reminder_queued' => 'Reminder queued successfully',
        'payment_imports_list' => 'Payment imports retrieved successfully',
        'payment_import_retrieved' => 'Payment import retrieved successfully',
        'payments_imported' => 'Payments imported successfully',
        'line_matched' => 'Line matched successfully',
        'quote_invoiced' => 'Invoice created from quote',
        'quote_delivery_note' => 'Delivery note created from quote',
        'quote_signed' => 'Quote signed successfully',
        'deposit_invoiced' => 'Deposit invoice created',
        'order_lines' => 'Order lines retrieved successfully',
    ],

];
