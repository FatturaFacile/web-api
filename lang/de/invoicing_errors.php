<?php

declare(strict_types=1);

return [
    'invoice' => [
        'cannot_be_approved' => 'Eine Rechnung im Status [:status] kann nicht freigegeben werden.',
        'proforma_not_postable' => 'Eine Proforma-Rechnung ist kein steuerrelevantes Dokument und kann nicht gebucht werden.',
        'cannot_be_posted' => 'Eine Rechnung im Status [:status] kann nicht gebucht werden.',
        'line_vat_rate_required' => 'Jede Rechnungszeile muss ihren Mehrwertsteuersatz angeben.',
    ],
];
