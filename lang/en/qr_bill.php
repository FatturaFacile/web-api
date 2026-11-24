<?php

declare(strict_types=1);

return [

    'additional_message' => 'Invoice :number',

    'errors' => [
        'invalid_qr_iban' => 'The QR-IBAN is not valid: it must be a Swiss or Liechtenstein IBAN with an institution identifier between 30000 and 31999.',
        'missing_tenant' => 'The invoice has no issuing company.',
        'invalid_currency' => 'The Swiss QR-bill only supports CHF and EUR invoices.',
        'invalid_amount' => 'There is no outstanding amount to pay.',
        'missing_creditor_iban' => 'No IBAN or QR-IBAN is configured for the issuing company.',
        'invalid_creditor_iban' => 'The creditor needs a valid Swiss or Liechtenstein IBAN or QR-IBAN.',
        'missing_creditor_address' => 'The issuing company address is incomplete: street, postal code and city are required.',
        'invalid_reference' => 'The payment reference is not a valid QR reference (27 digits, modulo 10) or creditor reference (ISO 11649).',
        'reference_mismatch' => 'The payment reference does not match the configured account: QR references need a QR-IBAN and creditor references need a standard IBAN.',
        'validation_failed' => 'The QR-bill data does not meet the SIX specification.',
    ],

    'parse_errors' => [
        'qr_not_found' => 'The file does not contain a readable Swiss QR-bill. Upload a PDF or image where the QR code is a bitmap.',
        'invalid_payload' => 'The payload is not a Swiss QR-bill (SPC).',
        'unsupported_file' => 'Unsupported or empty file.',
    ],

    'messages' => [
        'parsed' => 'Swiss QR-bill parsed successfully',
        'reference_checked' => 'Reference checked',
        'retrieved' => 'Swiss QR-bill generated successfully',
    ],

    'problem_title' => 'Swiss QR-bill unavailable',

];
