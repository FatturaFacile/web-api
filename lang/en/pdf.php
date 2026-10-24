<?php

declare(strict_types=1);

return [

    'messages' => [
        'unsupported_type' => 'PDF export is not available for this document type.',
    ],

    'titles' => [
        'invoice' => 'Invoice',
        'credit_note' => 'Credit note',
        'proforma' => 'Proforma invoice',
        'quote' => 'Quote',
        'sales_order' => 'Order confirmation',
        'delivery_note' => 'Delivery note',
        'work_order' => 'Work report',
    ],

    'number' => 'No.',
    'date' => 'Date',
    'due_date' => 'Due date',
    'valid_until' => 'Valid until',
    'customer' => 'Customer',
    'vat_number' => 'VAT no.',
    'fiscal_code' => 'Fiscal code',
    'uid_number' => 'UID',
    'page' => 'Page',

    'columns' => [
        'description' => 'Description',
        'type' => 'Type',
        'quantity' => 'Qty',
        'unit_price' => 'Unit price',
        'discount' => 'Disc. %',
        'tax' => 'VAT %',
        'total' => 'Total',
    ],

    'line_types' => [
        'labor' => 'Labor',
        'material' => 'Material',
        'travel' => 'Travel',
    ],

    'totals' => [
        'net' => 'Net total',
        'tax' => 'VAT',
        'rounding' => 'Rounding',
        'stamp_duty' => 'Stamp duty',
        'withholding_tax' => 'Withholding tax',
        'gross' => 'Total',
        'paid' => 'Paid',
        'balance' => 'Balance due',
    ],

    'notes' => 'Notes',

    'payment' => [
        'title' => 'Payment details',
        'method' => 'Payment method',
        'terms' => 'Payment terms',
        'bank' => 'Bank',
        'iban' => 'IBAN',
    ],

    'qr' => [
        'receipt' => 'Receipt',
        'payment_part' => 'Payment part',
        'account_payable_to' => 'Account / Payable to',
        'reference' => 'Reference',
        'additional_information' => 'Additional information',
        'payable_by' => 'Payable by',
        'payable_by_blank' => 'Payable by (name/address)',
        'currency' => 'Currency',
        'amount' => 'Amount',
        'acceptance_point' => 'Acceptance point',
        'unavailable' => 'The Swiss QR-bill payment slip could not be generated for this invoice. Please contact the issuer for the payment details.',
    ],

    'signature' => [
        'title' => 'Customer signature',
        'signed_by' => 'Signed by :name',
        'signed_on' => 'on :date',
        'line' => 'Date and signature',
    ],

    'quote' => [
        'terms' => 'Terms and conditions',
        'acceptance' => 'Acceptance of the quote',
    ],

    'delivery' => [
        'ship_to' => 'Ship to',
        'transport' => 'Transport details',
        'reason' => 'Transport reason',
        'appearance' => 'Goods appearance',
        'packages' => 'Packages',
        'weight' => 'Gross weight (kg)',
        'carrier' => 'Carrier',
        'courier' => 'Courier',
        'license_plate' => 'License plate',
        'departure' => 'Departure',
        'receipt' => 'Goods received in good condition',
    ],

    'work_order' => [
        'technician' => 'Technician',
        'address' => 'Intervention address',
        'started' => 'Started',
        'ended' => 'Ended',
        'labor_hours' => 'Labor hours',
        'acceptance' => 'Work accepted by the customer',
    ],

];
