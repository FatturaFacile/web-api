<?php

declare(strict_types=1);

return [
    'invoice' => [
        'cannot_be_approved' => 'Une facture au statut [:status] ne peut pas être approuvée.',
        'proforma_not_postable' => 'Une facture pro forma n\'est pas un document fiscal et ne peut pas être comptabilisée.',
        'cannot_be_posted' => 'Une facture au statut [:status] ne peut pas être comptabilisée.',
        'line_vat_rate_required' => 'Chaque ligne de facture doit indiquer son taux de TVA.',
    ],
];
