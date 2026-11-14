<?php

declare(strict_types=1);

return [
    'mail' => [
        'access_subject' => 'Your access to the supplier portal',
        'access_line' => 'Use the personal link below to open the supplier portal: registration, documents, orders, requests for quotation and invoices.',
    ],
    'notification' => [
        'expiring' => [
            'subject' => 'Document expiring: :supplier',
            'body' => 'The document ":document" of supplier :supplier expires on :date (in :days days).',
        ],
        'expired' => [
            'subject' => 'Document expired: :supplier',
            'body' => 'The document ":document" of supplier :supplier expired on :date.',
        ],
    ],
    'validation' => [
        'vat_number_invalid' => 'The VAT or UID number is not valid for the country given.',
    ],
];
