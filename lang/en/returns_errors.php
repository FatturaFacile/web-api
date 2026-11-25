<?php

declare(strict_types=1);

return [
    'quantity_positive' => 'The quantity must be greater than zero.',
    'transition' => 'The status cannot change from :from to :to.',
    'reason' => [
        'duplicate' => 'The reason code :code already exists.',
        'unknown' => 'The reason code :code does not exist or is not active.',
    ],
    'stock' => [
        'warehouse_required' => 'A warehouse is required to book the stock movement and the company has none.',
    ],
    'rma' => [
        'locked' => 'A return can only be changed while it is requested.',
        'customer_unknown' => 'The customer does not exist in the company.',
        'source_required' => 'An invoice or a sales order of the customer is required.',
        'invoice_not_returnable' => 'The invoice does not exist, is not an invoice of the customer or cannot be returned (credit notes and proformas).',
        'order_not_returnable' => 'The sales order does not exist or is not an order of the customer.',
        'window_expired' => 'The return window of :days days after the date of the document has passed.',
        'override_not_allowed' => 'Only an approver can accept a return after the return window.',
        'line_source_required' => 'Every line must name the invoice line or the order line it returns.',
        'line_not_of_source' => 'The line does not belong to the source document of the return.',
        'quantity_exceeds' => 'Only :available can still be returned of ":line".',
        'line_not_of_return' => 'A line does not belong to this return.',
        'received_exceeds' => 'The quantity received of ":line" is negative or exceeds the quantity authorised.',
        'nothing_received' => 'At least one line must be received.',
        'disposition_required' => 'The line ":line" needs a disposition.',
        'inspected_exceeds' => 'The quantity inspected of ":line" must be positive and not exceed the quantity received.',
        'supplier_required' => 'The line ":line" is returned to a supplier: choose an existing supplier.',
        'credit_note_reference_required' => 'The reference of the credit note draft is required.',
        'stock_note' => 'Customer return :number',
        'replacement_note' => 'Replacement for return :number',
    ],
    'warranty' => [
        'void_locked' => 'A void warranty cannot change.',
        'product_unknown' => 'The product does not exist in the company.',
        'period_invalid' => 'The warranty cannot end before it starts.',
        'identifier_required' => 'A serial number or a lot number is required.',
        'serial_taken' => 'The serial number :serial already has an active warranty for this product.',
    ],
    'claim' => [
        'warranty_unknown' => 'The warranty does not exist in the company.',
        'rma_mismatch' => 'The return does not exist or belongs to another customer than the warranty.',
        'not_valid' => 'The warranty did not cover the claim date (:reason): only an approver can accept it.',
        'status_expected' => 'The claim is :status: expected :expected.',
    ],
    'rtv' => [
        'locked' => 'Only a draft supplier return can change its supplier, lines and expected credit.',
        'final' => 'A closed or cancelled supplier return cannot change.',
        'no_lines' => 'A supplier return needs at least one line.',
        'supplier_unknown' => 'The supplier does not exist in the company.',
        'product_unknown' => 'The product does not exist in the company.',
        'receipt_line_mismatch' => 'The goods receipt line does not exist or is for another product.',
        'quantity_exceeds' => 'Only :available can still be returned of ":line".',
        'stock_note' => 'Return to supplier :number',
    ],
    'advance_replacement' => [
        'refurbished_not_found' => 'Refurbished B-Stock item not found in the company.',
        'not_overdue' => 'The advance replacement has not passed its SLA return due date yet.',
        'outbound_note' => 'Advance replacement (Cross-Shipping) :number',
        'penalty_invoice_line' => 'Charge for unreturned defective unit (:number — :product, due :due)',
        'penalty_invoice_note' => 'Automated penalty billing for unreturned advance replacement :number',
    ],
    'grading' => [
        'photos_required' => 'At least one photo of the received product is mandatory at the returns grading station.',
        'restock_note' => 'Grade A restock from return inspection :number',
        'rtv_note' => 'Receipt for supplier return from inspection :number',
        'bstock_note' => 'B-Stock / Refurbished receipt :sku',
    ],
    'credit_note' => [
        'original_invoice_required' => 'An originating sales invoice is required for fiscal control and Credit Note (TD04) issuance.',
        'already_issued' => 'A Credit Note has already been issued for this customer return.',
        'art26_over_one_year' => 'Warning Art. 26(2-3) DPR 633/72: :days days (over 1 year) have elapsed since original invoice :invoice dated :date. For commercial agreements/returned goods, VAT recovery is not permitted: issuing Credit Note TD04 for the taxable base only with nature code N2.2 and <DatiFattureCollegate> is proposed.',
        'art41_liva_notice' => 'Switzerland — Art. 41 LIVA: ensure documentary traceability of the consideration reduction referencing original invoice :invoice.',
        'default_note' => 'Credit note for return :rma on invoice :invoice',
    ],
];
