<?php

declare(strict_types=1);

return [
    'invoice' => [
        'cannot_be_approved' => 'Una fattura nello stato [:status] non può essere approvata.',
        'proforma_not_postable' => 'Una proforma non è un documento fiscale e non può essere contabilizzata.',
        'cannot_be_posted' => 'Una fattura nello stato [:status] non può essere contabilizzata.',
        'line_vat_rate_required' => 'Ogni riga della fattura deve indicare l\'aliquota IVA.',
    ],
];
