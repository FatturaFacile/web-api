<?php

declare(strict_types=1);

return [
    'errors' => [
        'forfettario_incompatible' => 'Ritenuta d\'acconto, split payment e inversione contabile non sono consentiti nel regime forfettario (RF19).',
        'enasarco_rate_not_configured' => 'Nessuna aliquota ENASARCO configurata per l\'anno :year.',
        'split_payment_incompatible' => 'Lo split payment non può essere combinato con l\'inversione contabile.',
        'split_payment_requires_vat' => 'Lo split payment richiede almeno una riga con IVA.',
        'invalid_natura' => 'Il codice natura :natura non è valido.',
        'amount_out_of_range' => 'Un importo è troppo grande per essere calcolato.',
        'invalid_quarter' => 'Il trimestre deve essere compreso tra 1 e 4.',
    ],

    'warnings' => [
        'zero_rate_without_natura' => 'La riga :line ha un\'aliquota IVA dello 0% senza codice natura.',
        'split_payment_customer' => 'Lo split payment è solitamente riservato alle pubbliche amministrazioni e alle società quotate; verificare il cliente.',
    ],

    'messages' => [
        'preview' => 'Anteprima fiscale calcolata con successo',
        'stamp_duty_report' => 'Prospetto imposta di bollo recuperato con successo',
        'withholding_report' => 'Prospetto ritenute d\'acconto recuperato con successo',
        'enasarco_status' => 'Stato ENASARCO recuperato con successo',
    ],
];
