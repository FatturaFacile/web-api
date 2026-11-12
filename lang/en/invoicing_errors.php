<?php

declare(strict_types=1);

return [
    'invoice' => [
        'cannot_be_approved' => 'Invoice in status [:status] cannot be approved.',
        'proforma_not_postable' => 'A proforma is not a fiscal document and cannot be posted.',
        'cannot_be_posted' => 'Invoice in status [:status] cannot be posted.',
        'line_vat_rate_required' => 'Every invoice line must state its VAT rate.',
    ],
];
